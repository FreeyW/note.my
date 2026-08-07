<?php

declare(strict_types=1);

/**
 * Black-box HTTP tests. Requires the app served at $base and a reachable
 * MariaDB/Redis, i.e. the real request cycle rather than direct class calls.
 */

$base = getenv('NOTEMY_BASE') ?: 'http://127.0.0.1:8080';
$origin = $base;

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32mPASS\033[0m  {$name}\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** @return array{status:int,body:string,headers:string} */
function http(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$config = require __DIR__ . '/../config/config.php';
$pdo = new PDO(
    "mysql:unix_socket={$config['db']['socket']};dbname={$config['db']['dbname']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('TRUNCATE TABLE notes');
$pdo->exec('TRUNCATE TABLE abuse_reports');

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushDB();

$H = ["Origin: {$origin}"];

echo "\n--- 1. Create and read over HTTP ---\n";

$secret = random_bytes(400) . "\x00\xFF";
$r = http('POST', "{$base}/api/note", ['payload' => b64u($secret), 'ttl' => '1h'], $H);
$id = json_decode($r['body'], true)['id'] ?? '';
ok('create returns 201 with an ID', $r['status'] === 201 && strlen($id) === 22, "{$r['status']} {$r['body']}");
ok('create response is no-store JSON',
    stripos($r['headers'], 'Cache-Control: no-store') !== false
    && stripos($r['headers'], 'Content-Type: application/json') !== false);

$r = http('POST', "{$base}/api/note/{$id}", null, $H);
$got = json_decode($r['body'], true)['payload'] ?? '';
ok('read returns the exact ciphertext',
    $r['status'] === 200 && base64_decode(strtr($got, '-_', '+/')) === $secret);

echo "\n--- 2. GET /n/{id} must not touch the database ---\n";

$id2 = json_decode(http('POST', "{$base}/api/note", ['payload' => b64u('prefetch-me'), 'ttl' => '1h'], $H)['body'], true)['id'];

$before = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch(PDO::FETCH_ASSOC)['Value'];
$comBefore = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Com_delete'")->fetch(PDO::FETCH_ASSOC)['Value'];

// Simulate every link unfurler that will ever see this URL.
for ($i = 0; $i < 25; $i++) {
    $shell = http('GET', "{$base}/n/{$id2}");
}
$after = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch(PDO::FETCH_ASSOC)['Value'];
$comAfter = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Com_delete'")->fetch(PDO::FETCH_ASSOC)['Value'];

// The two SHOW STATUS queries above each use this script's existing
// connection, so the counter should move by zero.
ok('25 prefetches opened zero database connections', $after - $before === 0, "delta " . ($after - $before));
ok('25 prefetches issued zero DELETEs', $comAfter - $comBefore === 0, "delta " . ($comAfter - $comBefore));
ok('read shell is 200 with noindex',
    $shell['status'] === 200
    && str_contains($shell['body'], 'noindex')
    && stripos($shell['headers'], 'X-Robots-Tag: noindex') !== false);
ok('read shell contains a noscript notice', str_contains($shell['body'], '<noscript>'));
ok('note survived all 25 prefetches',
    (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 1);

$r = http('POST', "{$base}/api/note/{$id2}", null, $H);
ok('note still readable after prefetching', $r['status'] === 200);

echo "\n--- 3. Miss responses are byte-identical ---\n";

$alreadyRead = http('POST', "{$base}/api/note/{$id2}", null, $H);
$neverExisted = http('POST', "{$base}/api/note/" . b64u(random_bytes(16)), null, $H);
$malformed = http('POST', "{$base}/api/note/zzz", null, $H);

$idExp = json_decode(http('POST', "{$base}/api/note", ['payload' => b64u('exp'), 'ttl' => '1h'], $H)['body'], true)['id'];
$pdo->prepare('UPDATE notes SET expires_at = NOW() - INTERVAL 1 SECOND WHERE id_hash = ?')
    ->execute([hash('sha256', $idExp)]);
$expired = http('POST', "{$base}/api/note/{$idExp}", null, $H);

ok('already-read == never-existed',
    $alreadyRead['status'] === $neverExisted['status'] && $alreadyRead['body'] === $neverExisted['body']);
ok('never-existed == expired',
    $neverExisted['status'] === $expired['status'] && $neverExisted['body'] === $expired['body']);
ok('expired == malformed',
    $expired['status'] === $malformed['status'] && $expired['body'] === $malformed['body']);
ok('all four are 404 with no distinguishing copy',
    $alreadyRead['status'] === 404 && $alreadyRead['body'] === '{"error":"not_found"}', $alreadyRead['body']);

echo "\n--- 4. Same-origin enforcement ---\n";

$noOrigin = http('POST', "{$base}/api/note", ['payload' => b64u('x'), 'ttl' => '1h']);
$badOrigin = http('POST', "{$base}/api/note", ['payload' => b64u('x'), 'ttl' => '1h'], ['Origin: https://evil.example']);
$refererOk = http('POST', "{$base}/api/note", ['payload' => b64u('x'), 'ttl' => '1h'], ["Referer: {$origin}/n/abc"]);
ok('write with no Origin/Referer is refused', $noOrigin['status'] === 403, (string) $noOrigin['status']);
ok('write with foreign Origin is refused', $badOrigin['status'] === 403, (string) $badOrigin['status']);
ok('same-origin Referer is accepted', $refererOk['status'] === 201, (string) $refererOk['status']);

echo "\n--- 5. Input limits ---\n";

$big = http('POST', "{$base}/api/note", ['payload' => b64u(random_bytes(40000)), 'ttl' => '1h'], $H);
ok('oversize payload returns 413', $big['status'] === 413, (string) $big['status']);

$badTtl = http('POST', "{$base}/api/note", ['payload' => b64u('x'), 'ttl' => '99y'], $H);
ok('non-whitelisted TTL returns 400', $badTtl['status'] === 400, (string) $badTtl['status']);

$badJson = http('POST', "{$base}/api/note", null, $H);
ok('empty body returns 400', $badJson['status'] === 400, (string) $badJson['status']);

$wrongMethod = http('GET', "{$base}/api/note");
ok('GET on a POST-only route returns 405', $wrongMethod['status'] === 405, (string) $wrongMethod['status']);

echo "\n--- 6. Rate limiting ---\n";

$redis->flushDB();
$statuses = [];
for ($i = 0; $i < 13; $i++) {
    $statuses[] = http('POST', "{$base}/api/note", ['payload' => b64u('rl'), 'ttl' => '1h'], $H)['status'];
}
$created = count(array_filter($statuses, static fn($s) => $s === 201));
$limited = count(array_filter($statuses, static fn($s) => $s === 429));
ok('create limit is 10/min', $created === 10 && $limited === 3, implode(',', $statuses));

$redis->flushDB();
$missStatuses = [];
for ($i = 0; $i < 33; $i++) {
    $missStatuses[] = http('POST', "{$base}/api/note/" . b64u(random_bytes(16)), null, $H)['status'];
}
ok('misses count toward the read limit (enumeration is throttled)',
    count(array_filter($missStatuses, static fn($s) => $s === 429)) === 3,
    implode(',', array_unique($missStatuses)));

echo "\n--- 7. Live-note quota ---\n";

$redis->flushDB();
$ipHash = substr(hash_hmac('sha256', inet_pton('127.0.0.1') & "\xff\xff\xff\x00", $config['ip_hash_secret']), 0, 32);
$redis->del("live:{$ipHash}");
for ($i = 0; $i < 50; $i++) {
    $redis->zAdd("live:{$ipHash}", time() + 3600, bin2hex(random_bytes(8)));
}
$redis->del("rl:create:{$ipHash}");
$quota = http('POST', "{$base}/api/note", ['payload' => b64u('over'), 'ttl' => '1h'], $H);
ok('51st live note is refused', $quota['status'] === 429 && str_contains($quota['body'], 'quota_exceeded'),
    "{$quota['status']} {$quota['body']}");

// Entries scored in the past must age out rather than block forever.
$redis->del("live:{$ipHash}");
for ($i = 0; $i < 50; $i++) {
    $redis->zAdd("live:{$ipHash}", time() - 10, bin2hex(random_bytes(8)));
}
$redis->del("rl:create:{$ipHash}");
$afterExpiry = http('POST', "{$base}/api/note", ['payload' => b64u('ok'), 'ttl' => '1h'], $H);
ok('expired quota entries are reclaimed', $afterExpiry['status'] === 201, (string) $afterExpiry['status']);

echo "\n--- 8. Abuse reports store only hashes ---\n";

$idR = json_decode(http('POST', "{$base}/api/note", ['payload' => b64u('reported'), 'ttl' => '1h'], $H)['body'], true)['id'];
$redis->del("rl:report:{$ipHash}");
$rep = http('POST', "{$base}/api/report", ['id' => $idR, 'reason' => 'phishing'], $H);
$row = $pdo->query('SELECT note_id_hash, reason FROM abuse_reports ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok('report accepted', $rep['status'] === 202, (string) $rep['status']);
ok('report stores sha256(id), not the ID',
    $row['note_id_hash'] === hash('sha256', $idR) && !str_contains($row['note_id_hash'], $idR));

$redis->del("rl:report:{$ipHash}");
$repGhost = http('POST', "{$base}/api/report", ['id' => b64u(random_bytes(16)), 'reason' => 'spam'], $H);
ok('reporting a nonexistent note looks identical (no existence oracle)',
    $repGhost['status'] === $rep['status'] && $repGhost['body'] === $rep['body']);

echo "\n" . str_repeat('=', 52) . "\n";
echo ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
