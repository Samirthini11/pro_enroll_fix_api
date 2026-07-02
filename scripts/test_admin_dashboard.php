<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Services\AdminRepository;

try {
    $stats = (new AdminRepository())->dashboardStats();
    echo json_encode(['ok' => true, 'stats' => $stats], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
