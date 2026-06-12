<?php

declare(strict_types=1);

// Show PHP errors when ?debug=1 or APP_DEBUG=true in .env
$root = dirname(__DIR__);
$debugQuery = isset($_GET['debug']) && $_GET['debug'] !== '0' && $_GET['debug'] !== '';

if (is_readable($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$appDebug = in_array(strtolower((string) ($_ENV['APP_DEBUG'] ?? '')), ['1', 'true', 'yes', 'on'], true);
$showErrors = $debugQuery || $appDebug;

if ($showErrors) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function pingEnv(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

function pingDbErrorHelp(string $message): string
{
    if (str_contains($message, '1045')) {
        return 'MySQL rejected the username/password. Create the user in phpMyAdmin (database/setup_mysql_user.sql) and match DB_USER/DB_PASS in server .env.';
    }
    if (str_contains($message, '1698')) {
        return 'Ubuntu MySQL blocks root from PHP. Use a dedicated user (proadmin) instead of root in .env.';
    }
    if (str_contains($message, '1049')) {
        return 'Database does not exist. Create pro_enroll in phpMyAdmin and import database/schema.sql.';
    }
    if (str_contains($message, '2002') || str_contains($message, 'Connection refused')) {
        return 'MySQL is not running or DB_HOST is wrong. On VPS use DB_HOST=127.0.0.1.';
    }
    return 'Check server .env and MySQL user permissions in phpMyAdmin.';
}

$checks = [
    'php' => phpversion(),
    'debug' => $showErrors,
    'vendor' => is_readable($root . '/vendor/autoload.php'),
    'env' => is_readable($root . '/.env'),
    'env_path' => $root . '/.env',
    'root_htaccess' => is_readable($root . '/.htaccess'),
    'public_htaccess' => is_readable(__DIR__ . '/.htaccess'),
    'api_screens' => is_readable($root . '/api/screens/splash.php'),
];

if ($checks['env']) {
    $checks['app_debug'] = $appDebug;
    $checks['app_url'] = pingEnv('APP_URL');
    $checks['db_host'] = pingEnv('DB_HOST', '127.0.0.1');
    $checks['db_port'] = pingEnv('DB_PORT', '3306');
    $checks['db_name'] = pingEnv('DB_NAME', 'pro_enroll');
    $checks['db_user'] = pingEnv('DB_USER', 'root');
    $checks['db_pass_set'] = pingEnv('DB_PASS') !== null && pingEnv('DB_PASS') !== '';

    $checks['env_loaded'] = [
        'DB_HOST' => pingEnv('DB_HOST') !== null,
        'DB_PORT' => pingEnv('DB_PORT') !== null,
        'DB_NAME' => pingEnv('DB_NAME') !== null,
        'DB_USER' => pingEnv('DB_USER') !== null,
        'DB_PASS' => pingEnv('DB_PASS') !== null,
        'APP_URL' => pingEnv('APP_URL') !== null,
    ];

    $appUrl = (string) ($checks['app_url'] ?? '');
    if (str_contains($appUrl, '/public')) {
        $checks['app_url_warning'] = 'APP_URL should be http://98.93.105.128/pro_enroll_api (remove /public)';
    }
}

if ($checks['vendor']) {
    require $root . '/vendor/autoload.php';
    \ProEnroll\Api\Config::load($root);

    $host = pingEnv('DB_HOST', '127.0.0.1');
    $port = pingEnv('DB_PORT', '3306');
    $name = pingEnv('DB_NAME', 'pro_enroll');
    $user = pingEnv('DB_USER', 'root');
    $pass = pingEnv('DB_PASS', '');

    $checks['db_dsn'] = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO(
            $checks['db_dsn'],
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $checks['db'] = 'OK';
        $checks['tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $checks['db'] = 'FAIL';
        $checks['db_error'] = $e->getMessage();
        $checks['db_error_help'] = pingDbErrorHelp($e->getMessage());
        if ($showErrors) {
            $checks['db_error_file'] = $e->getFile();
            $checks['db_error_line'] = $e->getLine();
            $checks['db_error_trace'] = explode("\n", $e->getTraceAsString());
        }
    }
} else {
    $checks['db'] = 'SKIP';
    $checks['errors'][] = 'vendor/autoload.php missing — run: composer install --no-dev';
}

if (!$checks['env']) {
    $checks['errors'][] = '.env file missing at ' . $checks['env_path'];
}

$checks['ok'] = $checks['vendor']
    && $checks['env']
    && $checks['api_screens']
    && ($checks['db'] ?? '') === 'OK'
    && !isset($checks['app_url_warning']);

if (!$checks['ok']) {
    $checks['fixes'] = [];
    if (($checks['db'] ?? '') !== 'OK') {
        $checks['fixes'][] = 'phpMyAdmin → SQL → run database/setup_mysql_user.sql';
        $checks['fixes'][] = 'Server .env: DB_USER=proadmin DB_PASS=Krishna@135';
        if (isset($checks['db_error_help'])) {
            $checks['fixes'][] = $checks['db_error_help'];
        }
    }
    if (isset($checks['app_url_warning'])) {
        $checks['fixes'][] = $checks['app_url_warning'];
    }
    if (!$checks['api_screens']) {
        $checks['fixes'][] = 'Upload api/ folder; run composer dump-autoload -o on server';
    }
    if (!$checks['root_htaccess']) {
        $checks['fixes'][] = 'Upload root .htaccess for /v1/* routes';
    }
}

http_response_code($checks['ok'] ? 200 : 503);
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
