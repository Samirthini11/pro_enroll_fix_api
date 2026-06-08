<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$root = dirname(__DIR__);

$checks = [
    'php' => phpversion(),
    'vendor' => is_readable($root . '/vendor/autoload.php'),
    'env' => is_readable($root . '/.env'),
    'public_htaccess' => is_readable(__DIR__ . '/.htaccess'),
];

if ($checks['vendor']) {
    require $root . '/vendor/autoload.php';
    \ProEnroll\Api\Config::load($root);
    $checks['app_url'] = \ProEnroll\Api\Config::get('APP_URL');
    $checks['db_host'] = \ProEnroll\Api\Config::get('DB_HOST', '127.0.0.1');
    $checks['db_name'] = \ProEnroll\Api\Config::get('DB_NAME', 'pro_enroll');

    try {
        $host = \ProEnroll\Api\Config::get('DB_HOST', '127.0.0.1');
        $port = \ProEnroll\Api\Config::get('DB_PORT', '3306');
        $name = \ProEnroll\Api\Config::get('DB_NAME', 'pro_enroll');
        $user = \ProEnroll\Api\Config::get('DB_USER', 'proadmin');
        $pass = \ProEnroll\Api\Config::get('DB_PASS', 'Krishna@135');

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $checks['db'] = 'OK';
        $checks['tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $checks['db'] = 'FAIL';
        $checks['db_error'] = $e->getMessage();
    }
} else {
    $checks['db'] = 'SKIP';
}

$checks['ok'] = $checks['vendor'] && $checks['env'] && ($checks['db'] ?? '') === 'OK';

http_response_code($checks['ok'] ? 200 : 503);
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
