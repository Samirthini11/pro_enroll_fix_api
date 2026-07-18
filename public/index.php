<?php

declare(strict_types=1);

use ProEnroll\Api\Config;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Router;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$root = dirname(__DIR__);

if (!is_readable($root . '/vendor/autoload.php')) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'missing_vendor',
            'message' => 'Run composer install in the API project root on the server.',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

require $root . '/vendor/autoload.php';

Config::load($root);
\ProEnroll\Api\IstTime::bootstrap();

try {
    $request = Request::fromGlobals();
    Router::dispatch($request);
} catch (\Throwable $e) {
    $debug = Config::bool('APP_DEBUG');
    Response::fail(
        $debug ? $e->getMessage() : 'Internal server error',
        500,
        'server_error'
    );
}
