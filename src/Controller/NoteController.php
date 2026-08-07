<?php

declare(strict_types=1);

namespace NoteMy\Controller;

use Closure;
use NoteMy\Http\Request;
use NoteMy\Http\Response;
use NoteMy\RateLimiter;
use NoteMy\Store\InvalidPayload;
use NoteMy\Store\InvalidTtl;
use NoteMy\Store\NoteStore;
use NoteMy\Store\PayloadTooLarge;
use Throwable;

final class NoteController
{
    private const CREATE_LIMIT = 10;

    private const READ_LIMIT = 30;

    private const REPORT_LIMIT = 3;

    private const LIVE_LIMIT = 50;

    /**
     * @param Closure():NoteStore $store Deferred on purpose — see index.php.
     */
    public function __construct(
        private readonly Closure $store,
        private readonly RateLimiter $limiter,
        private readonly string $canonicalOrigin,
    ) {
    }

    public function create(Request $request): Response
    {
        if (!$this->sameOrigin($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $ip = $request->clientIp();
        if (!$this->limiter->allow('create', $ip, self::CREATE_LIMIT)) {
            return $this->tooManyRequests();
        }

        // Reject on declared size before reading, so a large body never gets
        // allocated just to be thrown away.
        if ($request->contentLength() > Request::MAX_BODY) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }

        if ($this->limiter->liveCount($ip) >= self::LIVE_LIMIT) {
            return Response::json(['error' => 'quota_exceeded'], 429);
        }

        try {
            $body = $request->json();
        } catch (Throwable) {
            return Response::json(['error' => 'bad_request'], 400);
        }

        $payload = $body['payload'] ?? null;
        $ttl = $body['ttl'] ?? NoteStore::DEFAULT_TTL;

        if (!is_string($payload) || !is_string($ttl)) {
            return Response::json(['error' => 'bad_request'], 400);
        }

        try {
            $id = ($this->store)()->create($payload, $ttl);
        } catch (PayloadTooLarge) {
            return Response::json(['error' => 'payload_too_large'], 413);
        } catch (InvalidPayload | InvalidTtl) {
            return Response::json(['error' => 'bad_request'], 400);
        }

        $this->limiter->trackLive($ip, time() + NoteStore::TTLS[$ttl]);

        return Response::json(['id' => $id], 201);
    }

    /**
     * Take and destroy.
     *
     * Every failure mode below returns byte-identical JSON with the same
     * status: never existed, already read, expired, malformed ID. Do not add a
     * distinguishing message here, however tempting it is for debugging — the
     * uniformity is the feature.
     */
    public function read(Request $request, array $params): Response
    {
        if (!$this->sameOrigin($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // Misses are rate limited exactly like hits. Enumeration is the only
        // reason to issue a high volume of misses.
        if (!$this->limiter->allow('read', $request->clientIp(), self::READ_LIMIT)) {
            return $this->tooManyRequests();
        }

        $payload = ($this->store)()->takeAndDestroy((string) ($params['id'] ?? ''));

        if ($payload === null) {
            return $this->gone();
        }

        return Response::json(['payload' => $payload]);
    }

    public function report(Request $request): Response
    {
        if (!$this->sameOrigin($request)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        if (!$this->limiter->allow('report', $request->clientIp(), self::REPORT_LIMIT)) {
            return $this->tooManyRequests();
        }

        try {
            $body = $request->json();
        } catch (Throwable) {
            return Response::json(['error' => 'bad_request'], 400);
        }

        $id = $body['id'] ?? null;
        $reason = $body['reason'] ?? null;

        if (!is_string($id) || !is_string($reason)) {
            return Response::json(['error' => 'bad_request'], 400);
        }

        // Acknowledge regardless of whether the note exists — otherwise the
        // report endpoint becomes an oracle for note existence, which is the
        // exact leak the read endpoint is built to avoid.
        ($this->store)()->recordReport($id, $reason);

        return Response::json(['ok' => true], 202);
    }

    private function gone(): Response
    {
        return Response::json(['error' => 'not_found'], 404);
    }

    private function tooManyRequests(): Response
    {
        return Response::json(['error' => 'rate_limited'], 429)->withHeader('Retry-After', '60');
    }

    /**
     * Same-origin check for all writes. Absent Origin and Referer is treated as
     * a failure: this API is only ever called by our own page, and browsers
     * always send at least one on a cross-origin fetch.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin !== null) {
            return hash_equals($this->canonicalOrigin, rtrim($origin, '/'));
        }

        $referer = $request->header('referer');
        if ($referer !== null) {
            $parts = parse_url($referer);
            if (!isset($parts['scheme'], $parts['host'])) {
                return false;
            }
            $refOrigin = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return hash_equals($this->canonicalOrigin, $refOrigin);
        }

        return false;
    }
}
