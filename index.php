<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config.php';

// ============================================================
// Database Connection
// ============================================================
function getDB() {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        die("Database connection failed: " . $db->connect_error);
    }
    $db->set_charset(DB_CHARSET);
    return $db;
}

// ============================================================
// POTA Reference Counter
// The my_pota_ref field can contain multiple comma-separated
// references e.g. "PL-0123, PL-1234, PL-9999"
// Each POTA reference is exactly 7 characters long.
// This function extracts all unique POTA references.
// ============================================================
function countDistinctPOTA($rows, $field = 'my_pota_ref') {
    $potaRefs = [];

    foreach ($rows as $row) {
        $raw = trim($row[$field] ?? '');
        if (empty($raw)) continue;

        // Split by comma
        $parts = explode(',', $raw);

        foreach ($parts as $part) {
            $part = trim($part);
            // POTA reference is always exactly 7 characters
            if (strlen($part) === 7) {
                $potaRefs[$part] = true;
            }
        }
    }

    return count($potaRefs);
}

// ============================================================
// Search Logic
// ============================================================
$searchCall  = '';
$filterMode  = '';
$filterBand  = '';
$filterDateFrom = '';
$filterDateTo   = '';
$filterWWFF  = '';
$filterPOTA  = '';
$filterPGA   = '';
$showSota    = !empty($_GET['show_sota']) || !empty($_POST['show_sota']);
$results     = [];
$searchDone  = false;
$searchError = null;

// Stats
$totalQSOs = 0;
$wwffCount = 0;
$potaCount = 0;
$sotaCount = 0;
$modeOptions = [];
$bandOptions = [];
$hasPgaCol = false; // my_pga_ref exists only after migration_pga.sql
$hasVoivCol = false; // my_voivodeship_ref exists only after migration_voivodeship.sql

// Distinct mode/band for dropdowns + PGA column detection
try {
    $dbOpt = getDB();
    foreach (['mode' => 'modeOptions', 'band' => 'bandOptions'] as $col => $var) {
        $r = $dbOpt->query("SELECT DISTINCT `$col` FROM qso_log WHERE `$col` IS NOT NULL AND `$col` <> '' ORDER BY `$col` LIMIT 100");
        if ($r) { while ($row = $r->fetch_row()) { ${$var}[] = $row[0]; } $r->free(); }
    }
    $colCheck = $dbOpt->query("SHOW COLUMNS FROM qso_log LIKE 'my_pga_ref'");
    if ($colCheck) { $hasPgaCol = $colCheck->num_rows > 0; $colCheck->free(); }
    $colCheckV = $dbOpt->query("SHOW COLUMNS FROM qso_log LIKE 'my_voivodeship_ref'");
    if ($colCheckV) { $hasVoivCol = $colCheckV->num_rows > 0; $colCheckV->free(); }
    $dbOpt->close();
} catch (Throwable $e) { /* dropdowns stay empty */ }

if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search']))
    || ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['station_call']))) {

    $searchCall = strtoupper(trim($_POST['station_call'] ?? $_GET['station_call'] ?? ''));
    $filterMode = strtoupper(trim($_POST['mode'] ?? $_GET['mode'] ?? ''));
    $filterBand = strtolower(trim($_POST['band'] ?? $_GET['band'] ?? ''));
    $filterDateFrom = trim($_POST['date_from'] ?? $_GET['date_from'] ?? '');
    $filterDateTo   = trim($_POST['date_to'] ?? $_GET['date_to'] ?? '');
    $filterWWFF = strtoupper(trim($_POST['wwff'] ?? $_GET['wwff'] ?? ''));
    $filterPOTA = strtoupper(trim($_POST['pota'] ?? $_GET['pota'] ?? ''));
    $filterPGA = strtoupper(trim($_POST['pga'] ?? $_GET['pga'] ?? ''));
    $showSota = !empty($_POST['show_sota']) || !empty($_GET['show_sota']);

    if (empty($searchCall)) {
        $searchError = "Please enter a callsign to search.";
    } elseif (!preg_match('/^[A-Z0-9\/-]{2,20}$/', $searchCall)) {
        $searchError = "Invalid callsign format.";
    } elseif ($filterDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
        $searchError = "Invalid 'Date from' (use YYYY-MM-DD).";
    } elseif ($filterDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
        $searchError = "Invalid 'Date to' (use YYYY-MM-DD).";
    } else {
        $db = getDB();

        if ($filterPGA !== '' && !$hasPgaCol) {
            $searchError = "PGA search unavailable (database not migrated yet).";
            $db->close();
        }
    }

    if ($searchError === null && $searchCall !== '' && isset($db)) {

        $pgaSelect = $hasPgaCol ? ', my_pga_ref' : '';
        $voivSelect = $hasVoivCol ? ', my_voivodeship_ref' : '';

        $where = ['station_call = ?'];
        $params = [$searchCall];
        $types = 's';
        if ($filterMode !== '')     { $where[] = 'mode = ?';          $params[] = $filterMode; $types .= 's'; }
        if ($filterBand !== '')     { $where[] = 'band = ?';          $params[] = $filterBand; $types .= 's'; }
        if ($filterDateFrom !== '') { $where[] = 'qso_date >= ?';     $params[] = $filterDateFrom; $types .= 's'; }
        if ($filterDateTo !== '')   { $where[] = 'qso_date <= ?';     $params[] = $filterDateTo; $types .= 's'; }
        if ($filterWWFF !== '')     { $where[] = 'my_wwff_ref = ?';   $params[] = $filterWWFF; $types .= 's'; }
        if ($filterPOTA !== '')     { $where[] = 'my_pota_ref LIKE ?'; $params[] = '%' . $filterPOTA . '%'; $types .= 's'; }
        if ($filterPGA !== '')      { $where[] = 'my_pga_ref = ?';  $params[] = $filterPGA; $types .= 's'; }

        $sql = "SELECT
                    station_call,
                    station_callsign,
                    qso_date,
                    time_on,
                    band,
                    mode,
                    my_wwff_ref,
                    my_pota_ref,
                    my_sota_ref,
                    my_gridsquare" . $pgaSelect . $voivSelect . "
                FROM qso_log
                WHERE " . implode(' AND ', $where) . "
                ORDER BY qso_date DESC, time_on DESC
                LIMIT 1000";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            $searchError = "Query error: " . $db->error;
        } else {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $results[] = $row;
            }

            $stmt->close();
            $searchDone = true;
            $totalQSOs  = count($results);

            // --- Count distinct WWFF references ---
            $wwffRefs = [];
            foreach ($results as $row) {
                $ref = trim($row['my_wwff_ref'] ?? '');
                if (!empty($ref)) {
                    $wwffRefs[$ref] = true;
                }
            }
            $wwffCount = count($wwffRefs);

            // --- Count distinct POTA references ---
            $potaCount = countDistinctPOTA($results);

            // --- Count distinct SOTA references ---
            $sotaRefs = [];
            foreach ($results as $row) {
                $ref = trim($row['my_sota_ref'] ?? '');
                if (!empty($ref)) {
                    $sotaRefs[$ref] = true;
                }
            }
            $sotaCount = count($sotaRefs);
        }

        $db->close();
    }
}

// ============================================================
// Last 20 QSOs (by date) — ?latest=1
// ============================================================
$isLatest = false;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['latest'])) {
    try {
        $dbL = getDB();
        $pgaSelect = $hasPgaCol ? ', my_pga_ref' : '';
        $voivSelect = $hasVoivCol ? ', my_voivodeship_ref' : '';
        $sql = "SELECT station_call, station_callsign, qso_date, time_on, band, mode,
                    my_wwff_ref, my_pota_ref, my_sota_ref, my_gridsquare" . $pgaSelect . $voivSelect . "
                FROM qso_log ORDER BY qso_date DESC, time_on DESC LIMIT 20";
        $res = $dbL->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) $results[] = $row;
            $res->free();
        }
        $dbL->close();
        $isLatest = true;
        $searchDone = true;
        $totalQSOs = count($results);
    } catch (Throwable $e) {
        $searchError = "Could not load latest QSOs.";
    }
}

// ============================================================
// Whole-log statistics (always shown, last section)
// ============================================================
$logStats = null;
try {
    $dbS = getDB();
    $logStats = ['total' => 0, 'calls' => 0, 'first' => null, 'last' => null,
        'wwff' => 0, 'pota' => 0, 'sota' => 0, 'pga' => 0, 'grids' => 0];
    $pgaCount = $hasPgaCol ? ", COUNT(DISTINCT NULLIF(my_pga_ref, '')) AS pga" : '';
    $r = $dbS->query("SELECT COUNT(*) AS total,
            COUNT(DISTINCT station_call) AS calls,
            COUNT(DISTINCT NULLIF(my_wwff_ref, '')) AS wwff,
            COUNT(DISTINCT NULLIF(my_sota_ref, '')) AS sota,
            COUNT(DISTINCT NULLIF(my_gridsquare, '')) AS grids" . $pgaCount . "
        FROM qso_log");
    if ($r && ($agg = $r->fetch_assoc())) {
        $logStats['total'] = (int)$agg['total'];
        $logStats['calls'] = (int)$agg['calls'];
        $logStats['wwff']  = (int)$agg['wwff'];
        $logStats['sota']  = (int)$agg['sota'];
        $logStats['grids'] = (int)$agg['grids'];
        if ($hasPgaCol) $logStats['pga'] = (int)($agg['pga'] ?? 0);
        $r->free();
    }
    if ($logStats['total'] > 0) {
        $first = $dbS->query("SELECT qso_date, time_on FROM qso_log ORDER BY qso_date ASC, time_on ASC LIMIT 1");
        if ($first && ($frow = $first->fetch_assoc())) { $logStats['first'] = $frow; $first->free(); }
        $last = $dbS->query("SELECT qso_date, time_on FROM qso_log ORDER BY qso_date DESC, time_on DESC LIMIT 1");
        if ($last && ($lrow = $last->fetch_assoc())) { $logStats['last'] = $lrow; $last->free(); }
        // POTA refs can hold several comma-separated values per row: count in PHP
        $pr = $dbS->query("SELECT my_pota_ref FROM qso_log WHERE my_pota_ref IS NOT NULL AND my_pota_ref <> ''");
        if ($pr) {
            $potaRows = [];
            while ($prow = $pr->fetch_assoc()) $potaRows[] = $prow;
            $pr->free();
            $logStats['pota'] = countDistinctPOTA($potaRows);
        }
    }
    $dbS->close();
} catch (Throwable $e) { $logStats = null; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HAM Radio Log &mdash; Search</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .search-form {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .search-form .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .search-form .btn {
            width: auto;
            padding: 10px 28px;
            margin-top: 0;
            white-space: nowrap;
        }

        .search-filters {
            margin-top: 14px;
        }

        .search-filters .filter-row {
            display: grid;
            gap: 12px;
            margin-bottom: 12px;
        }

        .search-filters .filter-row:last-child { margin-bottom: 0; }

        .search-filters .filter-row.cols-2 { grid-template-columns: repeat(2, 1fr); }
        .search-filters .filter-row.cols-3 { grid-template-columns: repeat(3, 1fr); }

        .search-filters .form-group { margin-bottom: 0; }

        .search-filters select,
        .search-filters input[type="text"],
        .search-filters input[type="date"] {
            width: 100%;
            padding: 10px 13px;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            color: #c9d1d9;
            font-size: 1em;
        }

        .search-filters select:focus,
        .search-filters input:focus {
            outline: none;
            border-color: #f0a500;
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }

        thead tr {
            background: #0d1117;
            border-bottom: 2px solid #f0a500;
        }

        thead th {
            padding: 10px 12px;
            text-align: left;
            color: #f0a500;
            font-size: 0.82em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        tbody tr:nth-child(odd) {
    background: #161b22;
}
tbody tr:nth-child(even) {
    background: #1c2128;
}
tbody tr {
    border-bottom: 1px solid #21262d;
    transition: background 0.15s;
}
tbody tr:hover {
    background: #272e38;
}

        tbody td {
            padding: 9px 12px;
            color: #c9d1d9;
            white-space: nowrap;
        }

        tbody td.empty-cell {
            color: #3d444d;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 6px;
        }

        .summary-box {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 16px 10px;
            text-align: center;
        }

        .summary-number {
            font-size: 2em;
            font-weight: bold;
            line-height: 1;
            color: #f0a500;
        }

        .summary-label {
            font-size: 0.72em;
            color: #8b949e;
            margin-top: 7px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            line-height: 1.4;
        }

        .no-results {
            text-align: center;
            padding: 30px;
            color: #8b949e;
            font-size: 0.95em;
        }

        .no-results span {
            font-size: 2em;
            display: block;
            margin-bottom: 10px;
        }

        .ref-badge {
            display: inline-block;
            background: #1c2128;
            border: 1px solid #30363d;
            border-radius: 4px;
            padding: 2px 7px;
            font-size: 0.82em;
            margin: 1px;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }

        .ref-wwff { border-color: #3fb950; color: #3fb950; }
        .ref-pota { border-color: #79c0ff; color: #79c0ff; }
        .ref-sota { border-color: #f0a500; color: #f0a500; }

        @media (max-width: 600px) {
            .search-form {
                flex-direction: column;
            }
            .search-form .btn {
                width: 100%;
            }
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .search-filters .filter-row.cols-2,
            .search-filters .filter-row.cols-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <header>
        <h1>📡 SP5UAF. Parks Activations Log</h1>
        <p>This log was initially designed to store my <A HREF="https://sp5uaf.dxing.pl/2026/09/28/my-radio-holidays-in-the-klodzko-valley-summary/">2026 holidays activations</A> but then I decided upload logs from all of my activations from parks (SPFF, POTA, SOTA etc.) and make them available for searching. This small log upload/search utility was created as my own excercise in AI programming.</p>
        <p style="margin-top:10px"><a href="upload.php" style="color:#8b949e;font-size:0.85em">🔐 Operator login / upload</a></p>
    </header>

    <!-- Search error -->
    <?php if ($searchError): ?>
        <div class="alert alert-error">
            ⚠️ <?= htmlspecialchars($searchError) ?>
        </div>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="card">
        <h2>🔍 Search for QSOs</h2>
        <form method="GET" action="index.php" autocomplete="off">
            <div class="search-form">
                <div class="form-group">
                    <label for="station_call">Callsign *</label>
                    <input
                        type="text"
                        id="station_call"
                        name="station_call"
                        placeholder="e.g. SP5UAF"
                        maxlength="20"
                        autocomplete="off"
                        value="<?= htmlspecialchars($searchCall !== '' ? $searchCall : ($_GET['station_call'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>
                <button type="submit" class="btn btn-primary">
                    🔍 &nbsp;Search
                </button>
                <a href="index.php?latest=1" onclick="var c=document.getElementById('show_sota');if(c&&c.checked)this.href='index.php?latest=1&show_sota=1';" class="btn btn-primary" style="background:#21262d;color:#f0a500;border:1px solid #30363d">
                    🕘 &nbsp;Last 20 QSOs
                </a>
                <button type="button" class="btn btn-primary" style="background:#21262d;color:#8b949e;border:1px solid #30363d"
                    onclick="['mode','band','date_from','date_to','wwff','pota','pga'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});var sc=document.getElementById('show_sota');if(sc)sc.checked=false;">
                    🧹 &nbsp;Clear form
                </button>
            </div>
            <div class="search-filters">
                <div class="filter-row cols-2">
                <div class="form-group">
                    <label for="mode">Mode (optional)</label>
                    <select id="mode" name="mode">
                        <option value="">Any</option>
                        <?php foreach ($modeOptions as $m): ?>
                            <option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterMode !== '' && strtoupper($m) === $filterMode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="band">Band (optional)</label>
                    <select id="band" name="band">
                        <option value="">Any</option>
                        <?php foreach ($bandOptions as $b): ?>
                            <option value="<?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterBand !== '' && strtolower($b) === $filterBand ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                </div>
                <div class="filter-row cols-2">
                <div class="form-group">
                    <label for="date_from">Date from (optional)</label>
                    <input type="date" id="date_from" name="date_from"
                        value="<?= htmlspecialchars($filterDateFrom, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="date_to">Date to (optional)</label>
                    <input type="date" id="date_to" name="date_to"
                        value="<?= htmlspecialchars($filterDateTo, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                </div>
                <div class="filter-row cols-3">
                <div class="form-group">
                    <label for="wwff">SPFF / WWFF ref (optional)</label>
                    <input type="text" id="wwff" name="wwff" placeholder="e.g. SPFF-1234"
                        maxlength="20" autocomplete="off"
                        value="<?= htmlspecialchars($filterWWFF, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="pota">POTA ref (optional)</label>
                    <input type="text" id="pota" name="pota" placeholder="e.g. PL-0123"
                        maxlength="20" autocomplete="off"
                        value="<?= htmlspecialchars($filterPOTA, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="pga">PGA ref (optional)</label>
                    <input type="text" id="pga" name="pga" placeholder="e.g. NT08"
                        maxlength="10" autocomplete="off"
                        value="<?= htmlspecialchars($filterPGA, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                </div>
                <div class="form-group" style="margin-top:4px">
                    <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:normal;font-size:0.9em;cursor:pointer">
                        <input type="checkbox" id="show_sota" name="show_sota" value="1"
                            <?= $showSota ? 'checked' : '' ?> style="width:auto;accent-color:#f0a500">
                        Show SOTA in Results
                    </label>
                </div>
            </div>
        </form>
    </div>

    <?php if ($searchDone): ?>

        <?php if ($totalQSOs === 0): ?>

            <!-- No results -->
            <div class="card">
                <div class="no-results">
                    <span>🔭</span>
                    <?php if (!empty($isLatest)): ?>
                        No QSOs in the log yet.
                    <?php else: ?>
                        No QSOs found for <strong><?= htmlspecialchars($searchCall) ?></strong>.
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <!-- Results Table -->
            <div class="card">
                <?php if (!empty($isLatest)): ?>
                    <h2>🕘 Last 20 QSOs (by date)</h2>
                <?php else: ?>
                    <h2>📋 Results for <?= htmlspecialchars($searchCall) ?></h2>
                <?php endif; ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>CALL</th>
                                <th>Date</th>
                                <th>Band</th>
                                <th>Mode</th>
                                <th>WWFF</th>
                                <th>POTA</th>
                                <?php if (!empty($showSota)): ?>
                                <th>SOTA</th>
                                <?php endif; ?>
                                <?php if (!empty($hasVoivCol)): ?>
                                <th>V</th>
                                <?php endif; ?>
                                <?php if (!empty($hasPgaCol)): ?>
                                <th>PGA</th>
                                <?php endif; ?>
                                <th>GRID</th>
                                <th>PARK CALL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($row['station_call'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php
                                        $d = $row['qso_date'] ?? '';
                                        $t = trim($row['time_on'] ?? '');
                                        if (!empty($d)) {
                                            echo date('d-M-Y', strtotime($d));
                                            if (preg_match('/^\d{2}:\d{2}/', $t)) {
                                                echo ' ' . htmlspecialchars(substr($t, 0, 5), ENT_QUOTES, 'UTF-8');
                                            }
                                        } else {
                                            echo '<span class="empty-cell">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?= !empty($row['band'])
                                            ? htmlspecialchars($row['band'])
                                            : '<span class="empty-cell">-</span>' ?>
                                    </td>
                                    <td>
                                        <?= !empty($row['mode'])
                                            ? htmlspecialchars($row['mode'])
                                            : '<span class="empty-cell">-</span>' ?>
                                    </td>

                                    <!-- WWFF -->
                                    <td>
                                        <?php
                                        $wwff = trim($row['my_wwff_ref'] ?? '');
                                        if (!empty($wwff)): ?>
                                            <span class="ref-badge ref-wwff">
                                                <?= htmlspecialchars($wwff) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="empty-cell">-</span>
                                        <?php endif; ?>
                                    </td>

<!-- POTA - may contain multiple comma-separated refs -->
<td>
    <?php
    $pota = trim($row['my_pota_ref'] ?? '');
    if (!empty($pota)):
        $potaParts = explode(',', $pota);
        $potaChunks = array_chunk($potaParts, 2); // Split into groups of 2
        foreach ($potaChunks as $chunkIndex => $chunk):
            foreach ($chunk as $p):
                $p = trim($p);
                if (!empty($p)): ?>
                    <span class="ref-badge ref-pota">
                        <?= htmlspecialchars($p) ?>
                    </span>
                <?php endif;
            endforeach;
            // Add line break after every 2 references
            // but not after the very last group
            if ($chunkIndex < count($potaChunks) - 1): ?>
                <br>
            <?php endif;
        endforeach;
    else: ?>
        <span class="empty-cell">-</span>
    <?php endif; ?>
</td>

                                    <?php if (!empty($showSota)): ?>
                                    <!-- SOTA -->
                                    <td>
                                        <?php
                                        $sota = trim($row['my_sota_ref'] ?? '');
                                        if (!empty($sota)): ?>
                                            <span class="ref-badge ref-sota">
                                                <?= htmlspecialchars($sota) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="empty-cell">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (!empty($hasVoivCol)): ?>
                                    <!-- Voivodeship -->
                                    <td>
                                        <?= !empty(trim($row['my_voivodeship_ref'] ?? ''))
                                            ? htmlspecialchars(trim($row['my_voivodeship_ref']), ENT_QUOTES, 'UTF-8')
                                            : '<span class="empty-cell">-</span>' ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (!empty($hasPgaCol)): ?>
                                    <!-- PGA -->
                                    <td>
                                        <?php
                                        $pga = trim($row['my_pga_ref'] ?? '');
                                        if (!empty($pga)): ?>
                                            <span class="ref-badge ref-sota">
                                                <?= htmlspecialchars($pga, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="empty-cell">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <!-- GRID (my_gridsquare) -->
                                    <td>
                                        <?= !empty(trim($row['my_gridsquare'] ?? ''))
                                            ? htmlspecialchars(trim($row['my_gridsquare']), ENT_QUOTES, 'UTF-8')
                                            : '<span class="empty-cell">-</span>' ?>
                                    </td>

                                    <!-- PARK CALL (station_callsign used during activation) -->
                                    <td>
                                        <?= !empty(trim($row['station_callsign'] ?? ''))
                                            ? '<strong>' . htmlspecialchars(trim($row['station_callsign']), ENT_QUOTES, 'UTF-8') . '</strong>'
                                            : '<span class="empty-cell">-</span>' ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="card">
                <h2>📊 Summary</h2>
                <div class="summary-grid">

                    <div class="summary-box">
                        <div class="summary-number color-total">
                            <?= $totalQSOs ?>
                        </div>
                        <div class="summary-label">Number of QSOs</div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-number" style="color:#3fb950">
                            <?= $wwffCount ?>
                        </div>
                        <div class="summary-label">WWFF References Worked</div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-number" style="color:#79c0ff">
                            <?= $potaCount ?>
                        </div>
                        <div class="summary-label">POTA References Worked</div>
                    </div>

                    <?php if (!empty($showSota)): ?>
                    <div class="summary-box">
                        <div class="summary-number" style="color:#f0a500">
                            <?= $sotaCount ?>
                        </div>
                        <div class="summary-label">SOTA References Worked</div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

    <!-- Whole-log statistics (always shown) -->
    <?php if ($logStats !== null && $logStats['total'] > 0): ?>
        <div class="card">
            <h2>📈 Log Statistics</h2>
            <p style="color:#8b949e;font-size:0.9em;margin-bottom:12px;line-height:1.7">
                First QSO:
                <strong style="color:#c9d1d9"><?= htmlspecialchars(
                    date('d-M-Y', strtotime($logStats['first']['qso_date']))
                    . (!empty($logStats['first']['time_on']) ? ' ' . substr($logStats['first']['time_on'], 0, 5) . ' UTC' : ''),
                    ENT_QUOTES, 'UTF-8') ?></strong>
                &nbsp;•&nbsp; Last QSO:
                <strong style="color:#c9d1d9"><?= htmlspecialchars(
                    date('d-M-Y', strtotime($logStats['last']['qso_date']))
                    . (!empty($logStats['last']['time_on']) ? ' ' . substr($logStats['last']['time_on'], 0, 5) . ' UTC' : ''),
                    ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
            <div class="summary-grid" style="grid-template-columns:repeat(4,1fr)">
                <div class="summary-box">
                    <div class="summary-number color-total"><?= $logStats['total'] ?></div>
                    <div class="summary-label">QSOs in log</div>
                </div>
                <div class="summary-box">
                    <div class="summary-number" style="color:#79c0ff"><?= $logStats['calls'] ?></div>
                    <div class="summary-label">Unique callsigns</div>
                </div>
                <div class="summary-box">
                    <div class="summary-number" style="color:#3fb950"><?= $logStats['wwff'] ?></div>
                    <div class="summary-label">WWFF refs</div>
                </div>
                <div class="summary-box">
                    <div class="summary-number" style="color:#79c0ff"><?= $logStats['pota'] ?></div>
                    <div class="summary-label">POTA refs</div>
                </div>
                <div class="summary-box">
                    <div class="summary-number" style="color:#f0a500"><?= $logStats['sota'] ?></div>
                    <div class="summary-label">SOTA refs</div>
                </div>
                <?php if ($hasPgaCol): ?>
                <div class="summary-box">
                    <div class="summary-number" style="color:#f0a500"><?= $logStats['pga'] ?></div>
                    <div class="summary-label">PGA refs</div>
                </div>
                <?php endif; ?>
                <div class="summary-box">
                    <div class="summary-number" style="color:#c9d1d9"><?= $logStats['grids'] ?></div>
                    <div class="summary-label">Gridsquares</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div><!-- /.container -->

</body>
</html>
