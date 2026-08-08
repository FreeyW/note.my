<?php

declare(strict_types=1);

/**
 * The only entry point. Nginx sends everything that is not a static asset here.
 */

use NoteMy\Controller\NoteController;
use NoteMy\Controller\PageController;
use NoteMy\Http\Request;
use NoteMy\Http\Response;
use NoteMy\I18n;
use NoteMy\RateLimiter;
use NoteMy\Router;
use NoteMy\Store\Database;
use NoteMy\Store\NoteStore;
use NoteMy\Store\StatsStore;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'NoteMy\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
// Exception classes share a file with NoteStore, so PSR-4 autoloading alone
// won't find them.
require $root . '/src/Store/NoteStore.php';

$config = require $root . '/config/config.php';

$request = Request::fromGlobals($config['proxy'] ?? []);

// Local development only. PHP's built-in server funnels every request through
// this file, including /assets/*, which would otherwise 404 and break SRI.
// Under PHP-FPM this branch is unreachable: nginx serves /assets/ from disk and
// never forwards those requests here.
if (PHP_SAPI === 'cli-server') {
    $candidate = realpath(__DIR__ . $request->path);
    if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

/**
 * Deferred connection. Nothing opens a socket to MariaDB until a handler
 * actually calls this closure, which is what makes "GET /n/{id} does not
 * touch the database" a structural property rather than a convention:
 * PageController has no way to invoke it. tests/HttpTest.php asserts this by
 * watching the server's global connection counter across a burst of GETs.
 */
$store = static function () use ($config): NoteStore {
    static $instance = null;
    if ($instance === null) {
        $pdo = Database::connect($config['db']);
        $instance = new NoteStore($pdo, new StatsStore($pdo));
    }

    return $instance;
};

$limiter = new RateLimiter($config['redis'] ?? [], $config['ip_hash_secret']);
$notes = new NoteController($store, $limiter, rtrim($config['canonical_origin'], '/'));
$pages = new PageController($root . '/public/assets', $root . '/frontend/i18n', rtrim($config['canonical_origin'], '/'));

$router = new Router();
$router->add('GET',  '/',              $pages->createPage(...));
$router->add('GET',  '/zh',            $pages->createPage(...));
$router->add('GET',  '/robots.txt',    $pages->robots(...));
$router->add('GET',  '/sitemap.xml',   $pages->sitemap(...));
$router->add('GET',  '/n/{id}',        $pages->readPage(...));
// A note URL that lost its ID. {id} matches one or more characters, so neither
// of these reaches readPage — without them /n/ answers a browser with a JSON
// error object, and /n never reaches PHP at all under the nginx config.
$router->add('GET',  '/n',             $pages->newNote(...));
$router->add('GET',  '/n/',            $pages->newNote(...));
$router->add('POST', '/api/note',      $notes->create(...));
$router->add('POST', '/api/note/{id}', $notes->read(...));
$router->add('POST', '/api/report',    $notes->report(...));

// Everything else: a page for browsers, JSON for /api/ clients.
$router->fallback($pages->notFound(...));

try {
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    // Class name only. Messages can carry query fragments, and a stack trace
    // would carry bound parameters — meaning ciphertext — into the error log.
    error_log('notemy: unhandled ' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    $response = Response::json(['error' => 'server_error'], 500);
}

$response->send();
