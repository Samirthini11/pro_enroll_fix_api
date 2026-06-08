<?php

declare(strict_types=1);

namespace ProEnroll\Api\Http;

final class Request
{
    /** @var array{sub: string, phone: ?string, iat?: int, exp?: int}|null Verified JWT claims */
    public ?array $authUser = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly ?string $bearerToken,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (is_string($pathInfo) && $pathInfo !== '') {
            $path = rtrim($pathInfo, '/') ?: '/';
        } else {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = rtrim(dirname($scriptName), '/\\');
            if ($basePath !== '' && str_starts_with($uri, $basePath)) {
                $uri = substr($uri, strlen($basePath)) ?: '/';
            }
            if (str_starts_with($uri, '/index.php')) {
                $uri = substr($uri, strlen('/index.php')) ?: '/';
            }
            $path = rtrim($uri, '/') ?: '/';
        }

        $query = $_GET;
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            }
        }

        $auth = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $token = null;
        if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = trim($m[1]);
        }

        return new self($method, $path, $query, $body, $headers, $token);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
