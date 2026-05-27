<?php
// index.php
// A single-file PHP + HTML + JavaScript fog-of-war image revealer.

session_start();

// Change this password before putting the file on a public server.
$editPassword = "password";
$loginError = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "login") {
    $submittedPassword = $_POST["password"] ?? "";

    if (hash_equals($editPassword, $submittedPassword)) {
        $_SESSION["fog_edit_authenticated"] = true;
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $loginError = "Incorrect edit password.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$canEdit = !empty($_SESSION["fog_edit_authenticated"]);

$uploadDir = __DIR__ . "/uploads";
$uploadUrl = "uploads";

function getSelectableMaps($uploadDir) {
    if (!is_dir($uploadDir)) {
        return [];
    }

    $files = array_merge(
        glob($uploadDir . "/map_*.png") ?: [],
        glob($uploadDir . "/revealed_map_*.png") ?: []
    );

    $files = array_values(array_unique($files));

    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return array_map("basename", $files);
}

function isAllowedMapFile($filename, $uploadDir) {
    if (!is_string($filename)) {
        return false;
    }

    $isUploadedMap = preg_match("/^map_[a-f0-9]{16}\.png$/", $filename);
    $isRevealedMap = preg_match("/^revealed_map_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(?:_\d+)?\.png$/", $filename);

    if (!$isUploadedMap && !$isRevealedMap) {
        return false;
    }

    $path = realpath($uploadDir . "/" . $filename);
    $dir = realpath($uploadDir);

    return $path !== false && $dir !== false && str_starts_with($path, $dir . DIRECTORY_SEPARATOR) && is_file($path);
}

function sendJsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: application/json");
    echo json_encode($payload);
    exit;
}

function saveRenderedMapImage($dataUrl, $uploadDir) {
    if (!is_string($dataUrl) || !preg_match('/^data:image\/png;base64,/', $dataUrl)) {
        return [false, "Invalid PNG image data.", null];
    }

    $base64 = substr($dataUrl, strpos($dataUrl, ",") + 1);
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        return [false, "Could not decode PNG image data.", null];
    }

    if (strlen($binary) > 50 * 1024 * 1024) {
        return [false, "The rendered image is too large to save.", null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_buffer($finfo, $binary);
    finfo_close($finfo);

    if ($mime !== "image/png") {
        return [false, "Rendered image was not a valid PNG.", null];
    }

    $timestamp = date("Y-m-d_H-i-s");
    $filename = "revealed_map_" . $timestamp . ".png";
    $target = $uploadDir . "/" . $filename;

    $counter = 1;
    while (file_exists($target)) {
        $filename = "revealed_map_" . $timestamp . "_" . $counter . ".png";
        $target = $uploadDir . "/" . $filename;
        $counter++;
    }

    if (file_put_contents($target, $binary, LOCK_EX) === false) {
        return [false, "Could not write the rendered image to the server.", null];
    }

    return [true, null, $filename];
}

function getStateFilenameForRenderedFile($filename) {
    if (!preg_match("/^revealed_map_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(?:_\d+)?\.png$/", $filename)) {
        return null;
    }

    return preg_replace("/\.png$/", ".json", $filename);
}

function readEditableState($filename, $uploadDir) {
    $stateFilename = getStateFilenameForRenderedFile($filename);

    if ($stateFilename === null) {
        return null;
    }

    $statePath = $uploadDir . "/" . $stateFilename;

    if (!is_file($statePath)) {
        return null;
    }

    $json = file_get_contents($statePath);
    $state = json_decode($json, true);

    if (!is_array($state)) {
        return null;
    }

    return $state;
}

function saveEditableMapState($renderedDataUrl, $fogDataUrl, $stampsJson, $baseFile, $fogOpacity, $uploadDir) {
    [$savedOk, $saveError, $savedFilename] = saveRenderedMapImage($renderedDataUrl, $uploadDir);

    if (!$savedOk) {
        return [false, $saveError, null];
    }

    if (!isAllowedMapFile($baseFile, $uploadDir)) {
        unlink($uploadDir . "/" . $savedFilename);
        return [false, "The base map file was not valid.", null];
    }

    if (!is_string($fogDataUrl) || !preg_match('/^data:image\/png;base64,/', $fogDataUrl)) {
        unlink($uploadDir . "/" . $savedFilename);
        return [false, "Invalid fog image data.", null];
    }

    $fogBase64 = substr($fogDataUrl, strpos($fogDataUrl, ",") + 1);
    $fogBinary = base64_decode($fogBase64, true);

    if ($fogBinary === false || strlen($fogBinary) > 50 * 1024 * 1024) {
        unlink($uploadDir . "/" . $savedFilename);
        return [false, "Could not decode the fog image data.", null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $fogMime = finfo_buffer($finfo, $fogBinary);
    finfo_close($finfo);

    if ($fogMime !== "image/png") {
        unlink($uploadDir . "/" . $savedFilename);
        return [false, "Fog data was not a valid PNG.", null];
    }

    $stamps = json_decode($stampsJson, true);

    if (!is_array($stamps)) {
        $stamps = [];
    }

    $safeStamps = [];
    $allowedColors = ["red", "green", "blue", "white", "black"];
    $allowedSymbols = ["↑", "↓", "←", "→", "<-->", "↕", "TRAP", "SAFE?", "X", "?"];

    foreach ($stamps as $stamp) {
        if (!is_array($stamp)) {
            continue;
        }

        $text = trim((string)($stamp["text"] ?? ""));

        if ($text === "" && isset($stamp["type"])) {
            $legacyType = (string)$stamp["type"];
            $legacyMap = [
                "D" => "D",
                "T" => "T",
                "N" => "N",
                "S" => "S",
                "E" => "E",
                "W" => "W"
            ];
            $text = $legacyMap[$legacyType] ?? "";
        }

        if ($text === "") {
            continue;
        }

        $text = mb_substr($text, 0, 8);

        if (!in_array($text, $allowedSymbols, true)) {
            continue;
        }

        $color = strtolower((string)($stamp["color"] ?? "white"));

        if (!in_array($color, $allowedColors, true)) {
            $color = "white";
        }

        $safeStamps[] = [
            "x" => (float)($stamp["x"] ?? 0),
            "y" => (float)($stamp["y"] ?? 0),
            "text" => $text,
            "color" => $color,
            "size" => max(12, min(160, (float)($stamp["size"] ?? 27)))
        ];
    }

    $stateFilename = getStateFilenameForRenderedFile($savedFilename);
    $statePath = $uploadDir . "/" . $stateFilename;

    $state = [
        "version" => 1,
        "base_file" => $baseFile,
        "fog_data_url" => "data:image/png;base64," . base64_encode($fogBinary),
        "stamps" => $safeStamps,
        "fog_opacity" => max(20, min(100, (int)$fogOpacity))
    ];

    if (file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        unlink($uploadDir . "/" . $savedFilename);
        return [false, "Could not write the editable state to the server.", null];
    }

    return [true, null, $savedFilename];
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$writeActions = ["save_revealed_image", "delete_file", "upload"];
$requestedAction = $_POST["action"] ?? "";

if (!$canEdit && $_SERVER["REQUEST_METHOD"] === "POST" && in_array($requestedAction, $writeActions, true)) {
    if ($requestedAction === "save_revealed_image") {
        sendJsonResponse(["ok" => false, "error" => "Edit password required."], 403);
    }

    $error = "Edit password required.";
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_revealed_image") {
    [$savedOk, $saveError, $savedFilename] = saveEditableMapState(
        $_POST["imageData"] ?? "",
        $_POST["fogData"] ?? "",
        $_POST["stamps"] ?? "[]",
        $_POST["baseFile"] ?? "",
        $_POST["fogOpacity"] ?? 95,
        $uploadDir
    );

    if (!$savedOk) {
        sendJsonResponse(["ok" => false, "error" => $saveError], 400);
    }

    sendJsonResponse([
        "ok" => true,
        "filename" => $savedFilename,
        "url" => $uploadUrl . "/" . rawurlencode($savedFilename)
    ]);
}

$imagePath = null;
$selectedFile = null;
$baseFile = null;
$editableState = null;
$error = null;

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_file") {
    $fileToDelete = $_POST["file"] ?? "";

    if (isAllowedMapFile($fileToDelete, $uploadDir)) {
        unlink($uploadDir . "/" . $fileToDelete);

        $stateFilename = getStateFilenameForRenderedFile($fileToDelete);
        if ($stateFilename !== null && is_file($uploadDir . "/" . $stateFilename)) {
            unlink($uploadDir . "/" . $stateFilename);
        }

        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $error = "Could not delete the selected file.";
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload" && isset($_FILES["map"])) {
    $file = $_FILES["map"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        $error = "Upload failed.";
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file["tmp_name"]);
        finfo_close($finfo);

        if ($mime !== "image/png") {
            $error = "Please upload a PNG file.";
        } else {
            $filename = "map_" . bin2hex(random_bytes(8)) . ".png";
            $target = $uploadDir . "/" . $filename;

            if (move_uploaded_file($file["tmp_name"], $target)) {
                header("Location: " . $_SERVER["PHP_SELF"] . "?file=" . rawurlencode($filename));
                exit;
            } else {
                $error = "Could not save uploaded file.";
            }
        }
    }
}

$selectableMaps = getSelectableMaps($uploadDir);

if (true) {
    $requestedFile = $_GET["file"] ?? "";

    if (isAllowedMapFile($requestedFile, $uploadDir)) {
        $selectedFile = $requestedFile;
    } elseif (!empty($selectableMaps)) {
        $selectedFile = $selectableMaps[0];
    }

    if ($selectedFile !== null) {
        $editableState = readEditableState($selectedFile, $uploadDir);

        if (is_array($editableState) && isset($editableState["base_file"]) && isAllowedMapFile($editableState["base_file"], $uploadDir)) {
            $baseFile = $editableState["base_file"];
        } else {
            $editableState = null;
            $baseFile = $selectedFile;
        }

        $imagePath = $uploadUrl . "/" . rawurlencode($baseFile);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Map</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --bg: #111;
            --panel: #1c1c1c;
            --text: #f2f2f2;
            --muted: #b8b8b8;
            --border: #333;
            --accent: #e8e8e8;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
            overflow: hidden;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            width: 100vw;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            padding: 0;
        }

        .panel {
            flex: 0 0 auto;
            width: 100%;
            max-width: none;
            margin: 0;
            background: var(--panel);
            border: 0;
            border-bottom: 1px solid var(--border);
            border-radius: 0;
            padding: 6px 10px;
        }

        h1 {
            margin: 0;
            font-size: 18px;
        }

        p {
            margin: 2px 0;
            color: var(--muted);
            line-height: 1.25;
        }

        form {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 4px;
        }

        input[type="file"] {
            color: var(--text);
        }

        button,
        .button {
            appearance: none;
            border: 1px solid var(--accent);
            background: transparent;
            color: var(--text);
            padding: 5px 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
        }

        button:hover,
        .button:hover {
            background: #2a2a2a;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            margin-top: 4px;
        }

        .toolbar label {
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .toolbar input[type="range"] {
            width: 110px;
        }


        .panel-header {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
        }

        .logout-form,
        .edit-login-form {
            margin-top: 0;
            flex: 0 0 auto;
        }

        .edit-login-form input[type="password"] {
            width: 130px;
            padding: 5px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #0b0b0b;
            color: var(--text);
            font-size: 12px;
        }

        .login-page {
            justify-content: center;
            align-items: center;
            padding: 24px;
        }

        .login-panel {
            width: min(100%, 420px);
            margin: 0;
        }

        .login-panel form {
            display: block;
        }

        .login-panel input[type="password"] {
            width: 100%;
            margin: 12px 0;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #0b0b0b;
            color: var(--text);
            font-size: 16px;
        }

        .error {
            color: #ff8a8a;
        }

        .stage-wrap {
            flex: 1 1 auto;
            width: 100%;
            min-height: 0;
            max-width: none;
            margin: 0;
            overflow: auto;
            border: 0;
            border-radius: 0;
            background: #000;
            touch-action: none;
            -webkit-overflow-scrolling: touch;
        }

        .stage {
            position: relative;
            display: inline-block;
            line-height: 0;
            user-select: none;
            -webkit-user-select: none;
            touch-action: none;
        }

        .stage img {
            display: block;
            max-width: none;
            user-select: none;
            -webkit-user-drag: none;
            pointer-events: none;
        }

        canvas {
            position: absolute;
            left: 0;
            top: 0;
            touch-action: none;
        }

        #stampCanvas {
            cursor: crosshair;
        }

        .stage-wrap.pan-mode #stampCanvas {
            cursor: grab;
        }

        .stage-wrap.pan-mode #stampCanvas:active {
            cursor: grabbing;
        }

        .toolbar select {
            appearance: none;
            border: 1px solid var(--border);
            background: #111;
            color: var(--text);
            padding: 5px 24px 5px 8px;
            border-radius: 6px;
            font-size: 12px;
        }

        .mode-group {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }

        .mode-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 7px;
            color: var(--muted);
        }

        .mode-button input {
            margin: 0;
        }

        .empty {
            flex: 1 1 auto;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px dashed var(--border);
            border-radius: 0;
            padding: 30px;
            color: var(--muted);
            text-align: center;
        }


        .compass-overlay {
            position: fixed;
            right: 18px;
            bottom: 18px;
            width: clamp(54px, 6vw, 86px);
            height: auto;
            opacity: 0.68;
            pointer-events: none;
            z-index: 1000;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.9));
        }

        .compass-overlay text {
    	     fill: rgba(220, 40, 40, 0.95);
    	     stroke: rgba(0, 0, 0, 0.85);
	     stroke-width: 3px;
	     paint-order: stroke fill;
	     font-weight: 900;
	}
        .save-status {
            color: var(--muted);
            font-size: 12px;
        }

        .save-status a {
            color: var(--text);
        }
    </style>
</head>
<body>

<div class="page">
    <div class="panel">
        <div class="panel-header">
            <div>
                <h1>Map</h1>
                
            </div>
            <?php if ($canEdit): ?>
                <form class="logout-form" method="post">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit">Leave edit mode</button>
                </form>
            <?php else: ?>
                <form class="edit-login-form" method="post">
                    <input type="hidden" name="action" value="login">
                    <label for="password">Edit password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                    <button type="submit">Edit</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></p>
        <?php endif; ?>

        <div class="toolbar">
            <?php if ($canEdit): ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="map" accept="image/png" required>
                    <button type="submit">Upload</button>
                </form>
            <?php endif; ?>

            <?php if (!empty($selectableMaps)): ?>
                <form method="get">
                    <label>
                        Existing or saved
                        <select name="file" onchange="this.form.submit()">
                            <?php foreach ($selectableMaps as $mapFile): ?>
                                <option value="<?php echo htmlspecialchars($mapFile, ENT_QUOTES, "UTF-8"); ?>" <?php echo $mapFile === $selectedFile ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($mapFile, ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button type="submit">Open</button></noscript>
                </form>

                <?php if ($canEdit && $selectedFile): ?>
                    <form method="post" onsubmit="return confirm('Delete this map file?');">
                        <input type="hidden" name="action" value="delete_file">
                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($selectedFile, ENT_QUOTES, "UTF-8"); ?>">
                        <button type="submit">Delete file</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($imagePath && $canEdit): ?>
            <div class="toolbar">
                <label>
                    Brush size
                    <input id="brushSize" type="range" min="10" max="200" value="60">
                    <span id="brushSizeValue">60</span>px
                </label>

                <label>
                    Fog opacity
                    <input id="fogOpacity" type="range" min="20" max="100" value="<?php echo (int)($editableState["fog_opacity"] ?? 95); ?>">
                    <span id="fogOpacityValue"><?php echo (int)($editableState["fog_opacity"] ?? 95); ?></span>%
                </label>

                <span class="mode-group" aria-label="Tool mode">
                    <label class="mode-button">
                        <input type="radio" name="toolMode" value="pan" checked>
                        Pan
                    </label>
                    <label class="mode-button">
                        <input type="radio" name="toolMode" value="reveal">
                        Reveal
                    </label>
                    <label class="mode-button">
                        <input type="radio" name="toolMode" value="refog">
                        Restore fog
                    </label>
                    <label class="mode-button">
                        <input type="radio" name="toolMode" value="label">
                        Symbol
                    </label>
                    <label class="mode-button">
                        <input type="radio" name="toolMode" value="removeLabel">
                        Remove symbol
                    </label>
                </span>

                <label>
                    Symbol
                    <select id="labelText">
                        <option value="↑">↑</option>
                        <option value="↓">↓</option>
                        <option value="←">←</option>
                        <option value="→">→</option>
                        <option value="&lt;--&gt;">&lt;--&gt;</option>
                        <option value="↕">↕</option>
                        <option value="TRAP">TRAP</option>
                        <option value="SAFE?">SAFE?</option>
                        <option value="X">X</option>
                        <option value="?">?</option>
                    </select>
                </label>

                <label>
                    Color
                    <select id="labelColor">
                        <option value="red">Red</option>
                        <option value="green">Green</option>
                        <option value="blue">Blue</option>
                        <option value="white" selected>White</option>
                        <option value="black">Black</option>
                    </select>
                </label>

                <label>
                    Font size
                    <input id="stampSize" type="range" min="12" max="30" value="30">
                    <span id="stampSizeValue">30</span>px
                </label>

                <button type="button" id="undoStamp">Undo symbol</button>
                <button type="button" id="clearStamps">Clear symbols</button>
                <button type="button" id="resetFog">Reset fog</button>
                <button type="button" id="clearFog">Clear all fog</button>
                <button type="button" id="saveFog">Download revealed image</button>
                <button type="button" id="saveToServer">Save to server</button>
                <span id="saveStatus" class="save-status" aria-live="polite"></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($imagePath): ?>
        <div class="stage-wrap">
            <div class="stage" id="stage">
                <img id="mapImage" src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, "UTF-8"); ?>" alt="Uploaded map">
                <canvas id="fogCanvas"></canvas>
                <canvas id="stampCanvas"></canvas>
            </div>
        </div>
        <svg class="compass-overlay" viewBox="0 0 100 100" role="img" aria-label="Compass">
            <text x="50" y="18" text-anchor="middle" font-size="18">N</text>
            <text x="50" y="92" text-anchor="middle" font-size="15">S</text>
            <text x="14" y="56" text-anchor="middle" font-size="15">W</text>
            <text x="86" y="56" text-anchor="middle" font-size="15">E</text>
        </svg>
    <?php else: ?>
        <div class="empty">
            <?php if ($canEdit): ?>
                No PNG selected. Upload a PNG or choose an existing file.
            <?php else: ?>
                No PNG selected. Enter the edit password to upload a PNG.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($imagePath): ?>
<script>
(function () {
    const stageWrap = document.querySelector(".stage-wrap");
    const image = document.getElementById("mapImage");
    const canvas = document.getElementById("fogCanvas");
    const ctx = canvas.getContext("2d");
    const stampCanvas = document.getElementById("stampCanvas");
    const stampCtx = stampCanvas.getContext("2d");

    const brushSize = document.getElementById("brushSize");
    const brushSizeValue = document.getElementById("brushSizeValue");
    const fogOpacity = document.getElementById("fogOpacity");
    const fogOpacityValue = document.getElementById("fogOpacityValue");

    const resetFogButton = document.getElementById("resetFog");
    const clearFogButton = document.getElementById("clearFog");
    const saveFogButton = document.getElementById("saveFog");
    const saveToServerButton = document.getElementById("saveToServer");
    const saveStatus = document.getElementById("saveStatus");
    const labelText = document.getElementById("labelText");
    const labelColor = document.getElementById("labelColor");
    const stampSize = document.getElementById("stampSize");
    const stampSizeValue = document.getElementById("stampSizeValue");
    const undoStampButton = document.getElementById("undoStamp");
    const clearStampsButton = document.getElementById("clearStamps");
    const toolModeInputs = document.querySelectorAll('input[name="toolMode"]');
    const canEdit = <?php echo json_encode($canEdit); ?>;
    const initialFogOpacity = <?php echo json_encode((int)($editableState["fog_opacity"] ?? 95)); ?>;
    const baseFile = <?php echo json_encode($baseFile); ?>;
    const initialFogDataUrl = <?php echo json_encode($editableState["fog_data_url"] ?? null); ?>;
    const initialStamps = <?php echo json_encode($editableState["stamps"] ?? []); ?>;

    let drawing = false;
    let lastPoint = null;
    let fogAlpha = fogOpacity ? Number(fogOpacity.value) / 100 : Number(initialFogOpacity) / 100;
    let stamps = normalizeLabels(Array.isArray(initialStamps) ? initialStamps : []);
    let panning = false;
    let panStart = null;

    function normalizeLabelText(value) {
        return String(value || "").replace(/\s+/g, " ").trim().slice(0, 8);
    }

    function normalizeLabels(items) {
        const allowedColors = ["red", "green", "blue", "white", "black"];
        const legacyTextMap = { D: "D", T: "T", N: "N", S: "S", E: "E", W: "W" };

        return items
            .map(function (item) {
                if (!item || typeof item !== "object") {
                    return null;
                }

                let text = normalizeLabelText(item.text);

                if (!text && item.type) {
                    text = legacyTextMap[item.type] || "";
                }

                if (!text) {
                    return null;
                }

                const color = allowedColors.includes(String(item.color || "").toLowerCase())
                    ? String(item.color).toLowerCase()
                    : "white";

                return {
                    x: Number(item.x) || 0,
                    y: Number(item.y) || 0,
                    text: text,
                    color: color,
                    size: Math.max(12, Math.min(160, Number(item.size) || 27))
                };
            })
            .filter(Boolean);
    }

    function setupCanvas() {
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        canvas.style.width = image.naturalWidth + "px";
        canvas.style.height = image.naturalHeight + "px";

        stampCanvas.width = image.naturalWidth;
        stampCanvas.height = image.naturalHeight;
        stampCanvas.style.width = image.naturalWidth + "px";
        stampCanvas.style.height = image.naturalHeight + "px";

        image.width = image.naturalWidth;
        image.height = image.naturalHeight;

        if (initialFogDataUrl) {
            loadInitialFog(initialFogDataUrl);
        } else {
            resetFog();
        }

        redrawStamps();
    }

    function loadInitialFog(fogDataUrl) {
        const fogImage = new Image();

        fogImage.onload = function () {
            ctx.globalCompositeOperation = "source-over";
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(fogImage, 0, 0, canvas.width, canvas.height);
        };

        fogImage.onerror = function () {
            resetFog();
        };

        fogImage.src = fogDataUrl;
    }

    function resetFog() {
        ctx.globalCompositeOperation = "source-over";
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = "rgba(0, 0, 0, " + fogAlpha + ")";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    function clearFog() {
        ctx.globalCompositeOperation = "source-over";
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }

    function rescaleExistingFogOpacity(previousAlpha, nextAlpha) {
        const previousTarget = Math.max(1, Math.round(previousAlpha * 255));
        const nextTarget = Math.round(nextAlpha * 255);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;

        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] === 0) {
                continue;
            }

            const relativeOpacity = Math.min(1, data[i + 3] / previousTarget);

            data[i] = 0;
            data[i + 1] = 0;
            data[i + 2] = 0;
            data[i + 3] = Math.round(nextTarget * relativeOpacity);
        }

        ctx.putImageData(imageData, 0, 0);
    }

    function getToolMode() {
        const checked = document.querySelector('input[name="toolMode"]:checked');
        return checked ? checked.value : "pan";
    }

    function updatePointerBehavior() {
        const mode = getToolMode();

        stageWrap.classList.toggle("pan-mode", mode === "pan");
        stampCanvas.style.cursor = mode === "pan" ? "grab" : mode === "label" ? "copy" : mode === "removeLabel" ? "not-allowed" : "crosshair";
    }

    function startPanning(event) {
        event.preventDefault();
        stampCanvas.setPointerCapture(event.pointerId);

        panning = true;
        panStart = {
            x: event.clientX,
            y: event.clientY,
            scrollLeft: stageWrap.scrollLeft,
            scrollTop: stageWrap.scrollTop
        };
    }

    function pan(event) {
        if (!panning || !panStart) {
            return;
        }

        event.preventDefault();
        stageWrap.scrollLeft = panStart.scrollLeft - (event.clientX - panStart.x);
        stageWrap.scrollTop = panStart.scrollTop - (event.clientY - panStart.y);
    }

    function stopPanning(event) {
        if (!panning) {
            return;
        }

        event.preventDefault();
        panning = false;
        panStart = null;

        try {
            stampCanvas.releasePointerCapture(event.pointerId);
        } catch (e) {
            // Pointer capture may already be released.
        }
    }

    function getLabelStrokeColor(color) {
        return color === "black" ? "rgba(255, 255, 255, 0.95)" : "rgba(0, 0, 0, 0.9)";
    }

    function getLabelFillColor(color) {
        const colors = {
            red: "rgba(255, 80, 80, 0.98)",
            green: "rgba(90, 230, 90, 0.98)",
            blue: "rgba(90, 160, 255, 0.98)",
            white: "rgba(255, 255, 255, 0.98)",
            black: "rgba(0, 0, 0, 0.98)"
        };

        return colors[color] || colors.white;
    }

    function drawStamp(stamp) {
        const size = Number(stamp.size);
        const text = normalizeLabelText(stamp.text);

        if (!text) {
            return;
        }

        stampCtx.save();
        stampCtx.font = "700 " + size + "px Arial, Helvetica, sans-serif";
        stampCtx.textAlign = "center";
        stampCtx.textBaseline = "middle";
        stampCtx.lineJoin = "round";
        stampCtx.lineWidth = Math.max(3, size * 0.14);
        stampCtx.strokeStyle = getLabelStrokeColor(stamp.color);
        stampCtx.fillStyle = getLabelFillColor(stamp.color);
        stampCtx.strokeText(text, stamp.x, stamp.y);
        stampCtx.fillText(text, stamp.x, stamp.y);
        stampCtx.restore();
    }

    function redrawStamps() {
        stampCtx.clearRect(0, 0, stampCanvas.width, stampCanvas.height);

        for (const stamp of stamps) {
            drawStamp(stamp);
        }
    }

    function placeStamp(point) {
        const text = normalizeLabelText(labelText.value);

        if (!text) {
            if (saveStatus) {
                saveStatus.textContent = "Choose a symbol before placing it.";
            }
            return;
        }

        if (saveStatus) {
            saveStatus.textContent = "";
        }

        stamps.push({
            x: point.x,
            y: point.y,
            text: text,
            color: labelColor ? labelColor.value : "white",
            size: stampSize ? Number(stampSize.value) : 27
        });

        redrawStamps();
    }

    function undoStamp() {
        stamps.pop();
        redrawStamps();
    }

    function clearStamps() {
        stamps = [];
        redrawStamps();
    }

    function isPointInsideStamp(point, stamp) {
        const size = Number(stamp.size) || 27;
        const text = normalizeLabelText(stamp.text);

        stampCtx.save();
        stampCtx.font = "700 " + size + "px Arial, Helvetica, sans-serif";
        const metrics = stampCtx.measureText(text);
        stampCtx.restore();

        const left = stamp.x - Math.max(metrics.actualBoundingBoxLeft || metrics.width / 2, metrics.width / 2) - size * 0.2;
        const right = stamp.x + Math.max(metrics.actualBoundingBoxRight || metrics.width / 2, metrics.width / 2) + size * 0.2;
        const top = stamp.y - Math.max(metrics.actualBoundingBoxAscent || size * 0.55, size * 0.55) - size * 0.2;
        const bottom = stamp.y + Math.max(metrics.actualBoundingBoxDescent || size * 0.35, size * 0.35) + size * 0.2;

        return point.x >= left && point.x <= right && point.y >= top && point.y <= bottom;
    }

    function removeStampAt(point) {
        for (let i = stamps.length - 1; i >= 0; i--) {
            if (isPointInsideStamp(point, stamps[i])) {
                stamps.splice(i, 1);
                redrawStamps();
                if (saveStatus) {
                    saveStatus.textContent = "";
                }
                return true;
            }
        }

        return false;
    }

    function getCanvasPoint(event) {
        const rect = stampCanvas.getBoundingClientRect();

        return {
            x: (event.clientX - rect.left) * (canvas.width / rect.width),
            y: (event.clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function eraseAt(point) {
        const size = brushSize ? Number(brushSize.value) : 60;
        const halfSize = size / 2;

        ctx.save();
        ctx.globalCompositeOperation = "destination-out";
        ctx.fillStyle = "rgba(0, 0, 0, 1)";
        ctx.fillRect(point.x - halfSize, point.y - halfSize, size, size);
        ctx.restore();
    }

    function restoreFogAt(point) {
        const size = brushSize ? Number(brushSize.value) : 60;
        const halfSize = size / 2;
        const left = Math.max(0, Math.floor(point.x - halfSize));
        const top = Math.max(0, Math.floor(point.y - halfSize));
        const right = Math.min(canvas.width, Math.ceil(point.x + halfSize));
        const bottom = Math.min(canvas.height, Math.ceil(point.y + halfSize));
        const width = right - left;
        const height = bottom - top;

        if (width <= 0 || height <= 0) {
            return;
        }

        const imageData = ctx.getImageData(left, top, width, height);
        const data = imageData.data;
        const targetAlpha = Math.round(fogAlpha * 255);

        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                const index = (y * width + x) * 4;

                data[index] = 0;
                data[index + 1] = 0;
                data[index + 2] = 0;
                data[index + 3] = Math.max(data[index + 3], targetAlpha);
            }
        }

        ctx.putImageData(imageData, left, top);
    }

    function paintLine(from, to, paintFunction) {
        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const distance = Math.sqrt(dx * dx + dy * dy);
        const spacing = Math.max(2, (brushSize ? Number(brushSize.value) : 60) / 5);
        const steps = Math.max(1, Math.ceil(distance / spacing));

        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            paintFunction({
                x: from.x + dx * t,
                y: from.y + dy * t
            });
        }
    }

    function startDrawing(event) {
        const mode = getToolMode();

        if (mode === "pan") {
            startPanning(event);
            return;
        }

        event.preventDefault();
        stampCanvas.setPointerCapture(event.pointerId);

        const point = getCanvasPoint(event);

        if (mode === "label") {
            placeStamp(point);
            try {
                stampCanvas.releasePointerCapture(event.pointerId);
            } catch (e) {
                // Pointer capture may already be released.
            }
            return;
        }

        if (mode === "removeLabel") {
            removeStampAt(point);
            try {
                stampCanvas.releasePointerCapture(event.pointerId);
            } catch (e) {
                // Pointer capture may already be released.
            }
            return;
        }

        drawing = true;
        lastPoint = point;

        if (mode === "refog") {
            restoreFogAt(lastPoint);
        } else {
            eraseAt(lastPoint);
        }
    }

    function draw(event) {
        if (panning) {
            pan(event);
            return;
        }

        if (!drawing) {
            return;
        }

        event.preventDefault();

        const point = getCanvasPoint(event);

        if (getToolMode() === "refog") {
            paintLine(lastPoint, point, restoreFogAt);
        } else {
            paintLine(lastPoint, point, eraseAt);
        }

        lastPoint = point;
    }

    function stopDrawing(event) {
        if (panning) {
            stopPanning(event);
            return;
        }

        if (!drawing) {
            return;
        }

        event.preventDefault();

        drawing = false;
        lastPoint = null;

        try {
            stampCanvas.releasePointerCapture(event.pointerId);
        } catch (e) {
            // Pointer capture may already be released.
        }
    }

    function createRenderedMapCanvas() {
        const output = document.createElement("canvas");
        const outputCtx = output.getContext("2d");

        output.width = image.naturalWidth;
        output.height = image.naturalHeight;

        outputCtx.drawImage(image, 0, 0);
        outputCtx.drawImage(canvas, 0, 0);
        outputCtx.drawImage(stampCanvas, 0, 0);

        return output;
    }

    function getTimestampedFilename() {
        const now = new Date();
        const pad = function (value) {
            return String(value).padStart(2, "0");
        };

        return "revealed-map-" +
            now.getFullYear() + "-" +
            pad(now.getMonth() + 1) + "-" +
            pad(now.getDate()) + "_" +
            pad(now.getHours()) + "-" +
            pad(now.getMinutes()) + "-" +
            pad(now.getSeconds()) + ".png";
    }

    function downloadRevealedImage() {
        const output = createRenderedMapCanvas();
        const link = document.createElement("a");

        link.download = getTimestampedFilename();
        link.href = output.toDataURL("image/png");
        link.click();
    }

    async function saveRenderedImageToServer() {
        const output = createRenderedMapCanvas();
        const formData = new FormData();

        formData.append("action", "save_revealed_image");
        formData.append("imageData", output.toDataURL("image/png"));
        formData.append("fogData", canvas.toDataURL("image/png"));
        formData.append("stamps", JSON.stringify(stamps));
        formData.append("baseFile", baseFile || "");
        formData.append("fogOpacity", fogOpacity.value);

        if (!saveToServerButton || !saveStatus) {
            return;
        }

        saveToServerButton.disabled = true;
        saveStatus.textContent = "Saving...";

        try {
            const response = await fetch(window.location.href, {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            });

            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.error || "Could not save the rendered image.");
            }

            saveStatus.textContent = "";

            const savedLink = document.createElement("a");
            savedLink.href = result.url;
            savedLink.download = result.filename;
            savedLink.textContent = result.filename;

            saveStatus.append("Saved as ", savedLink);
        } catch (error) {
            saveStatus.textContent = error.message;
        } finally {
            saveToServerButton.disabled = false;
        }
    }

    if (brushSize && brushSizeValue) {
        brushSize.addEventListener("input", function () {
            brushSizeValue.textContent = brushSize.value;
        });
    }

    if (fogOpacity && fogOpacityValue) {
        fogOpacity.addEventListener("input", function () {
            const previousFogAlpha = fogAlpha;
            const nextFogAlpha = Number(fogOpacity.value) / 100;

            fogAlpha = nextFogAlpha;
            fogOpacityValue.textContent = fogOpacity.value;
            rescaleExistingFogOpacity(previousFogAlpha, nextFogAlpha);
        });
    }

    if (stampSize && stampSizeValue) {
        stampSize.addEventListener("input", function () {
            stampSizeValue.textContent = stampSize.value;
        });
    }

    if (labelText) {
        labelText.addEventListener("input", function () {
            const normalized = normalizeLabelText(labelText.value);
            if (labelText.value !== normalized) {
                labelText.value = normalized;
            }
            if (saveStatus && saveStatus.textContent === "Choose a symbol before placing it.") {
                saveStatus.textContent = "";
            }
        });
    }

    if (labelColor) {
        labelColor.addEventListener("change", function () {
            if (saveStatus && saveStatus.textContent === "Choose a symbol before placing it.") {
                saveStatus.textContent = "";
            }
        });
    }

    if (undoStampButton) {
        undoStampButton.addEventListener("click", undoStamp);
    }
    if (clearStampsButton) {
        clearStampsButton.addEventListener("click", clearStamps);
    }

    for (const input of toolModeInputs) {
        input.addEventListener("change", updatePointerBehavior);
    }

    updatePointerBehavior();

    if (resetFogButton) {
        resetFogButton.addEventListener("click", resetFog);
    }
    if (clearFogButton) {
        clearFogButton.addEventListener("click", clearFog);
    }
    if (saveFogButton) {
        saveFogButton.addEventListener("click", downloadRevealedImage);
    }
    if (saveToServerButton) {
        saveToServerButton.addEventListener("click", saveRenderedImageToServer);
    }

    stampCanvas.addEventListener("pointerdown", startDrawing);
    stampCanvas.addEventListener("pointermove", draw);
    stampCanvas.addEventListener("pointerup", stopDrawing);
    stampCanvas.addEventListener("pointercancel", stopDrawing);
    stampCanvas.addEventListener("pointerleave", stopDrawing);

    if (image.complete) {
        setupCanvas();
    } else {
        image.addEventListener("load", setupCanvas);
    }
})();
</script>
<?php endif; ?>
</body>
</html>