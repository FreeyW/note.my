<?php

declare(strict_types=1);

namespace NoteMy\Http;

use InvalidArgumentException;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private array $headers = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * A redirect with an empty body.
     *
     * $location is written into a header, so it is restricted to an absolute
     * path built by this application — never anything derived from the request.
     * A header value containing CR or LF would split the response.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        if (!str_starts_with($location, '/') || strpbrk($location, "\r\n") !== false) {
            throw new InvalidArgumentException('redirect target must be a plain absolute path');
        }

        return new self($status, '', [
            'Location'      => $location,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        // Belt and braces. CSP/HSTS live in nginx.conf per the spec, but these
        // two are cheap here and protect anyone running the app behind a
        // misconfigured proxy.
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header_remove('X-Powered-By');

        echo $this->body;
    }
}
