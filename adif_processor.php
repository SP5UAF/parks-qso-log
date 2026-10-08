<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

final class ADIFProcessor
{
    private mysqli $db;
    private bool $hasUploadId = false;
    private bool $hasPgaRef = false;
    private bool $hasVoivodeship = false;
    private array $stats = ['total' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [],
        'skipped_no_call' => 0, 'skipped_no_date' => 0, 'skipped_bad_date' => 0, 'skipped_dup' => 0];

    public function __construct()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $this->db->set_charset(DB_CHARSET);
        // Detect which optional columns exist (migrations may be applied independently)
        foreach (['upload_id' => 'hasUploadId', 'my_pga_ref' => 'hasPgaRef',
                  'my_voivodeship_ref' => 'hasVoivodeship'] as $col => $prop) {
            try {
                $r = $this->db->query("SHOW COLUMNS FROM qso_log LIKE '$col'");
                if ($r && $r->num_rows > 0) $this->$prop = true;
                if ($r) $r->free();
            } catch (mysqli_sql_exception $e) { /* assume column missing */ }
        }
    }

    public function registerUpload(string $originalFilename, ?string $comments): int
    {
        $stmt = $this->db->prepare('INSERT INTO uploads (filename, comments) VALUES (?, ?)');
        $name = mb_substr(trim($originalFilename), 0, 255);
        $stmt->bind_param('ss', $name, $comments);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function finishUpload(int $uploadId, array $stats): void
    {
        $stmt = $this->db->prepare(
            'UPDATE uploads SET qsos_found = ?, qsos_inserted = ?, qsos_skipped = ?, qsos_errors = ?
             WHERE upload_id = ?'
        );
        $stmt->bind_param('iiiii', $stats['total'], $stats['inserted'], $stats['skipped'], $stats['errors'], $uploadId);
        $stmt->execute();
        $stmt->close();
    }

    // $overrides: station values from the upload form that take precedence
    // over ADIF fields. Keys: rig, antenna, wwff, pota, sota, pga, grid,
    // voivodeship. Missing/null = keep ADIF value (null for voivodeship).
    public function processFile(string $filePath, ?int $uploadId = null, array $overrides = []): array
    {
        if (!is_file($filePath)) {
            $this->addMessage('ERROR: File not found.');
            return $this->stats;
        }
        // Stream record-by-record instead of loading 50MB into memory.
        // NOTE: do-while, not while(!feof): for files smaller than one chunk
        // the loop body must still run to split the buffered content at <EOR>.
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->addMessage('ERROR: Cannot read file.');
            return $this->stats;
        }
        // Prepare insert + dup-check once
        $insert = $this->db->prepare($this->insertSQL());
        $dup = $this->db->prepare(
            'SELECT 1 FROM qso_log WHERE station_call <=> ? AND qso_date <=> ?
             AND time_on <=> ? AND band <=> ? AND mode <=> ? LIMIT 1'
        );
        $count = 0;
        $content = '';
        $headerStripped = false;
        do {
            $chunk = fread($handle, 256 * 1024);
            $content .= $chunk === false ? '' : $chunk;
            if (!$headerStripped) {
                $eoh = stripos($content, '<eoh>');
                if ($eoh !== false) {
                    $content = substr($content, $eoh + 5);
                    $headerStripped = true;
                    $this->addMessage('ADIF header removed.');
                } elseif (feof($handle) || strlen($content) >= 64 * 1024) {
                    // No header in file (or absurdly long header): process from start
                    $headerStripped = true;
                    $this->addMessage('No ADIF header - processing from file start.');
                } else {
                    continue; // header may be split across chunks: buffer more
                }
            }
            while (($pos = stripos($content, '<eor>')) !== false) {
                $record = substr($content, 0, $pos);
                $content = substr($content, $pos + 5);
                $this->parseRecord($record, $insert, $dup, ++$count, $uploadId, $overrides);
            }
        } while (!feof($handle));
        // Trailing record without <EOR>
        if (trim($content) !== '') {
            $this->parseRecord($content, $insert, $dup, ++$count, $uploadId, $overrides);
        }
        fclose($handle);
        $insert->close();
        $dup->close();
        $this->stats['total'] = $count;
        $this->addMessage("Found {$count} QSO records.");
        foreach (['skipped_no_call' => 'no callsign', 'skipped_no_date' => 'no date',
                  'skipped_bad_date' => 'bad date', 'skipped_dup' => 'duplicates'] as $k => $label) {
            if (!empty($this->stats[$k])) $this->addMessage("Skipped {$this->stats[$k]} ($label).");
        }
        return $this->stats;
    }

    private function parseRecord(string $raw, mysqli_stmt $insert, mysqli_stmt $dup, int $n, ?int $uploadId, array $overrides): void
    {
        $raw = trim($raw);
        if ($raw === '') return;
        $fields = [];
        // <NAME:LEN[:TYPE]>value — use byte-accurate substr on raw bytes
        if (preg_match_all('/<([A-Za-z0-9_]+):(\d+)(?::[A-Za-z])?>([^<]*)/s', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $len = (int)$match[2];
                $fields[$name === 'call' ? 'station_call' : $name] = trim(substr($match[3], 0, $len));
            }
        }
        if (!isset($fields['adif_raw'])) $fields['adif_raw'] = substr($raw, 0, 5000);
        $this->insertRecord($fields, $n, $insert, $dup, $uploadId, $overrides);
    }

    private function formatDate(string $d): ?string
    {
        $d = trim($d);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return null;
    }

    private function formatTime(string $t): ?string
    {
        $t = trim($t);
        if (preg_match('/^(\d{2})(\d{2})(\d{2})?$/', $t, $m)) {
            $h = (int)$m[1]; $i = (int)$m[2]; $s = isset($m[3]) ? (int)$m[3] : 0;
            if ($h < 24 && $i < 60 && $s < 60) return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // PGA ref: look for "PGA XXXX" (4-char code) inside QSLMSG, e.g. "PGA NT08"
    // -------------------------------------------------------------------------
    private function extractPgaRef(?string $qslmsg): ?string
    {
        if ($qslmsg === null || $qslmsg === '') return null;
        if (preg_match('/\bPGA[\s\-:]*([A-Za-z0-9]{4})\b/', $qslmsg, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function clean(?string $v, int $max): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function insertRecord(array $f, int $n, mysqli_stmt $insert, mysqli_stmt $dup, ?int $uploadId, array $overrides): void
    {
        if (empty($f['station_call']) || empty($f['qso_date'])) {
            $this->stats['skipped']++;
            $this->stats[empty($f['station_call']) ? 'skipped_no_call' : 'skipped_no_date']++;
            return;
        }
        $date = $this->formatDate($f['qso_date']);
        if ($date === null) { $this->stats['skipped']++; $this->stats['skipped_bad_date']++; $this->addMessage("Record #$n skipped - bad date: " . mb_substr($f['qso_date'], 0, 20)); return; }
        $timeOn = $this->formatTime($f['time_on'] ?? '');
        $band = $this->clean($f['band'] ?? null, 10);
        $mode = $this->clean($f['mode'] ?? null, 20);

        // NULL-safe duplicate check (<=> handles NULL time/band/mode)
        $call = $this->clean($f['station_call'], 20);
        $dup->bind_param('sssss', $call, $date, $timeOn, $band, $mode);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) { $this->stats['skipped']++; $this->stats['skipped_dup']++; return; }

        $vals = [
            $call, $band, $this->clean($f['freq'] ?? null, 20), $mode, $date, $timeOn,
            $this->formatTime($f['time_off'] ?? ''),
            $this->clean($f['rst_sent'] ?? null, 10), $this->clean($f['rst_rcvd'] ?? null, 10),
            $this->clean($f['tx_pwr'] ?? null, 10), $this->clean($f['gridsquare'] ?? null, 10),
            $this->clean($f['country'] ?? null, 50), $this->clean($f['dxcc'] ?? null, 10),
            $this->clean($f['state'] ?? null, 10), $this->clean($f['county'] ?? null, 50),
            $this->clean($f['cont'] ?? null, 10), $this->clean($f['cqz'] ?? null, 5),
            $this->clean($f['ituz'] ?? null, 5), $this->clean($f['pfx'] ?? null, 10),
            $this->clean($f['name'] ?? null, 100), $this->clean($f['qth'] ?? null, 100),
            $this->clean($f['comment'] ?? null, 1000), $this->clean($f['notes'] ?? null, 1000),
            $this->clean($f['qsl_sent'] ?? null, 5), $this->clean($f['qsl_rcvd'] ?? null, 5),
            $this->clean($f['qsl_sent_via'] ?? null, 10), $this->clean($f['qsl_rcvd_via'] ?? null, 10),
            $this->clean($f['lotw_qsl_sent'] ?? null, 5), $this->clean($f['lotw_qsl_rcvd'] ?? null, 5),
            $this->clean($f['eqsl_qsl_sent'] ?? null, 5), $this->clean($f['eqsl_qsl_rcvd'] ?? null, 5),
            $this->clean($f['my_call'] ?? MY_CALLSIGN, 20),
            $overrides['grid'] ?? $this->clean($f['my_gridsquare'] ?? null, 10),
            $overrides['rig'] ?? $this->clean($f['my_rig'] ?? null, 100),
            $overrides['antenna'] ?? $this->clean($f['my_antenna'] ?? null, 100),
            $this->clean($f['operator'] ?? null, 20), $this->clean($f['station_callsign'] ?? null, 20),
            $this->clean($f['contest_id'] ?? null, 50), $this->clean($f['srx'] ?? null, 20),
            $this->clean($f['stx'] ?? null, 20), $this->clean($f['prop_mode'] ?? null, 10),
            $this->clean($f['sat_name'] ?? null, 20), $this->clean($f['sat_mode'] ?? null, 20),
            $this->clean($f['iota'] ?? null, 20), $this->clean($f['sota_ref'] ?? null, 20),
            $this->clean($f['pota_ref'] ?? null, 30), $this->clean($f['wwff_ref'] ?? null, 20),
            $overrides['sota'] ?? $this->clean($f['my_sota_ref'] ?? null, 20),
            $overrides['pota'] ?? $this->clean($f['my_pota_ref'] ?? null, 50),
            $overrides['wwff'] ?? $this->clean($f['my_wwff_ref'] ?? null, 20),
            $this->clean($f['adif_raw'] ?? null, 5000),
        ];
        // Station overrides from the upload form take precedence over ADIF values
        $types = str_repeat('s', count($vals));
        if ($this->hasPgaRef) {
            $vals[] = $overrides['pga'] ?? $this->extractPgaRef($f['qslmsg'] ?? null);
            $types .= 's';
        }
        if ($this->hasVoivodeship) {
            $vals[] = $overrides['voivodeship'] ?? null;
            $types .= 's';
        }
        if ($this->hasUploadId) {
            $vals[] = $uploadId;
            $types .= 'i';
        }
        $insert->bind_param($types, ...$vals);
        try {
            $insert->execute();
            $this->stats['inserted']++;
        } catch (mysqli_sql_exception $e) {
            // 1062 = duplicate race, count as skipped not error
            if ($e->getCode() === 1062) $this->stats['skipped']++;
            else { $this->stats['errors']++; $this->addMessage("Insert failed #$n: {$e->getMessage()}"); }
        }
    }

    private function insertSQL(): string
    {
        $cols = 'station_call, band, freq, mode, qso_date, time_on, time_off,
            rst_sent, rst_rcvd, tx_pwr, gridsquare, country, dxcc, state, county, cont, cqz, ituz, pfx,
            name, qth, comment, notes, qsl_sent, qsl_rcvd, qsl_sent_via, qsl_rcvd_via,
            lotw_qsl_sent, lotw_qsl_rcvd, eqsl_qsl_sent, eqsl_qsl_rcvd,
            my_call, my_gridsquare, my_rig, my_antenna, operator, station_callsign,
            contest_id, srx, stx, prop_mode, sat_name, sat_mode, iota, sota_ref, pota_ref, wwff_ref,
            my_sota_ref, my_pota_ref, my_wwff_ref, adif_raw';
        $n = 51;
        if ($this->hasPgaRef) { $cols .= ', my_pga_ref'; $n++; }
        if ($this->hasVoivodeship) { $cols .= ', my_voivodeship_ref'; $n++; }
        if ($this->hasUploadId) { $cols .= ', upload_id'; $n++; }
        return "INSERT INTO qso_log ($cols) VALUES (" . rtrim(str_repeat('?,', $n), ',') . ')';
    }

    private function addMessage(string $msg): void { $this->stats['messages'][] = $msg; }
    public function getStats(): array { return $this->stats; }
    public function closeConnection(): void { $this->db->close(); }
}
