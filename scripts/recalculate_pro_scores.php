<?php

declare(strict_types=1);

/**
 * One-shot backfill: recalculate pro_score for all professionals.
 *
 * Usage: php scripts/recalculate_pro_scores.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Services\ProScoreService;

try {
    $n = (new ProScoreService())->recalculateAll();
    echo json_encode(['ok' => true, 'updated' => $n], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
