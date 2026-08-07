<?php

declare(strict_types=1);

/**
 * Deployment self-check.
 *
 *   php scripts/doctor.php
 *   sudo -u www-data php scripts/doctor.php     # the one that matters
 *
 * Checks everything the app needs, and reports the actual driver error rather
 * than the deliberately terse line that reaches the error log at runtime.
 *
 * Run it as the web server user. A good half of "works from the shell, 500s in
 * the browser" is a file the CLI user can read and www-data cannot.
 *
 * Reads configuration and opens connections. The only write is a probe row in
 * `notes` that is deleted by the same statement that reads it back, so nothing
 * survives this script. Safe against production.
 */

use NoteMy\Store\Database;

require __DIR__ . '/../src/Store/Database.php';

$root = dirname(__DIR__);
$problems = 0;
$warnings = 0;

function ok(string $msg): void
{
    echo "  \033[32m✓\033[0m {$msg}\n";
}

function bad(string $msg, string $fix = ''): void
{
    global $problems;
    $problems++;
    echo "  \033[31m✗\033[0m {$msg}\n";
    if ($fix !== '') {
        echo "     \033[2m→ {$fix}\033[0m\n";
    }
}

function warn(string $msg, string $fix = ''): void
{
    global $warnings;
    $warnings++;
    echo "  \033[33m!\033[0m {$msg}\n";
    if ($fix !== '') {
        echo "     \033[2m→ {$fix}\033[0m\n";
    }
}

$user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : get_current_user();

echo "\nnote.my doctor — running as {$user}, PHP " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo str_repeat('=', 62) . "\n";

// ------------------------------------------------------------------ PHP
echo "\nPHP\n";

PHP_VERSION_ID >= 80300
    ? ok('version is 8.3 or newer')
    : bad('PHP ' . PHP_VERSION . ' is too old', 'note.my needs 8.3+');

if (extension_loaded('pdo_mysql')) {
    ok('pdo_mysql loaded');
} else {
    bad(
        'pdo_mysql is NOT loaded',
        'apt install php8.3-mysql && systemctl restart php8.3-fpm — installing it only makes the CLI see it; FPM needs the restart',
    );
}

extension_loaded('redis')
    ? ok('redis loaded')
    : warn('redis extension missing', 'rate limiting will fail open: apt install php8.3-redis');

// ----------------------------------------------------------------- files
echo "\nFiles\n";

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    bad('config/config.php not found', 'cp config/config.php.example config/config.php');
    echo "\nCannot continue without it.\n\n";
    exit(1);
}
if (!is_readable($configPath)) {
    bad(
        "config/config.php exists but {$user} cannot read it",
        'chown root:www-data config/config.php && chmod 640 config/config.php',
    );
    echo "\nCannot continue.\n\n";
    exit(1);
}
ok('config/config.php is readable');

$config = require $configPath;

$manifestPath = $root . '/public/assets/manifest.json';
$manifest = is_file($manifestPath) && is_readable($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;

if (is_array($manifest) && isset($manifest['js'], $manifest['css'])) {
    ok('assets/manifest.json is present');
    foreach (['js', 'css'] as $kind) {
        is_file($root . '/public/assets/' . $manifest[$kind])
            ? ok("bundle {$manifest[$kind]} exists")
            : bad("bundle {$manifest[$kind]} is missing", './scripts/build.sh');
    }
} else {
    bad('assets/manifest.json missing or malformed', './scripts/build.sh');
}

count(glob($root . '/public/assets/fonts/*.woff2') ?: []) >= 2
    ? ok('self-hosted fonts present')
    : warn(
        'no .woff2 in public/assets/fonts — the page 404s on fonts and falls back to system faces',
        'harmless; see frontend/fonts/README.md, then re-run scripts/build.sh',
    );

$lockDir = dirname((string) ($config['purge_lock'] ?? '/var/lock/notemy-purge.lock'));
is_dir($lockDir) && is_writable($lockDir)
    ? ok("purge lock directory {$lockDir} is writable")
    : warn("cannot write to {$lockDir}", 'purge-expired.php will exit 1 on every cron run');

// -------------------------------------------------------------- database
echo "\nDatabase\n";

$db = $config['db'] ?? [];
$where = isset($db['socket'])
    ? "socket {$db['socket']}"
    : ($db['host'] ?? '127.0.0.1') . ':' . ($db['port'] ?? 3306);

if (isset($db['socket']) && !file_exists($db['socket'])) {
    bad(
        "configured socket does not exist: {$db['socket']}",
        'find the real one: mariadb -e "SHOW VARIABLES LIKE \'socket\'" — usually /run/mysqld/mysqld.sock on Debian/Ubuntu',
    );
}

$pdo = null;
try {
    $pdo = Database::connect($db);
    ok("connected to '{$db['dbname']}' via {$where}");
} catch (PDOException $e) {
    // The driver message is the useful part and carries no note data: PDO
    // reports SQLSTATE and the server error, never bound parameters.
    $m = $e->getMessage();
    bad('cannot connect: ' . $m, match (true) {
        str_contains($m, '2002') => 'wrong socket path, or MariaDB is not running',
        str_contains($m, '1045') => 'wrong user or password in config.php',
        str_contains($m, '1049') => "database does not exist: CREATE DATABASE {$db['dbname']};",
        str_contains($m, '1044') => "no grant: GRANT ALL ON {$db['dbname']}.* TO '{$db['user']}'@'localhost';",
        default                  => 'check credentials and that the server is reachable from this user',
    });
} catch (Throwable $e) {
    bad($e->getMessage(), 'note.my requires MariaDB 10.5+; MySQL has no DELETE ... RETURNING');
}

if ($pdo instanceof PDO) {
    ok('server reports ' . $pdo->query('SELECT VERSION()')->fetchColumn());

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['notes', 'daily_stats', 'abuse_reports'] as $table) {
        in_array($table, $tables, true)
            ? ok("table {$table}")
            : bad("table {$table} is missing", "mariadb {$db['dbname']} < config/schema.sql");
    }

    if (in_array('notes', $tables, true)) {
        $columns = $pdo->query('SHOW COLUMNS FROM notes')->fetchAll(PDO::FETCH_COLUMN);
        $columns === ['id_hash', 'payload', 'expires_at', 'created_at']
            ? ok('notes has exactly the four sanctioned columns')
            : bad('notes columns are wrong: ' . implode(', ', $columns), 'reload config/schema.sql');

        // Exercise the exact path that 500s, end to end.
        try {
            $probe = hash('sha256', 'doctor-' . bin2hex(random_bytes(8)));
            $blob = "\x00\xff probe \x00";

            $ins = $pdo->prepare(
                'INSERT INTO notes (id_hash, payload, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 MINUTE)'
            );
            $ins->bindValue(1, $probe, PDO::PARAM_STR);
            $ins->bindValue(2, $blob, PDO::PARAM_STR);
            $ins->execute();
            ok('INSERT works');

            $del = $pdo->prepare('DELETE FROM notes WHERE id_hash = ? AND expires_at > NOW() RETURNING payload');
            $del->execute([$probe]);
            $row = $del->fetch(PDO::FETCH_ASSOC);
            $del->closeCursor();

            $row !== false && $row['payload'] === $blob
                ? ok('DELETE ... RETURNING works and is binary-safe (probe row removed)')
                : bad('DELETE ... RETURNING returned nothing or corrupted bytes');

            $pdo->query('SELECT 1')->fetchColumn();
            ok('connection still healthy after RETURNING');
        } catch (Throwable $e) {
            bad('round trip failed: ' . $e->getMessage(), 'the user needs INSERT and DELETE, not just SELECT');
        }
    }

    // Settings that do not break anything but undermine the point.
    $logBin = $pdo->query("SHOW VARIABLES LIKE 'log_bin'")->fetch(PDO::FETCH_ASSOC);
    if (($logBin['Value'] ?? 'OFF') === 'ON') {
        $expire = (int) ($pdo->query("SHOW VARIABLES LIKE 'binlog_expire_logs_seconds'")
            ->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
        $expire > 0 && $expire <= 86400
            ? warn("binlog is ON, expiring after {$expire}s", 'deleted ciphertext is retained for that window')
            : bad('binlog is ON with no short expiry', 'this retains deleted ciphertext indefinitely; see config/my.cnf.example');
    } else {
        ok('binlog is OFF');
    }

    foreach (['general_log', 'slow_query_log'] as $log) {
        ($pdo->query("SHOW VARIABLES LIKE '{$log}'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? 'OFF') === 'OFF'
            ? ok("{$log} is off")
            : bad("{$log} is ON — SQL with ciphertext is being written to disk", 'see config/my.cnf.example');
    }
}

// ----------------------------------------------------------------- redis
echo "\nRedis\n";

if (!extension_loaded('redis')) {
    warn('skipped, extension not loaded');
} else {
    try {
        $rc = $config['redis'] ?? [];
        $redis = new Redis();
        $connected = isset($rc['socket'])
            ? $redis->connect($rc['socket'])
            : $redis->connect($rc['host'] ?? '127.0.0.1', $rc['port'] ?? 6379, $rc['timeout'] ?? 0.2);

        if ($connected !== true) {
            throw new RedisException('connect returned false');
        }
        ok('connected');

        $policy = $redis->config('GET', 'maxmemory-policy')['maxmemory-policy'] ?? '?';
        $policy === 'volatile-lru'
            ? ok("maxmemory-policy is {$policy}")
            : warn("maxmemory-policy is {$policy}", 'volatile-lru recommended: evicting a live:* set hands a network unlimited fresh quota');
    } catch (Throwable $e) {
        warn(
            'not reachable: ' . $e->getMessage(),
            'the app fails open rather than erroring, so this cannot cause a 500 — but you have no rate limiting',
        );
    }
}

// --------------------------------------------------------- configuration
echo "\nConfiguration\n";

$secret = (string) ($config['ip_hash_secret'] ?? '');
if ($secret === '' || strlen($secret) < 32 || str_contains($secret, 'dev-secret')) {
    bad('ip_hash_secret is empty or a placeholder', "php -r 'echo bin2hex(random_bytes(32));'");
} else {
    ok('ip_hash_secret is set');
}

$origin = (string) ($config['canonical_origin'] ?? '');
if ($origin === '' || !str_starts_with($origin, 'http')) {
    bad('canonical_origin is not set', "e.g. 'https://note.my', no trailing slash");
} elseif (str_ends_with($origin, '/')) {
    bad(
        'canonical_origin has a trailing slash',
        'the same-origin check compares exactly — this rejects every write with 403',
    );
} elseif (str_starts_with($origin, 'http://') && !preg_match('#://(127\.0\.0\.1|localhost)#', $origin)) {
    warn(
        "canonical_origin is plain http: {$origin}",
        'crypto.subtle is unavailable outside a secure context, so encryption cannot run off localhost',
    );
} else {
    ok("canonical_origin is {$origin}");
}

// --------------------------------------------------------------- summary
echo "\n" . str_repeat('=', 62) . "\n";

if ($problems === 0) {
    echo $warnings === 0
        ? "\033[32mEverything checks out.\033[0m\n\n"
        : "\033[33m{$warnings} warning(s), nothing blocking.\033[0m\n\n";

    echo "If creating a note still fails, read the status on POST /api/note in\n";
    echo "the browser's Network tab:\n\n";
    echo "  403  the browser's Origin does not match canonical_origin above\n";
    echo "  413  payload over 32 KB\n";
    echo "  429  rate limited, or this network's 50 unread notes are used up\n";
    echo "  500  grep the PHP-FPM error log for a line beginning 'notemy:'\n\n";
    exit(0);
}

echo "\033[31m{$problems} problem(s), {$warnings} warning(s).\033[0m Fix the ✗ lines above.\n\n";
exit(1);
