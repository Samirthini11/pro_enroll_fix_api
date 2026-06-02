<?php

declare(strict_types=1);

namespace ProEnroll\Api\Http;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function ok(mixed $data = null, int $status = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    public static function fail(string $message, int $status = 400, ?string $code = null): void
    {
        self::json([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $code ?? 'error',
                'message' => $message,
            ],
        ], $status);
    }
}
