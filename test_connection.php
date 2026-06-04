<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$root = __DIR__;
require $root . '/src/Config.php';

use ProEnroll\Api\Config;

Config::load($root);

$result = [
    'db' => 'FAIL',
    'tables' => [],
    'php' => phpversion(),
    'app_url' => Config::get('APP_URL'),
    'db_host' => Config::get('DB_HOST', '127.0.0.1'),
    'db_name' => Config::get('DB_NAME', 'pro_enroll'),
];

try {
    $host = Config::get('DB_HOST', '127.0.0.1');
    $port = Config::get('DB_PORT', '3306');
    $name = Config::get('DB_NAME', 'pro_enroll');
    $user = Config::get('DB_USER', 'root');
    $pass = Config::get('DB_PASS', '');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result['db'] = 'OK';
    $result['tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
