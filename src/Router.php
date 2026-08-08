<?php

declare(strict_types=1);

namespace NoteMy;

use NoteMy\Http\Request;
use NoteMy\Http\Response;

/**
 * The whole router. No framework, by design — this file should stay small
 * enough that an auditor can read it in a minute and be sure that
 * GET /n/{id} reaches nothing but a static handler.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable}> */
    private array $routes = [];

    /** @var null|callable(Request):Response */
    private $fallback = null;

    /**
     * $pattern uses {name} placeholders, which match one path segment and are
     * passed to the handler as an associative array.
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        // preg_quote escapes the braces, so the placeholder pattern below
        // matches \{name\} rather than {name}.
        $regex = preg_replace_callback(
            '/\\\\\{([a-z]+)\\\\\}/',
            static fn(array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            preg_quote($pattern, '#')
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    /**
     * What answers a request that matched no route at all.
     *
     * Without one, every mistyped URL — a truncated /n/, a stale bookmark —
     * is answered with a JSON error object, which is the right reply to an
     * API client and gibberish to the browser that actually asked.
     *
     * @param callable(Request):Response $handler
     */
    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        if ($this->fallback !== null) {
            return ($this->fallback)($request);
        }

        return Response::json(['error' => 'not_found'], 404);
    }
}
