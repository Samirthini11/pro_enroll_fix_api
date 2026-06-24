<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;

/**
 * GET /v1/auth/me  — current user + profile (requires JWT)
 */
final class MeEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        try {
            $service = new AuthService();
            Response::ok($service->me($request));
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 404, 'not_found');
        }
    }
}
