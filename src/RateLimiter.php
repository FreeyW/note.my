<?php

declare(strict_types=1);

namespace NoteMy;

use NoteMy\Http\ClientIp;
use Redis;
use RedisException;
use Throwable;

/**
 * Rate limiting and live-note quota. Redis holds counters and nothing else —
 * no note data, no raw IPs. Losing the whole dataset costs nothing but a
 * window of reset limits.
 *
 * Availability beats enforcement here: if Redis is down the service must keep
 * accepting notes. A dead limiter is an annoyance; a dead create endpoint is
 * an outage.
 */
final class RateLimiter
{
    private ?Redis $redis = null;

    private bool $unavailable = false;

    /** @param array{host?:string,port?:int,socket?:string,timeout?:float} $cfg */
    public function __construct(
        private readonly array $cfg,
        private readonly string $secret,
    ) {
    }

    /**
     * Fixed-window counter. Returns true if the request may proceed.
     *
     * The window is intentionally coarse — a caller can burst up to 2x the
     * limit across a boundary. A sliding window would be more precise, and
     * more code, for a threshold that exists to blunt automation rather than
     * to be exact.
     */
    public function allow(string $bucket, string $ip, int $limit, int $window = 60): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return true;
        }

        $key = "rl:{$bucket}:" . $this->ipHash($ip);

        try {
            $count = $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            } elseif ($count > $limit && $redis->ttl($key) < 0) {
                // Defensive: a key that lost its TTL would block the network
                // forever. Re-arm it rather than serving a permanent 429.
                $redis->expire($key, $window);
            }

            return $count <= $limit;
        } catch (Throwable $e) {
            $this->degrade($e);

            return true;
        }
    }

    /**
     * Number of not-yet-expired notes attributable to this network.
     *
     * Implemented as a sorted set scored by expiry rather than a plain counter,
     * because nothing on the server can decrement a counter when a note is
     * read: `notes` stores no creator identifier, so neither the read path nor
     * the purge job knows whose note it just destroyed. Letting entries age out
     * by TTL overcounts (a note read early still occupies quota until its
     * original expiry) but overcounting is the safe direction for a quota, and
     * it avoids building the note-to-network mapping we deliberately don't keep.
     *
     * Members are random tokens, never note IDs or hashes.
     */
    public function liveCount(string $ip): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }

        $key = 'live:' . $this->ipHash($ip);

        try {
            $redis->zRemRangeByScore($key, '-inf', (string) time());

            return (int) $redis->zCard($key);
        } catch (Throwable $e) {
            $this->degrade($e);

            return 0;
        }
    }

    public function trackLive(string $ip, int $expiresAt): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }

        $key = 'live:' . $this->ipHash($ip);

        try {
            $redis->zAdd($key, $expiresAt, bin2hex(random_bytes(8)));
            // Longest TTL plus slack, so the set cannot outlive its contents.
            $redis->expire($key, 2592000 + 3600);
        } catch (Throwable $e) {
            $this->degrade($e);
        }
    }

    public function isDegraded(): bool
    {
        return $this->unavailable;
    }

    /**
     * HMAC of the *network*, not the address. The server-side secret means a
     * stolen Redis dump cannot be brute-forced back to a list of visitors —
     * the IPv4 space is small enough that a plain hash would fall in seconds.
     */
    private function ipHash(string $ip): string
    {
        $network = ClientIp::normalize($ip) ?? $ip;

        return substr(hash_hmac('sha256', $network, $this->secret), 0, 32);
    }

    private function redis(): ?Redis
    {
        if ($this->unavailable) {
            return null;
        }
        if ($this->redis instanceof Redis) {
            return $this->redis;
        }

        try {
            $redis = new Redis();
            $connected = isset($this->cfg['socket'])
                ? $redis->connect($this->cfg['socket'])
                : $redis->connect($this->cfg['host'] ?? '127.0.0.1', $this->cfg['port'] ?? 6379, $this->cfg['timeout'] ?? 0.2);

            if ($connected !== true) {
                throw new RedisException('connect returned false');
            }

            return $this->redis = $redis;
        } catch (Throwable $e) {
            $this->degrade($e);

            return null;
        }
    }

    private function degrade(Throwable $e): void
    {
        if (!$this->unavailable) {
            // Class and message only — never a key, never a payload.
            error_log('notemy: rate limiting degraded, allowing all traffic: ' . $e::class);
        }
        $this->unavailable = true;
        $this->redis = null;
    }
}
