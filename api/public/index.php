<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use CacheCounty\Shared\Router;
use CacheCounty\Shared\Request;
use CacheCounty\Shared\Response;

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
    exit;
});

$request = new Request();
$router  = new Router($request);

require BASE_PATH . '/src/routes.php';

$router->dispatch();
