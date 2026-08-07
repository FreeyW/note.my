<?php

declare(strict_types=1);

namespace NoteMy\Store;

use PDO;
use RuntimeException;

/**
 * PDO factory.
 *
 * Hard requirement: MariaDB >= 10.5 with native prepared statements.
 * We do NOT silently degrade — DELETE ... RETURNING is load-bearing for the
 * destroy semantics, and a MySQL server would fail at runtime in a much more
 * confusing way than at startup.
 */
final class Database
{
    public const MIN_VERSION = '10.5';

    /**
     * @param array{host?:string,port?:int,socket?:string,dbname:string,user:string,pass:string} $cfg
     */
    public static function connect(array $cfg): PDO
    {
        $dsn = isset($cfg['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s', $cfg['socket'], $cfg['dbname'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 3306, $cfg['dbname']);

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            // Native prepares. Required: emulated prepares would interpolate the
            // BLOB into the SQL text, and would not reliably surface the result
            // set produced by DELETE ... RETURNING.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            // Pinned deliberately, not left to the default. Measured on
            // PHP 8.3.6 / MariaDB 10.11.14: with buffering ON, forgetting
            // closeCursor() after DELETE ... RETURNING is harmless; with it
            // OFF, the very next query dies with "2014 Cannot execute queries
            // while other unbuffered queries are active". Payloads are capped
            // at 32 KB, so there is no reason to ever turn this off — and
            // turning it off would silently arm that failure mode in the
            // destroy path.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        self::assertMariaDb($pdo);

        return $pdo;
    }

    /**
     * Fail loudly on MySQL or on MariaDB < 10.5.
     */
    public static function assertMariaDb(PDO $pdo): void
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        if (stripos($version, 'mariadb') === false) {
            throw new RuntimeException(
                "note.my requires MariaDB " . self::MIN_VERSION . "+ (DELETE ... RETURNING is not "
                . "available on MySQL). Server reports: {$version}"
            );
        }

        // "10.11.14-MariaDB-0ubuntu0.24.04.1" -> "10.11.14"
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) || version_compare($m[1], self::MIN_VERSION, '<')) {
            throw new RuntimeException(
                "note.my requires MariaDB " . self::MIN_VERSION . "+. Server reports: {$version}"
            );
        }

        if ((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
            throw new RuntimeException('PDO::ATTR_EMULATE_PREPARES must be false.');
        }
    }
}
