<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'time' => date('c'),
    'note' => 'If you see this JSON, PHP and Apache are working.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
