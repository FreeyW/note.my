<?php

declare(strict_types=1);

namespace NoteMy\Store;

use InvalidArgumentException;
use PDO;

/**
 * Day-granularity counters only. Nothing here identifies a single note.
 *
 * Note the delta-may-be-zero contract: NoteStore::takeAndDestroy() calls
 * bump('notes_read', 0) on a miss so that the hit and miss paths issue the
 * identical statement against the identical row. See the timing note in
 * NoteStore.
 */
final class StatsStore
{
    private const COLUMNS = ['notes_created', 'notes_read', 'notes_expired'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function bump(string $column, int $delta = 1): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Unknown stats column: {$column}");
        }
        if ($delta < 0) {
            throw new InvalidArgumentException('Stats deltas are monotonic.');
        }

        // Column name is whitelisted above, never user input.
        $sql = "INSERT INTO daily_stats (stat_date, {$column}) VALUES (CURDATE(), ?)
                ON DUPLICATE KEY UPDATE {$column} = {$column} + VALUES({$column})";

        $this->pdo->prepare($sql)->execute([$delta]);
    }
}
