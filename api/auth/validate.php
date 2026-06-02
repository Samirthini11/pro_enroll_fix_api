<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;

/**
 * GET /v1/auth/validate  — check JWT + session still active
 */
final class ValidateEndpoint
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

        Response::ok([
            'valid' => true,
            'auth_uid' => $request->authUser['sub'] ?? null,
            'phone_e164' => $request->authUser['phone'] ?? null,
            'session_id' => $request->authUser['jti'] ?? null,
            'expires_at' => isset($request->authUser['exp'])
                ? date('c', (int) $request->authUser['exp'])
                : null,
        ]);
    }
}
