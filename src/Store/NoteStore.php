<?php

declare(strict_types=1);

namespace NoteMy\Store;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The only place user data is written or read.
 *
 * Server-side there is no encryption or decryption anywhere in this class, by
 * design. `payload` arrives as opaque base64url from the browser and leaves as
 * opaque base64url. If you ever find yourself reaching for openssl_encrypt()
 * here, the architecture has gone wrong.
 */
final class NoteStore
{
    /** Whitelisted clock TTLs, in seconds. Anything else is rejected. */
    public const TTLS = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000];

    public const DEFAULT_TTL = '7d';

    /**
     * "Until it is read." A note created with this TTL has no clock expiry at
     * all: the first read destroys it, and nothing else ever will.
     */
    public const TTL_NEVER = 'never';

    /**
     * Sentinel expiry for TTL_NEVER — the largest value a MariaDB DATETIME can
     * hold. A sentinel rather than a NULL column keeps `expires_at` NOT NULL and
     * leaves both hot statements byte-identical: the read still filters on
     * `expires_at > NOW()` and the purge still deletes `expires_at <= NOW()`.
     * So there is no new branch on the path that destroys notes, and no way for
     * the purge job to sweep up a note that was never meant to expire.
     */
    private const NEVER_EXPIRES_AT = '9999-12-31 23:59:59';

    /** Max base64url payload length. ~32 KB encoded ≈ 23 KB plaintext. */
    public const MAX_PAYLOAD = 32768;

    /** base64url of 16 random bytes, unpadded. */
    private const ID_LENGTH = 22;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StatsStore $stats,
    ) {
    }

    /**
     * Store a note and return its ID. The ID is the only thing that can address
     * the row, and it is never persisted — only sha256(id) is.
     *
     * @throws PayloadTooLarge|InvalidPayload|InvalidTtl
     */
    public function create(string $payloadB64, string $ttl = self::DEFAULT_TTL): string
    {
        if (!self::isValidTtl($ttl)) {
            throw new InvalidTtl($ttl);
        }
        if (strlen($payloadB64) > self::MAX_PAYLOAD) {
            throw new PayloadTooLarge();
        }

        $binary = self::b64uDecode($payloadB64);
        if ($binary === false || $binary === '') {
            throw new InvalidPayload();
        }

        $expiresAt = $ttl === self::TTL_NEVER
            ? self::NEVER_EXPIRES_AT
            : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . self::TTLS[$ttl] . ' seconds')
                ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO notes (id_hash, payload, expires_at) VALUES (?, ?, ?)'
        );

        // A 128-bit collision is not a real event, but a duplicate primary key
        // would otherwise surface to the user as a 500. Retry, then give up.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = self::b64uEncode(random_bytes(16));
            try {
                $stmt->bindValue(1, hash('sha256', $id), PDO::PARAM_STR);
                // PARAM_STR is binary-safe under native prepares; PARAM_LOB
                // would require a stream on some drivers.
                $stmt->bindValue(2, $binary, PDO::PARAM_STR);
                $stmt->bindValue(3, $expiresAt, PDO::PARAM_STR);
                $stmt->execute();

                $this->stats->bump('notes_created');

                return $id;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not allocate a unique note ID.');
    }

    public static function isValidTtl(string $ttl): bool
    {
        return $ttl === self::TTL_NEVER || isset(self::TTLS[$ttl]);
    }

    /**
     * How long a note of this TTL occupies its creator's live-note quota.
     *
     * A never-expiring note is charged the longest window rather than forever.
     * The quota is a throttle, not an accounting system — RateLimiter::liveCount()
     * already explains why it deliberately overcounts — and an entry that never
     * ages out would let a single unread note hold a slot indefinitely.
     */
    public static function quotaSeconds(string $ttl): int
    {
        return self::TTLS[$ttl] ?? self::TTLS['30d'];
    }

    /**
     * Atomically fetch and destroy. Returns the base64url payload, or null.
     *
     * null covers all three of "never existed", "already read" and "expired",
     * and the caller MUST render them identically — same status, same body,
     * same copy. Do not add a distinguishing return value here later.
     *
     * Concurrency: the DELETE takes an exclusive lock on the matching row, so
     * only one statement can succeed in deleting it. A second concurrent
     * request blocks until the first commits, then re-evaluates under READ
     * COMMITTED, finds nothing, and RETURNING yields an empty result set.
     * Exactly one caller receives the ciphertext.
     */
    public function takeAndDestroy(string $id): ?string
    {
        if (!self::isWellFormedId($id)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM notes WHERE id_hash = ? AND expires_at > NOW() RETURNING payload'
        );
        $stmt->execute([hash('sha256', $id)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Keep this. DELETE ... RETURNING produces a result set at the protocol
        // level, and an unconsumed one breaks the next query on this connection
        // with "2014 Cannot execute queries while other unbuffered queries are
        // active" — but *only* when MYSQL_ATTR_USE_BUFFERED_QUERY is false.
        // Under PDO's buffered default the omission is invisible, which is
        // exactly why it must not be omitted: the bug would lie dormant until
        // someone flips buffering off. Database.php pins buffering on as a
        // second line of defence. Measured both ways on 8.3.6 / 10.11.14.
        $stmt->closeCursor();

        $hit = $row !== false;

        // Issue the same statement on both paths so a hit and a miss do
        // comparable work. This narrows, but does not close, the timing gap —
        // a hit still transmits the BLOB. Documented in SECURITY.md.
        $this->stats->bump('notes_read', $hit ? 1 : 0);

        return $hit ? self::b64uEncode((string) $row['payload']) : null;
    }

    /**
     * Delete one batch of expired notes. Returns rows removed.
     *
     * Batched deliberately: a single unbounded DELETE would hold locks on
     * idx_expires for as long as it runs. Callers loop until this returns
     * fewer than $batch. See scripts/purge-expired.php.
     */
    public function purgeExpiredBatch(int $batch = 1000): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM notes WHERE expires_at <= NOW() LIMIT ?');
        $stmt->bindValue(1, $batch, PDO::PARAM_INT);
        $stmt->execute();
        $deleted = $stmt->rowCount();

        if ($deleted > 0) {
            // Per batch, not per run: if this process is killed mid-loop, the
            // work already committed is still accounted for.
            $this->stats->bump('notes_expired', $deleted);
        }

        return $deleted;
    }

    /**
     * Record an abuse report. Stores only sha256(id), so an operator can ban or
     * purge the note without ever being able to open it.
     *
     * Deliberately silent about whether the note exists — see the note in
     * NoteController::report().
     */
    public function recordReport(string $id, string $reason): void
    {
        if (!self::isWellFormedId($id)) {
            return;
        }
        $reason = substr($reason, 0, 32);

        $this->pdo->prepare(
            'INSERT INTO abuse_reports (note_id_hash, reason, created_at) VALUES (?, ?, NOW())'
        )->execute([hash('sha256', $id), $reason]);
    }

    /**
     * Abuse handling. Takes the hash directly — the operator running this has a
     * report, not a URL, and should never need the ID itself.
     */
    public function deleteByHash(string $idHash): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $idHash)) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM notes WHERE id_hash = ?');
        $stmt->execute([$idHash]);

        return $stmt->rowCount() > 0;
    }

    private static function isWellFormedId(string $id): bool
    {
        return strlen($id) === self::ID_LENGTH && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }

    private static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return string|false */
    private static function b64uDecode(string $s)
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1) {
            return false;
        }
        $padded = strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4);

        return base64_decode($padded, true);
    }
}

final class PayloadTooLarge extends \RuntimeException
{
}

final class InvalidPayload extends \RuntimeException
{
}

final class InvalidTtl extends \RuntimeException
{
    public function __construct(string $ttl)
    {
        parent::__construct('Unsupported TTL: ' . $ttl);
    }
}
