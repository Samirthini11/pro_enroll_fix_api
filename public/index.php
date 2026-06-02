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

require $root . '/vendor/autoload.php';

Config::load($root);

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
