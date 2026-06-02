<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/refresh  { "refresh_token": "..." }
 */
final class RefreshEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $refresh = (string) $request->input('refresh_token', '');
        if ($refresh === '') {
            Response::fail('refresh_token is required', 422, 'validation');
            return;
        }

        try {
            $service = new AuthService();
            Response::ok($service->refresh($refresh, $request));
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 401, 'invalid_refresh');
        }
    }
}
