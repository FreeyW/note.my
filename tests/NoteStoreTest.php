<?php

declare(strict_types=1);

use NoteMy\Store\Database;
use NoteMy\Store\NoteStore;
use NoteMy\Store\StatsStore;

require __DIR__ . '/../src/Store/Database.php';
require __DIR__ . '/../src/Store/StatsStore.php';
require __DIR__ . '/../src/Store/NoteStore.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);
$store = new NoteStore($pdo, new StatsStore($pdo));

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

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$pdo->exec('TRUNCATE TABLE notes');
$pdo->exec('TRUNCATE TABLE daily_stats');

echo "\n--- 1. Round trip, binary safety ---\n";

// Payload that is hostile to any charset conversion: NULs, 0xFF, lone
// continuation bytes, and a byte sequence that is invalid UTF-8.
$raw = random_bytes(12) . "\x00\x00\xFF\xFE\x80\xC3\x28" . random_bytes(2000) . "\x00";
$id = $store->create(b64u($raw), '1h');
ok('ID is 22 chars base64url', strlen($id) === 22 && preg_match('/^[A-Za-z0-9_-]{22}$/', $id) === 1, $id);

$stored = $pdo->query('SELECT payload, id_hash FROM notes')->fetch(PDO::FETCH_ASSOC);
ok('payload stored as raw binary (33% smaller than base64)', $stored['payload'] === $raw,
    'len ' . strlen((string) $stored['payload']) . ' vs ' . strlen($raw));
ok('id_hash is sha256(id), not the ID', $stored['id_hash'] === hash('sha256', $id));
ok('the ID itself appears nowhere in the row', !str_contains(implode('', $stored), $id));

$out = $store->takeAndDestroy($id);
ok('round trip is byte-exact', $out !== null && base64_decode(strtr($out, '-_', '+/')) === $raw);

echo "\n--- 2. Destroy is one-shot ---\n";

$id2 = $store->create(b64u('secret'), '1h');
$first = $store->takeAndDestroy($id2);
$second = $store->takeAndDestroy($id2);
ok('first read returns payload', $first !== null);
ok('second read returns null', $second === null);
ok('row is gone', (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 0);

echo "\n--- 3. closeCursor() is load-bearing ---\n";

// Prove the failure mode the spec warns about, on this exact PHP/MariaDB pair.
$id3 = $store->create(b64u('x'), '1h');
$bad = $pdo->prepare('DELETE FROM notes WHERE id_hash = ? AND expires_at > NOW() RETURNING payload');
$bad->execute([hash('sha256', $id3)]);
$bad->fetch(PDO::FETCH_ASSOC);
// deliberately NOT calling closeCursor()
$leaked = false;
try {
    $pdo->query('SELECT 1')->fetchColumn();
} catch (PDOException $e) {
    $leaked = true;
}
echo "         (next query after unclosed cursor " . ($leaked ? "threw: it matters" : "succeeded on this driver") . ")\n";
$bad->closeCursor();
ok('driver recovers after closeCursor()', (int) $pdo->query('SELECT 1')->fetchColumn() === 1);

echo "\n--- 4. Expired notes are indistinguishable from absent ---\n";

$idExp = $store->create(b64u('expired-content'), '1h');
$pdo->prepare('UPDATE notes SET expires_at = NOW() - INTERVAL 1 SECOND WHERE id_hash = ?')
    ->execute([hash('sha256', $idExp)]);

$expiredRead = $store->takeAndDestroy($idExp);
$absentRead  = $store->takeAndDestroy(b64u(random_bytes(16)));
$malformed   = $store->takeAndDestroy('not-a-valid-id');
ok('expired note reads as null', $expiredRead === null);
ok('absent note reads as null', $absentRead === null);
ok('malformed ID reads as null', $malformed === null);
ok('all three are the identical value', $expiredRead === $absentRead && $absentRead === $malformed);
ok('expired row NOT deleted by read path (purge owns it)',
    (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 1);

echo "\n--- 5. Input validation ---\n";

$caught = null;
try { $store->create(b64u('x'), '2h'); } catch (Throwable $e) { $caught = $e::class; }
ok('non-whitelisted TTL rejected', $caught === 'NoteMy\Store\InvalidTtl', (string) $caught);

$caught = null;
try { $store->create(str_repeat('A', 32769), '1h'); } catch (Throwable $e) { $caught = $e::class; }
ok('payload > 32 KB rejected', $caught === 'NoteMy\Store\PayloadTooLarge', (string) $caught);

$caught = null;
try { $store->create('not+valid/base64url==', '1h'); } catch (Throwable $e) { $caught = $e::class; }
ok('non-base64url payload rejected', $caught === 'NoteMy\Store\InvalidPayload', (string) $caught);

ok('32 KB payload accepted at the boundary',
    strlen($store->create(b64u(random_bytes(24575)), '1h')) === 22);

echo "\n--- 6. Purge batching ---\n";

$pdo->exec('TRUNCATE TABLE notes');
$pdo->exec('TRUNCATE TABLE daily_stats');

$ins = $pdo->prepare('INSERT INTO notes (id_hash, payload, expires_at) VALUES (?, ?, ?)');
for ($i = 0; $i < 2500; $i++) {
    // 2000 expired, 500 live
    $ins->execute([
        hash('sha256', 'seed' . $i),
        random_bytes(64),
        $i < 2000 ? date('Y-m-d H:i:s', time() - 60) : date('Y-m-d H:i:s', time() + 3600),
    ]);
}

$b1 = $store->purgeExpiredBatch(1000);
$b2 = $store->purgeExpiredBatch(1000);
$b3 = $store->purgeExpiredBatch(1000);
ok('batches honour LIMIT', $b1 === 1000 && $b2 === 1000, "{$b1}/{$b2}");
ok('final batch is short, terminating the loop', $b3 === 0, (string) $b3);
ok('live notes untouched', (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 500);
ok('notes_expired aggregated correctly',
    (int) $pdo->query('SELECT notes_expired FROM daily_stats WHERE stat_date = CURDATE()')->fetchColumn() === 2000);

echo "\n--- 7. Stats do not leak per-note data ---\n";

$cols = $pdo->query('SHOW COLUMNS FROM notes')->fetchAll(PDO::FETCH_COLUMN);
ok('notes table has exactly the 4 sanctioned columns',
    $cols === ['id_hash', 'payload', 'expires_at', 'created_at'], implode(',', $cols));

$pdo->exec('TRUNCATE TABLE daily_stats');
$idS = $store->create(b64u('counted'), '1h');
$store->takeAndDestroy($idS);
$store->takeAndDestroy(b64u(random_bytes(16))); // a miss
$row = $pdo->query('SELECT notes_created, notes_read FROM daily_stats WHERE stat_date = CURDATE()')->fetch();
ok('a miss issues the same statement but adds 0',
    (int) $row['notes_created'] === 1 && (int) $row['notes_read'] === 1,
    json_encode($row));

echo "\n" . str_repeat('=', 46) . "\n";
echo ($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} passed, {$fail} failed\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
