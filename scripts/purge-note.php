<?php

declare(strict_types=1);

/**
 * Delete a note in response to an abuse report.
 *
 *   php scripts/purge-note.php <note_id_hash>
 *   php scripts/purge-note.php --list [limit]
 *
 * Takes the sha256 hash from abuse_reports, never the note ID. An operator
 * acting on a report should not need — and cannot obtain — a working URL.
 */

use NoteMy\Store\Database;
use NoteMy\Store\NoteStore;
use NoteMy\Store\StatsStore;

require __DIR__ . '/../src/Store/Database.php';
require __DIR__ . '/../src/Store/StatsStore.php';
require __DIR__ . '/../src/Store/NoteStore.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$arg = $argv[1] ?? null;

if ($arg === null || $arg === '--help') {
    fwrite(STDERR, "usage: purge-note.php <note_id_hash> | --list [limit]\n");
    exit(2);
}

if ($arg === '--list') {
    $limit = max(1, min(200, (int) ($argv[2] ?? 20)));
    $stmt = $pdo->prepare(
        'SELECT r.note_id_hash, r.reason, r.created_at, COUNT(*) AS reports,
                EXISTS(SELECT 1 FROM notes n WHERE n.id_hash = r.note_id_hash) AS still_live
           FROM abuse_reports r
          GROUP BY r.note_id_hash
          ORDER BY reports DESC, r.created_at DESC
          LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    printf("%-64s  %-12s  %7s  %s\n", 'NOTE_ID_HASH', 'REASON', 'REPORTS', 'LIVE');
    foreach ($stmt as $row) {
        printf(
            "%-64s  %-12s  %7d  %s\n",
            $row['note_id_hash'],
            substr((string) $row['reason'], 0, 12),
            (int) $row['reports'],
            $row['still_live'] ? 'yes' : 'no'
        );
    }
    exit(0);
}

if (!preg_match('/^[0-9a-f]{64}$/', $arg)) {
    fwrite(STDERR, "error: expected a 64-character lowercase hex hash, not a note ID.\n");
    exit(2);
}

$store = new NoteStore($pdo, new StatsStore($pdo));

if ($store->deleteByHash($arg)) {
    fwrite(STDOUT, "deleted\n");
    exit(0);
}

// Already read, already expired, or never existed. Nothing to distinguish here
// either — and the operator has no need to.
fwrite(STDOUT, "no matching note\n");
exit(0);
