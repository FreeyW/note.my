<?php

declare(strict_types=1);

namespace NoteMy\Http;

final class Request
{
    /** Hard ceiling on any request body, above the 32 KB payload cap with room
     *  for JSON framing. Enforced before reading a single byte. */
    public const MAX_BODY = 65536;

    private ?string $bodyCache = null;

    /**
     * @param array<string,string> $headers
     * @param array{trusted_proxies?:list<string>,client_ip_header?:string} $proxyCfg
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly string $remoteAddr,
        private readonly int $contentLength,
        private readonly array $proxyCfg,
    ) {
    }

    /** @param array{trusted_proxies?:list<string>,client_ip_header?:string} $proxyCfg */
    public static function fromGlobals(array $proxyCfg = []): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rawurldecode(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            $proxyCfg,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentLength(): int
    {
        return $this->contentLength;
    }

    /**
     * The address used for rate limiting.
     *
     * A forwarded-for style header is honoured only when the immediate peer is
     * a configured proxy. Trusting it unconditionally would let anyone reset
     * their own rate limit by sending a header.
     */
    public function clientIp(): string
    {
        $headerName = $this->proxyCfg['client_ip_header'] ?? null;
        $trusted = $this->proxyCfg['trusted_proxies'] ?? [];

        if ($headerName !== null && $trusted !== [] && ClientIp::inAny($this->remoteAddr, $trusted)) {
            $forwarded = $this->header($headerName);
            if ($forwarded !== null && @inet_pton(trim($forwarded)) !== false) {
                return trim($forwarded);
            }
        }

        return $this->remoteAddr;
    }

    /**
     * Read the body, refusing anything oversized before allocating for it.
     * Returns null if the declared length exceeds MAX_BODY.
     */
    public function body(): ?string
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }
        if ($this->contentLength > self::MAX_BODY) {
            return null;
        }

        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        $raw = $raw === false ? '' : $raw;
        if (strlen($raw) > self::MAX_BODY) {
            return null;
        }

        return $this->bodyCache = $raw;
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        $raw = $this->body();
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }
}
