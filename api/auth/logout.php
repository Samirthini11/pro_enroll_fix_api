<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/logout  — revoke session (requires JWT)
 */
final class LogoutEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        $jti = $request->authUser['jti'] ?? null;
        $uid = $request->authUser['sub'] ?? null;
        $service = new AuthService();
        $service->logout(is_string($jti) ? $jti : null, is_string($uid) ? $uid : null);

        Response::ok(['logged_out' => true]);
    }
}
