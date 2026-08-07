<?php

declare(strict_types=1);

/**
 * One contender in the take-and-destroy race. Not part of the app.
 * Usage: php race-worker.php <noteId> <targetMicrotime> <isolationLevel>
 */

use NoteMy\Store\Database;
use NoteMy\Store\NoteStore;
use NoteMy\Store\StatsStore;

require __DIR__ . '/../src/Store/Database.php';
require __DIR__ . '/../src/Store/StatsStore.php';
require __DIR__ . '/../src/Store/NoteStore.php';

[$_, $id, $target, $isolation] = $argv;

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);
$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL {$isolation}");

$store = new NoteStore($pdo, new StatsStore($pdo));

// Spin until the shared start instant so all contenders hit the row together.
while (microtime(true) < (float) $target) {
    usleep(200);
}

try {
    $result = $store->takeAndDestroy($id);
    echo $result === null ? "MISS\n" : "HIT:{$result}\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
