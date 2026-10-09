<?php
declare(strict_types=1);

// Load secrets from environment, never hardcode.
// Copy .env.example to .env (outside web root) — never commit .env.
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] ??= trim($v, " \t\"'");
    }
}

function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_USER',    env('DB_USER'));
define('DB_PASS',    env('DB_PASS'));
define('DB_NAME',    env('DB_NAME'));
define('DB_CHARSET', 'utf8mb4');

define('MAX_FILE_SIZE', 50 * 1024 * 1024);
define('UPLOAD_DIR',    __DIR__ . '/uploads/');

define('MY_CALLSIGN',   env('MY_CALLSIGN', 'SP5UAF'));
// Store bcrypt hash in env MY_PASS_HASH (password_hash($pw, PASSWORD_DEFAULT))
define('MY_PASS_HASH',  env('MY_PASS_HASH'));

// ============================================================
// System Version (shown in upload.php Tips)
// ============================================================
define('SYSTEM_VERSION', '00.13 20261009');

if (DB_USER === '' || DB_NAME === '' || MY_PASS_HASH === '') {
    http_response_code(500);
    error_log('Missing required env: DB_USER, DB_NAME, MY_PASS_HASH');
    exit('Server misconfigured.');
}
