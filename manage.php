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

if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    session_unset(); session_destroy();
    header('Location: manage.php'); exit;
}
$_SESSION['last_activity'] = time();

function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}
function mdb() {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) throw new Exception('Database connection failed.');
    $db->set_charset(DB_CHARSET);
    return $db;
}
function attemptLogin($callsign, $password) {
    $callsignOK = hash_equals(strtoupper(MY_CALLSIGN), strtoupper(trim($callsign)));
    $passwordOK = password_verify($password, MY_PASS_HASH);
    if ($callsignOK && $passwordOK) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['callsign'] = strtoupper(trim($callsign));
        return true;
    }
    return false;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: manage.php'); exit;
}

$loginError = null;
if (!isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $loginError = 'Invalid session. Please retry.';
    } else {
        $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'] ?? [], fn($t) => time() - $t < 300);
        if (count($_SESSION['login_attempts']) >= 5) {
            $loginError = 'Too many attempts. Wait 5 minutes.';
        } else {
            $c = $_POST['callsign'] ?? ''; $p = $_POST['password'] ?? '';
            if ($c === '' || $p === '') $loginError = 'Please enter your callsign and password.';
            elseif (!attemptLogin($c, $p)) { $_SESSION['login_attempts'][] = time(); sleep(1); $loginError = 'Incorrect callsign or password.'; }
        }
    }
}

// Detect optional columns
$hasPga = $hasVoiv = false;
if (isLoggedIn()) {
    try {
        $db = mdb();
        $r = $db->query("SHOW COLUMNS FROM qso_log LIKE 'my_pga_ref'"); if ($r) { $hasPga = $r->num_rows > 0; $r->free(); }
        $r = $db->query("SHOW COLUMNS FROM qso_log LIKE 'my_voivodeship_ref'"); if ($r) { $hasVoiv = $r->num_rows > 0; $r->free(); }
        $db->close();
    } catch (Throwable $e) {}
}

// ---------- Delete single QSO ----------
$msg = $err = null; $delPreview = null;
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['del_id']) || isset($_POST['del_confirm']))) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $err = 'Invalid session. Please retry.';
    else {
        $tid = (int)($_POST['del_confirm'] ?? $_POST['del_id'] ?? 0);
        if ($tid <= 0) $err = 'Invalid record.';
        else try {
            $db = mdb();
            $s = $db->prepare('SELECT id, station_call, qso_date, time_on FROM qso_log WHERE id = ?');
            $s->bind_param('i', $tid); $s->execute();
            $row = $s->get_result()->fetch_assoc(); $s->close();
            if (!$row) $err = "Record #$tid not found.";
            elseif (!isset($_POST['del_confirm'])) $delPreview = $row;
            else {
                $d = $db->prepare('DELETE FROM qso_log WHERE id = ?');
                $d->bind_param('i', $tid); $d->execute(); $d->close();
                $msg = "Record #$tid deleted.";
            }
            $db->close();
        } catch (Throwable $e) { $err = 'Delete failed: ' . $e->getMessage(); }
    }
}

// ---------- Edit single QSO ----------
$editRow = null;
$results = []; $searched = false; $searchErr = null;
$editable = ['station_call','band','freq','qso_date','time_on','rst_sent','rst_rcvd','station_callsign','my_wwff_ref','my_pota_ref','my_gridsquare','my_voivodeship_ref','my_pga_ref'];
if (isLoggedIn() && isset($_GET['edit_id'])) {
    $eid = (int)$_GET['edit_id'];
    if ($eid > 0) try {
        $db = mdb();
        $s = $db->prepare('SELECT * FROM qso_log WHERE id = ?');
        $s->bind_param('i', $eid); $s->execute();
        $editRow = $s->get_result()->fetch_assoc(); $s->close(); $db->close();
        if (!$editRow) $err = "Record #$eid not found.";
    } catch (Throwable $e) { $err = 'Load failed: ' . $e->getMessage(); }
}
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_save'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $err = 'Invalid session. Please retry.';
    else {
        $eid = (int)($_POST['edit_id'] ?? 0);
        if ($eid <= 0) $err = 'Invalid record.';
        else {
            $v = [];
            foreach ($editable as $col) $v[$col] = mb_substr(trim($_POST[$col] ?? ''), 0, 50);
            if ($v['station_call'] === '') $err = 'Worked call cannot be empty.';
            elseif ($v['qso_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['qso_date'])) $err = "Bad date (YYYY-MM-DD).";
            elseif ($v['my_voivodeship_ref'] !== '' && !preg_match('/^[A-Z]$/i', $v['my_voivodeship_ref'])) $err = 'Voivodeship must be one letter.';
            else {
                foreach (['station_call','station_callsign','my_wwff_ref','my_pota_ref','my_gridsquare','my_pga_ref'] as $u) $v[$u] = strtoupper($v[$u]);
                $v['my_voivodeship_ref'] = strtoupper($v['my_voivodeship_ref']);
                foreach ($v as $k => $x) if ($x === '') $v[$k] = null;
                if (!$hasPga) unset($v['my_pga_ref']);
                if (!$hasVoiv) unset($v['my_voivodeship_ref']);
                try {
                    $db = mdb();
                    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($v)));
                    $stmt = $db->prepare("UPDATE qso_log SET $set WHERE id = ?");
                    $types = str_repeat('s', count($v)) . 'i';
                    $params = array_values($v); $params[] = $eid;
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute(); $stmt->close();
                    // Reload the saved row so it can be shown as a single-record result below
                    $pgaS = $hasPga ? ', my_pga_ref' : ''; $voivS = $hasVoiv ? ', my_voivodeship_ref' : '';
                    $rs = $db->prepare("SELECT id, upload_id, station_call, band, freq, qso_date, time_on, rst_sent, rst_rcvd, station_callsign, my_wwff_ref, my_pota_ref, my_gridsquare$pgaS$voivS FROM qso_log WHERE id = ?");
                    $rs->bind_param('i', $eid); $rs->execute();
                    $savedRow = $rs->get_result()->fetch_assoc(); $rs->close();
                    $db->close();
                    $msg = "Record #$eid updated.";
                    $editRow = null;
                    if ($savedRow) { $results = [$savedRow]; $searched = true; }
                } catch (Throwable $e) { $err = 'Update failed: ' . $e->getMessage(); }
            }
            if ($err) { try { $db = mdb(); $s = $db->prepare('SELECT * FROM qso_log WHERE id = ?'); $s->bind_param('i', $eid); $s->execute(); $editRow = $s->get_result()->fetch_assoc(); $s->close(); $db->close(); } catch (Throwable $e) {} }
        }
    }
}

// ---------- Search ----------
$f = ['call' => strtoupper(trim($_GET['call'] ?? '')), 'dfrom' => trim($_GET['dfrom'] ?? ''),
    'dto' => trim($_GET['dto'] ?? ''), 'spff' => strtoupper(trim($_GET['spff'] ?? '')),
    'pota' => strtoupper(trim($_GET['pota'] ?? '')), 'grid' => strtoupper(trim($_GET['grid'] ?? ''))];
if (isLoggedIn() && isset($_GET['s'])) {
    if ($f['dfrom'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['dfrom'])) $searchErr = "Bad 'Date from'.";
    elseif ($f['dto'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['dto'])) $searchErr = "Bad 'Date to'.";
    else try {
        $db = mdb();
        $pgaS = $hasPga ? ', my_pga_ref' : ''; $voivS = $hasVoiv ? ', my_voivodeship_ref' : '';
        $where = ['1=1']; $params = []; $types = '';
        if ($f['call'] !== '') { $where[] = 'station_call LIKE ?'; $params[] = '%' . $f['call'] . '%'; $types .= 's'; }
        if ($f['dfrom'] !== '') { $where[] = 'qso_date >= ?'; $params[] = $f['dfrom']; $types .= 's'; }
        if ($f['dto'] !== '') { $where[] = 'qso_date <= ?'; $params[] = $f['dto']; $types .= 's'; }
        if ($f['spff'] !== '') { $where[] = 'my_wwff_ref LIKE ?'; $params[] = '%' . $f['spff'] . '%'; $types .= 's'; }
        if ($f['pota'] !== '') { $where[] = 'my_pota_ref LIKE ?'; $params[] = '%' . $f['pota'] . '%'; $types .= 's'; }
        if ($f['grid'] !== '') { $where[] = 'my_gridsquare LIKE ?'; $params[] = '%' . $f['grid'] . '%'; $types .= 's'; }
        $sql = "SELECT id, upload_id, station_call, band, freq, qso_date, time_on, rst_sent, rst_rcvd, station_callsign, my_wwff_ref, my_pota_ref, my_gridsquare$pgaS$voivS FROM qso_log WHERE " . implode(' AND ', $where) . " ORDER BY qso_date DESC, time_on DESC LIMIT 500";
        $stmt = $db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $results[] = $r;
        $stmt->close(); $db->close();
        $searched = true;
    } catch (Throwable $e) { $searchErr = 'Search failed: ' . $e->getMessage(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HAM Radio Log &mdash; Manage QSOs</title>
<link rel="stylesheet" href="style.css">
<style>
.table-wrapper { overflow-x: auto; margin-top: 6px; }
table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
thead tr { background: #0d1117; border-bottom: 2px solid #f0a500; }
thead th { padding: 10px 12px; text-align: left; color: #f0a500; font-size: 0.78em; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
tbody tr:nth-child(odd) { background: #161b22; }
tbody tr:nth-child(even) { background: #1c2128; }
tbody tr { border-bottom: 1px solid #21262d; }
tbody td { padding: 8px 10px; color: #c9d1d9; white-space: nowrap; }
.filter-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.filter-grid input { width: 100%; padding: 10px 13px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 1em; }
.filter-grid input:focus { outline: none; border-color: #f0a500; }
.edit-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 12px; }
.edit-grid input { width: 100%; padding: 9px 12px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 0.95em; }
.edit-grid input:focus { outline: none; border-color: #f0a500; }
.edit-grid input[readonly] { background: #1c2128; color: #8b949e; }
@media (max-width: 700px) { .filter-grid, .edit-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php if (!isLoggedIn()): ?>
<div class="login-wrapper"><div class="login-card">
<div class="login-header"><div class="login-icon">📡</div><h1>HAM Radio Log</h1><p>QSO Manager &mdash; Secure Access</p></div>
<?php if ($loginError): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($loginError) ?></div><?php endif; ?>
<div class="card"><h2>🔐 Operator Login</h2>
<form method="POST" action="manage.php" autocomplete="off">
<input type="hidden" name="login" value="1">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<div class="form-group"><label for="callsign">Callsign</label><input type="text" id="callsign" name="callsign" maxlength="20" autocomplete="off" required></div>
<div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" maxlength="100" autocomplete="off" required></div>
<button type="submit" class="btn btn-primary">🔑 &nbsp;Login</button>
</form></div></div></div>
<?php else: ?>
<div class="container">
<header><h1>🛠️ QSO Manager</h1><p>Edit / delete individual log records</p></header>
<div class="top-bar">
<span>Logged in as: <strong><?= htmlspecialchars($_SESSION['callsign']) ?></strong></span>
<span><a href="index.php" class="btn btn-primary btn-sm">🔍 Search log</a>
<a href="upload.php" class="btn btn-primary btn-sm">📤 Upload</a>
<a href="manage.php?logout=1" class="btn btn-danger btn-sm">⏻ Logout</a></span>
</div>
<?php if ($err): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($searchErr): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($searchErr) ?></div><?php endif; ?>

<?php if ($delPreview): ?>
<div class="card" style="border-color:#da3633">
<h2 style="color:#f85149">⚠️ Confirm Deletion</h2>
<p style="margin-bottom:12px;line-height:1.6">Delete record <strong>#<?= (int)$delPreview['id'] ?></strong>
(<?= htmlspecialchars($delPreview['station_call'] ?? '', ENT_QUOTES, 'UTF-8') ?>,
<?= htmlspecialchars($delPreview['qso_date'] ?? '', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($delPreview['time_on'] ?? '', ENT_QUOTES, 'UTF-8') ?>)?</p>
<p style="margin-bottom:16px;color:#f85149"><strong>Warning: this permanently removes this QSO. This cannot be undone.</strong></p>
<form method="POST" style="display:flex;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="del_confirm" value="<?= (int)$delPreview['id'] ?>">
<button type="submit" class="btn btn-danger" style="width:auto;padding:10px 24px">Yes, delete record</button>
<a href="manage.php" class="btn btn-primary" style="width:auto;padding:10px 24px;background:#21262d;color:#c9d1d9;border:1px solid #30363d">Cancel</a>
</form></div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card" style="border-color:#f0a500">
<h2>✏️ Edit record #<?= (int)$editRow['id'] ?></h2>
<form method="POST">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="edit_save" value="1">
<input type="hidden" name="edit_id" value="<?= (int)$editRow['id'] ?>">
<div class="edit-grid">
<div class="form-group"><label>ID (read only)</label><input type="text" value="<?= (int)$editRow['id'] ?>" readonly></div>
<div class="form-group"><label>upload_id (read only)</label><input type="text" value="<?= htmlspecialchars((string)($editRow['upload_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
<?php
$labels = ['station_call'=>'Worked call','band'=>'Band','freq'=>'Freq','qso_date'=>'Date (YYYY-MM-DD)','time_on'=>'Time','rst_sent'=>'RST sent','rst_rcvd'=>'RST rcvd','station_callsign'=>'Station callsign','my_wwff_ref'=>'WWFF','my_pota_ref'=>'POTA','my_gridsquare'=>'Grid','my_voivodeship_ref'=>'Voiv (1 letter)','my_pga_ref'=>'PGA'];
foreach ($labels as $col => $lab) {
    if ($col === 'my_pga_ref' && !$hasPga) continue;
    if ($col === 'my_voivodeship_ref' && !$hasVoiv) continue;
    echo '<div class="form-group"><label>' . htmlspecialchars($lab) . '</label><input type="text" name="' . $col . '" maxlength="50" value="' . htmlspecialchars((string)($editRow[$col] ?? ''), ENT_QUOTES, 'UTF-8') . '"></div>';
}
?>
</div>
<div style="display:flex;gap:10px;margin-top:14px">
<button type="submit" class="btn btn-primary" style="width:auto;padding:10px 28px">💾 Save</button>
<a href="manage.php" class="btn btn-primary" style="width:auto;padding:10px 24px;background:#21262d;color:#c9d1d9;border:1px solid #30363d">Cancel</a>
</div></form></div>
<?php endif; ?>

<?php if ($searched): ?>
<div class="card"><h2>📋 <?= count($results) ?> record(s)</h2>
<div class="table-wrapper"><table><thead><tr>
<th>ID</th><th>UPL</th><th>CALL</th><th>Band</th><th>Freq</th><th>Date</th><th>Time</th><th>RST S</th><th>RST R</th><th>STATION</th><th>WWFF</th><th>POTA</th><th>GRID</th><?php if ($hasVoiv): ?><th>V</th><?php endif; ?><?php if ($hasPga): ?><th>PGA</th><?php endif; ?><th></th>
</tr></thead><tbody>
<?php foreach ($results as $r): ?>
<tr>
<td><?= (int)$r['id'] ?></td>
<td><?= htmlspecialchars((string)($r['upload_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
<td><strong><?= htmlspecialchars($r['station_call'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
<td><?= htmlspecialchars($r['band'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['freq'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['qso_date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars(substr((string)($r['time_on'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['rst_sent'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['rst_rcvd'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['station_callsign'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['my_wwff_ref'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['my_pota_ref'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($r['my_gridsquare'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
<?php if ($hasVoiv): ?><td><?= htmlspecialchars($r['my_voivodeship_ref'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
<?php if ($hasPga): ?><td><?= htmlspecialchars($r['my_pga_ref'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
<td style="white-space:nowrap">
<a href="manage.php?edit_id=<?= (int)$r['id'] ?>" title="Edit record #<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm">✏️</a>
<form method="POST" style="display:inline" onsubmit="return confirm('Delete record #<?= (int)$r['id'] ?>?');">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="del_id" value="<?= (int)$r['id'] ?>">
<button type="submit" title="Delete record #<?= (int)$r['id'] ?>" class="btn btn-danger btn-sm">🗑</button>
</form></td>
</tr>
<?php endforeach; ?>
<?php if (!count($results)): ?><tr><td colspan="20" style="text-align:center;color:#8b949e">No records found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>

<div class="card"><h2>🔍 Find records</h2>
<form method="GET" action="manage.php" autocomplete="off">
<input type="hidden" name="s" value="1">
<div class="filter-grid">
<div class="form-group"><label>Callsign</label><input type="text" name="call" maxlength="20" value="<?= htmlspecialchars($f['call'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="form-group"><label>Date from</label><input type="date" name="dfrom" value="<?= htmlspecialchars($f['dfrom'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="form-group"><label>Date to</label><input type="date" name="dto" value="<?= htmlspecialchars($f['dto'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="form-group"><label>SPFF / WWFF</label><input type="text" name="spff" maxlength="20" value="<?= htmlspecialchars($f['spff'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="form-group"><label>POTA</label><input type="text" name="pota" maxlength="20" value="<?= htmlspecialchars($f['pota'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="form-group"><label>GRID</label><input type="text" name="grid" maxlength="10" value="<?= htmlspecialchars($f['grid'], ENT_QUOTES, 'UTF-8') ?>"></div>
</div>
<button type="submit" class="btn btn-primary" style="margin-top:14px">🔍 &nbsp;Search</button>
</form></div>

</div>
<?php endif; ?>
</body>
</html>
