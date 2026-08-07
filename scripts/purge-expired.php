<?php

declare(strict_types=1);

/**
 * Delete expired notes. Run from cron every 5 minutes:
 *
 *   *_/5 * * * * /usr/bin/php /srv/note.my/scripts/purge-expired.php >/dev/null 2>&1
 *   (write the above with a real slash — escaped here to keep this comment valid)
 *
 * Deliberately a cron job rather than a MariaDB EVENT: the event scheduler
 * hangs off a global toggle that self-hosters routinely forget to enable, and
 * when it fails it fails silently.
 *
 * Reentrant via flock. Overlapping runs exit immediately rather than queueing.
 */

use NoteMy\Store\Database;
use NoteMy\Store\NoteStore;
use NoteMy\Store\StatsStore;

require __DIR__ . '/../src/Store/Database.php';
require __DIR__ . '/../src/Store/StatsStore.php';
require __DIR__ . '/../src/Store/NoteStore.php';

const BATCH_SIZE = 1000;

/** Stop after this long and let the next cron tick continue. Prevents a
 *  backlog from pinning one process for an unbounded time. */
const MAX_RUNTIME_SECONDS = 240;

$config = require __DIR__ . '/../config/config.php';

$lockPath = $config['purge_lock'] ?? '/var/lock/notemy-purge.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "purge: cannot open lock file {$lockPath}\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Previous run still going. Not an error.
    exit(0);
}

$started = microtime(true);
$total = 0;
$exit = 0;

try {
    $pdo = Database::connect($config['db']);
    $store = new NoteStore($pdo, new StatsStore($pdo));

    do {
        $deleted = $store->purgeExpiredBatch(BATCH_SIZE);
        $total += $deleted;

        if (microtime(true) - $started > MAX_RUNTIME_SECONDS) {
            fwrite(STDERR, "purge: time budget reached after {$total} rows; deferring rest\n");
            break;
        }
    } while ($deleted === BATCH_SIZE);
} catch (Throwable $e) {
    // Never log SQL or payloads. Class + message only, and the messages thrown
    // by this codebase are payload-free by construction.
    fwrite(STDERR, 'purge: ' . $e::class . ': ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($total > 0) {
    fwrite(STDOUT, "purge: removed {$total} expired notes\n");
}

exit($exit);
