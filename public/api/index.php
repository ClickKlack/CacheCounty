<?php
declare(strict_types=1);

// Front controller lives in the docroot (public/api/), the application in api/ outside it
define('BASE_PATH', dirname(__DIR__, 2) . '/api');

require BASE_PATH . '/vendor/autoload.php';

use CacheCounty\Shared\Router;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

// No CORS headers: the frontend is served from the same origin, cross-origin
// access to the API is not intended.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    // Log details for diagnosis, but never send them to the client
    error_log('[CacheCounty] ' . $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
    exit;
});

$request = new Request();
$router  = new Router($request);

require BASE_PATH . '/src/routes.php';

$router->dispatch();
