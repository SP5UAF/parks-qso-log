<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
require_once 'config.php';
require_once 'adif_processor.php';

// Idle timeout: 30 min
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    session_unset(); session_destroy();
    header('Location: upload.php'); exit;
}
$_SESSION['last_activity'] = time();

// ============================================================
// Auth Functions
// ============================================================
function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Human-readable bytes, e.g. 52428800 -> "50 MB"
function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units));
    return round($bytes / (1024 ** $i), $i >= 2 ? 2 : 0) . ' ' . $units[$i - 1];
}

function attemptLogin($callsign, $password) {
    // Timing-safe callsign check + bcrypt password verify. Hash stored in env MY_PASS_HASH.
    $callsignOK = hash_equals(strtoupper(MY_CALLSIGN), strtoupper(trim($callsign)));
    $passwordOK = password_verify($password, MY_PASS_HASH);
    if ($callsignOK && $passwordOK) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['callsign']      = strtoupper(trim($callsign));
        return true;
    }
    return false;
}

// ============================================================
// Logout
// ============================================================
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: upload.php');
    exit;
}

// ============================================================
// Login Handling
// ============================================================
$loginError = null;

if (!isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $loginError = "Invalid session. Please retry.";
    } else {
    // Rate limit: max 5 attempts per 5 min per IP+session
    $attemptKey = 'login_attempts';
    $_SESSION[$attemptKey] = array_filter($_SESSION[$attemptKey] ?? [], fn($t) => time() - $t < 300);
    if (count($_SESSION[$attemptKey]) >= 5) {
        $loginError = "Too many attempts. Wait 5 minutes.";
    } else {
    $enteredCall = $_POST['callsign'] ?? '';
    $enteredPass = $_POST['password'] ?? '';

    if (empty($enteredCall) || empty($enteredPass)) {
        $loginError = "Please enter your callsign and password.";
    } elseif (!attemptLogin($enteredCall, $enteredPass)) {
        $_SESSION[$attemptKey][] = time();
        sleep(1);
        $loginError = "Incorrect callsign or password.";
    }
    }
    }
}

// ============================================================
// File Upload Handling
// ============================================================
$uploadStats   = null;
$uploadError   = null;
$uploadSuccess = false;

if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['adif_file'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $uploadError = "Invalid session. Please retry.";
    } else {

    $file = $_FILES['adif_file'];

    $phpUploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot write temp file.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server.',
    ];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $phpUploadErrors[$file['error']] ?? "Upload error code: {$file['error']}";
    } elseif ($file['size'] === 0) {
        $uploadError = "Uploaded file is empty.";
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $uploadError = "File too large. Maximum is " . formatBytes(MAX_FILE_SIZE) . ".";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['adi', 'adif'])) {
            $uploadError = "Invalid file type. Only .adi or .adif allowed.";
        }
    }

    if ($uploadError === null) {

        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
            $uploadError = "Cannot create uploads directory.";
        } elseif (!is_writable(UPLOAD_DIR)) {
            $uploadError = "Uploads directory is not writable.";
        } else {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
            $tempPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                $uploadError = "Failed to save uploaded file.";
            } else {
                // Comment for the upload log (optional, max 1000 chars)
                $uploadComment = mb_substr(trim($_POST['comments'] ?? ''), 0, 1000);
                if ($uploadComment === '') $uploadComment = null;
                // Station overrides: applied to every QSO when filled, else ADIF values kept.
                // Refs/grids uppercased; voivodeship must be a single capital letter.
                $overrides = [
                    'rig'     => mb_substr(trim($_POST['my_rig'] ?? ''), 0, 100),
                    'antenna' => mb_substr(trim($_POST['my_antenna'] ?? ''), 0, 100),
                    'wwff'    => mb_substr(strtoupper(trim($_POST['my_wwff'] ?? '')), 0, 20),
                    'pota'    => mb_substr(strtoupper(trim($_POST['my_pota'] ?? '')), 0, 50),
                    'sota'    => mb_substr(strtoupper(trim($_POST['my_sota'] ?? '')), 0, 20),
                    'pga'     => mb_substr(strtoupper(trim($_POST['my_pga'] ?? '')), 0, 10),
                    'grid'    => mb_substr(strtoupper(trim($_POST['my_grid'] ?? '')), 0, 10),
                    'voivodeship' => strtoupper(trim($_POST['voivodeship'] ?? '')),
                ];
                foreach ($overrides as $k => $v) {
                    if ($v === '') $overrides[$k] = null;
                }
                if ($overrides['voivodeship'] !== null
                    && !preg_match('/^[A-Z]$/', $overrides['voivodeship'])) {
                    $overrides['voivodeship'] = null;
                }
                $uploadId = null;
                try {
                    $processor = new ADIFProcessor();
                    // Register the upload first to obtain upload_id.
                    // Falls back to untracked import if migration not yet applied.
                    try {
                        $uploadId = $processor->registerUpload($file['name'], $uploadComment);
                    } catch (Exception $e) {
                        error_log('registerUpload failed (migration missing?): ' . $e->getMessage());
                        $uploadId = null;
                    }
                    $uploadStats = $processor->processFile($tempPath, $uploadId, $overrides);
                    if ($uploadId !== null) {
                        try { $processor->finishUpload($uploadId, $uploadStats); }
                        catch (Exception $e) { error_log('finishUpload failed: ' . $e->getMessage()); }
                    }
                    $uploadSuccess = true;
                    $processor->closeConnection();
                } catch (Exception $e) {
                    $uploadError = "Processing error: " . $e->getMessage();
                } finally {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }
            }
        }
    }
    } // csrf else
}

// ============================================================
// Delete Upload Handling (authenticated only)
// Two-step: POST delete_id -> shows confirmation; POST delete_confirm=1 -> deletes
// ============================================================
$deleteError   = null;
$deleteSuccess = null;
$deletePreview = null; // row to confirm deletion for

function deleteDb() {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) throw new Exception("Database connection failed.");
    $db->set_charset(DB_CHARSET);
    return $db;
}

if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['delete_id']) || isset($_POST['delete_confirm']))) {

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $deleteError = "Invalid session. Please retry.";
    } else {
        $targetId = (int)($_POST['delete_confirm'] ?? $_POST['delete_id'] ?? 0);
        if ($targetId <= 0) {
            $deleteError = "Invalid upload selected.";
        } else {
            try {
                $db = deleteDb();
                // Load upload + live QSO count for preview / warning
                $stmt = $db->prepare('SELECT upload_id, filename, comments, upload_date,
                    qsos_found, qsos_inserted FROM uploads WHERE upload_id = ?');
                $stmt->bind_param('i', $targetId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    $deleteError = "Upload #$targetId not found.";
                } elseif (!isset($_POST['delete_confirm'])) {
                    $countStmt = $db->prepare('SELECT COUNT(*) FROM qso_log WHERE upload_id = ?');
                    $countStmt->bind_param('i', $targetId);
                    $countStmt->execute();
                    $row['qso_count'] = (int)$countStmt->get_result()->fetch_row()[0];
                    $countStmt->close();
                    $deletePreview = $row;
                } else {
                    // Confirmed: delete QSOs + upload row in one transaction
                    $db->begin_transaction();
                    try {
                        $del1 = $db->prepare('DELETE FROM qso_log WHERE upload_id = ?');
                        $del1->bind_param('i', $targetId);
                        $del1->execute();
                        $qsoDeleted = $del1->affected_rows;
                        $del1->close();
                        $del2 = $db->prepare('DELETE FROM uploads WHERE upload_id = ?');
                        $del2->bind_param('i', $targetId);
                        $del2->execute();
                        $del2->close();
                        $db->commit();
                        $deleteSuccess = "Upload #$targetId deleted ($qsoDeleted QSOs removed).";
                    } catch (Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                }
                $db->close();
            } catch (Exception $e) {
                $deleteError = "Delete failed: " . $e->getMessage();
            }
        }
    }
}

// ============================================================
// Upload History (authenticated only, paginated 10/page)
// ============================================================
$huPage = max(1, (int)($_GET['hupage'] ?? $_POST['hupage'] ?? 1));
$huPerPage = 10;
$huTotal = 0;
$huPages = 1;
$uploadHistory = [];
if (isLoggedIn()) {
    try {
        $db = deleteDb();
        $cnt = $db->query('SELECT COUNT(*) FROM uploads');
        if ($cnt) { $huTotal = (int)$cnt->fetch_row()[0]; $cnt->free(); }
        $huPages = max(1, (int)ceil($huTotal / $huPerPage));
        $huPage = min($huPage, $huPages);
        $offset = ($huPage - 1) * $huPerPage;
        $res = $db->query('SELECT u.upload_id, u.filename, u.comments, u.upload_date,
                u.qsos_found, u.qsos_inserted, u.qsos_skipped, u.qsos_errors,
                (SELECT COUNT(*) FROM qso_log q WHERE q.upload_id = u.upload_id) AS qso_count
            FROM uploads u ORDER BY u.upload_id DESC LIMIT ' . $huPerPage . ' OFFSET ' . $offset);
        if ($res) { while ($r = $res->fetch_assoc()) $uploadHistory[] = $r; $res->free(); }
        $db->close();
    } catch (Exception $e) {
        // uploads table may not exist yet (migration pending): history stays empty
        error_log('upload history: ' . $e->getMessage());
    }
}

// ============================================================
// Voivodeship list for the upload form (sp_voivodeships)
// ============================================================
$voivodeships = [];
if (isLoggedIn()) {
    try {
        $db = deleteDb();
        $res = $db->query('SELECT indicator, name FROM sp_voivodeships ORDER BY indicator');
        if ($res) { while ($r = $res->fetch_assoc()) $voivodeships[] = $r; $res->free(); }
        $db->close();
    } catch (Exception $e) {
        // table missing (migration pending): dropdown hidden, field stays null
        error_log('voivodeships: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HAM Radio Log &mdash; ADIF Importer</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table-wrapper { overflow-x: auto; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        thead tr { background: #0d1117; border-bottom: 2px solid #f0a500; }
        thead th { padding: 10px 12px; text-align: left; color: #f0a500;
            font-size: 0.82em; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        tbody tr:nth-child(odd) { background: #161b22; }
        tbody tr:nth-child(even) { background: #1c2128; }
        tbody tr { border-bottom: 1px solid #21262d; }
        tbody td { padding: 9px 12px; color: #c9d1d9; }
    </style>
</head>
<body>

<?php if (!isLoggedIn()): ?>

<!-- ============================================================ -->
<!-- LOGIN PAGE                                                    -->
<!-- ============================================================ -->
<div class="login-wrapper">
    <div class="login-card">

        <div class="login-header">
            <div class="login-icon">📡</div>
            <h1>HAM Radio Log</h1>
            <p>ADIF Importer &mdash; Secure Access</p>
        </div>

        <?php if ($loginError): ?>
            <div class="alert alert-error">
                ⚠️ <?= htmlspecialchars($loginError) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>🔐 Operator Login</h2>
            <form method="POST" action="upload.php" autocomplete="off">
                <input type="hidden" name="login" value="1">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="callsign">Callsign</label>
                    <input
                        type="text"
                        id="callsign"
                        name="callsign"
                        placeholder="e.g. SP5UAF"
                        maxlength="20"
                        autocomplete="off"
                        value="<?= htmlspecialchars($_POST['callsign'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        maxlength="100"
                        autocomplete="off"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    🔑 &nbsp;Login
                </button>
            </form>
        </div>

    </div>
</div>

<?php else: ?>

<!-- ============================================================ -->
<!-- MAIN PAGE (Authenticated)                                     -->
<!-- ============================================================ -->
<div class="container">

    <header>
        <h1>📡 HAM Radio Log</h1>
        <p>ADIF File Importer</p>
    </header>

    <!-- Top bar -->
    <div class="top-bar">
        <span>Logged in as: <strong><?= htmlspecialchars($_SESSION['callsign']) ?></strong></span>
        <span>
            <a href="index.php" class="btn btn-primary btn-sm">🔍 &nbsp;Search log</a>
            <a href="manage.php" class="btn btn-primary btn-sm">🛠️ &nbsp;Manage QSOs</a>
            <a href="upload.php?logout=1" class="btn btn-danger btn-sm">⏻ &nbsp;Logout</a>
        </span>
    </div>

    <!-- Upload error -->
    <?php if ($uploadError): ?>
        <div class="alert alert-error">
            ⚠️ <?= htmlspecialchars($uploadError) ?>
        </div>
    <?php endif; ?>

    <!-- Delete error / success -->
    <?php if ($deleteError): ?>
        <div class="alert alert-error">
            ⚠️ <?= htmlspecialchars($deleteError) ?>
        </div>
    <?php endif; ?>
    <?php if ($deleteSuccess): ?>
        <div class="alert alert-success">
            ✅ <?= htmlspecialchars($deleteSuccess) ?>
        </div>
    <?php endif; ?>

    <!-- Delete confirmation -->
    <?php if ($deletePreview): ?>
        <div class="card" style="border-color:#da3633">
            <h2 style="color:#f85149">⚠️ Confirm Deletion</h2>
            <p style="margin-bottom:12px;line-height:1.6">
                Delete upload <strong>#<?= (int)$deletePreview['upload_id'] ?></strong>
                (<?= htmlspecialchars($deletePreview['filename'] ?? '', ENT_QUOTES, 'UTF-8') ?>,
                <?= htmlspecialchars($deletePreview['upload_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>)?
            </p>
            <p style="margin-bottom:16px;line-height:1.6;color:#f85149">
                <strong>Warning: this permanently removes the upload record AND
                all <?= (int)$deletePreview['qso_count'] ?> related QSOs from the log.
                This cannot be undone.</strong>
            </p>
            <form method="POST" action="upload.php" style="display:flex;gap:10px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="hupage" value="<?= (int)($huPage ?? 1) ?>">
                <input type="hidden" name="delete_confirm" value="<?= (int)$deletePreview['upload_id'] ?>">
                <button type="submit" class="btn btn-danger" style="flex:1">
                    🗑️ &nbsp;Yes, delete everything
                </button>
                <a href="upload.php<?= ($huPage ?? 1) > 1 ? '?hupage=' . (int)$huPage : '' ?>" class="btn btn-primary" style="flex:1">Cancel</a>
            </form>
        </div>
    <?php endif; ?>

    <!-- Import results -->
    <?php if ($uploadSuccess && $uploadStats): ?>
        <div class="card">
            <h2>✅ Import Complete</h2>
            <?php if (!empty($uploadId ?? null)): ?>
                <p style="color:#8b949e;font-size:0.9em;margin-bottom:12px">
                    Upload ID: <strong style="color:#f0a500">#<?= (int)($uploadId ?? 0) ?></strong>
                </p>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-number color-total">
                        <?= $uploadStats['total'] ?>
                    </div>
                    <div class="stat-label">Found</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number color-inserted">
                        <?= $uploadStats['inserted'] ?>
                    </div>
                    <div class="stat-label">Inserted</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number color-skipped">
                        <?= $uploadStats['skipped'] ?>
                    </div>
                    <div class="stat-label">Skipped</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number color-errors">
                        <?= $uploadStats['errors'] ?>
                    </div>
                    <div class="stat-label">Errors</div>
                </div>
            </div>

            <?php if (!empty($uploadStats['messages'])): ?>
                <p class="log-title">Processing Log</p>
                <div class="message-log">
                    <?php foreach ($uploadStats['messages'] as $msg): ?>
                        <p>&rsaquo; <?= htmlspecialchars($msg) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

    <!-- Upload form -->
    <div class="card">
        <h2>📂 Upload ADIF File</h2>
        <form method="POST" action="upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="upload-area" id="upload-area"
                 onclick="document.getElementById('adif_file').click()">
                <div class="upload-icon">📄</div>
                <span class="click-label">Click to select ADIF file</span>
                <p>Supported formats: .adi &nbsp;/&nbsp; .adif</p>
                <div id="file-name">No file selected</div>
            </div>

            <input
                type="file"
                id="adif_file"
                name="adif_file"
                accept=".adi,.adif"
            >

            <div class="form-group" style="margin-top:14px">
                <label for="comments">Comments (optional)</label>
                <textarea
                    id="comments"
                    name="comments"
                    rows="3"
                    maxlength="1000"
                    placeholder="e.g. POTA activation PL-0123, Sept holidays log"
                    style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em;font-family:inherit;resize:vertical"
                ></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px">
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_rig">My RIG (optional)</label>
                    <input type="text" id="my_rig" name="my_rig" maxlength="100"
                        autocomplete="off" placeholder="e.g. IC-7300"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_antenna">My ANT (optional)</label>
                    <input type="text" id="my_antenna" name="my_antenna" maxlength="100"
                        autocomplete="off" placeholder="e.g. EFHW 40-10m"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
            </div>
            <p style="color:#8b949e;font-size:0.82em;margin-top:8px">
                Filled values overwrite MY_RIG / MY_ANTENNA from the file for every imported QSO; empty keeps file values.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_wwff">My WWFF (optional)</label>
                    <input type="text" id="my_wwff" name="my_wwff" maxlength="20"
                        autocomplete="off" placeholder="e.g. SPFF-1234"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_pota">My POTA (optional)</label>
                    <input type="text" id="my_pota" name="my_pota" maxlength="50"
                        autocomplete="off" placeholder="e.g. PL-0123"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_sota">My SOTA (optional)</label>
                    <input type="text" id="my_sota" name="my_sota" maxlength="20"
                        autocomplete="off" placeholder="e.g. SP/BZ-001"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_pga">My PGA (optional)</label>
                    <input type="text" id="my_pga" name="my_pga" maxlength="10"
                        autocomplete="off" placeholder="e.g. NT08"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="my_grid">My GRID (optional)</label>
                    <input type="text" id="my_grid" name="my_grid" maxlength="10"
                        autocomplete="off" placeholder="e.g. JO90NB"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                </div>
                <?php if (!empty($voivodeships)): ?>
                <div class="form-group" style="margin-bottom:0">
                    <label for="voivodeship">Voivodeship (optional)</label>
                    <select id="voivodeship" name="voivodeship"
                        style="width:100%;padding:10px 13px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-size:1em">
                        <option value="">— none —</option>
                        <?php foreach ($voivodeships as $v): ?>
                            <option value="<?= htmlspecialchars($v['indicator'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($v['indicator'] . ' — ' . $v['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <p style="color:#8b949e;font-size:0.82em;margin-top:8px">
                These overwrite MY_WWFF_REF / MY_POTA_REF / MY_SOTA_REF / MY_PGA_REF / MY_GRIDSQUARE and the voivodeship for every imported QSO; empty keeps file values.
            </p>

            <button type="submit" class="btn btn-primary">
                ⬆️ &nbsp;Upload &amp; Import
            </button>

        </form>
    </div>

    <!-- Upload history + delete -->
    <div class="card">
        <h2>🗂️ Upload History</h2>
        <?php if (empty($uploadHistory)): ?>
            <p style="color:#8b949e;font-size:0.9em">No uploads logged yet.</p>
        <?php else: ?>
            <div class="table-wrapper" style="max-height:420px;overflow-y:auto">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>File</th>
                            <th>Date</th>
                            <th>Comments</th>
                            <th>QSOs</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploadHistory as $u): ?>
                            <tr>
                                <td>#<?= (int)$u['upload_id'] ?></td>
                                <td><?= htmlspecialchars($u['filename'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($u['upload_date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(mb_substr($u['comments'] ?? '', 0, 80), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$u['qso_count'] ?></td>
                                <td>
                                    <form method="POST" action="upload.php" style="display:inline"
                                        onsubmit="return confirm('Delete upload #<?= (int)$u['upload_id'] ?> and ALL its <?= (int)$u['qso_count'] ?> QSOs? This cannot be undone.')">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hupage" value="<?= (int)$huPage ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$u['upload_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($huPages > 1): ?>
                <p style="margin-top:12px;font-size:0.9em;color:#8b949e;text-align:center">
                    <?php if ($huPage > 1): ?>
                        <a href="upload.php?hupage=<?= $huPage - 1 ?>" style="color:#f0a500">← Newer</a>
                    <?php endif; ?>
                    Page <?= (int)$huPage ?> of <?= (int)$huPages ?>
                    (<?= (int)$huTotal ?> uploads)
                    <?php if ($huPage < $huPages): ?>
                        <a href="upload.php?hupage=<?= $huPage + 1 ?>" style="color:#f0a500">Older →</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Tips -->
    <div class="card tips">
        <h2>💡 Tips</h2>
        <ul>
            <li>ADIF files can be exported from <strong>WSJT-X</strong>, <strong>Log4OM</strong>,
                <strong>N1MM+</strong>, <strong>Ham Radio Deluxe</strong>, <strong>DX4WIN</strong>
                and most other logging software.</li>
            <li>Duplicate QSOs (same callsign + date + time + band + mode) are
                skipped automatically.</li>
            <li>Fields supported include SOTA, POTA, WWFF references for both
                contacted station and your own station.</li>
            <li>Maximum file size: <strong><?= htmlspecialchars(formatBytes(MAX_FILE_SIZE), ENT_QUOTES, 'UTF-8') ?></strong>.</li>
            <li>System version: <strong><?= htmlspecialchars(SYSTEM_VERSION) ?></strong>.</li>
        </ul>
    </div>

</div><!-- /.container -->

<!-- JavaScript: update filename label and highlight drop area -->
<script>
    const fileInput  = document.getElementById('adif_file');
    const fileLabel  = document.getElementById('file-name');
    const uploadArea = document.getElementById('upload-area');

    fileInput.addEventListener('change', function () {
        if (this.files && this.files.length > 0) {
            fileLabel.textContent = this.files[0].name;
            uploadArea.classList.add('has-file');
        } else {
            fileLabel.textContent = 'No file selected';
            uploadArea.classList.remove('has-file');
        }
    });
</script>

<?php endif; ?>

</body>
</html>
