<?php

declare(strict_types=1);

namespace ProEnroll\Api\Middleware;

use ProEnroll\Api\Auth\JwtAuth;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AuthRepository;

final class JwtTokenMiddleware
{
    public static function require(Request $request): bool
    {
        if ($request->bearerToken === null || $request->bearerToken === '') {
            Response::fail('Missing Authorization: Bearer <jwt>', 401, 'missing_token');
            return false;
        }

        try {
            $request->authUser = JwtAuth::verify($request->bearerToken);
        } catch (\Throwable $e) {
            Response::fail('Invalid or expired JWT', 401, 'invalid_token');
            return false;
        }

        $jti = $request->authUser['jti'] ?? null;
        if ($jti !== null && $jti !== '') {
            $auth = new AuthRepository();
            if ($auth->findActiveSession($jti) === null) {
                Response::fail('Session revoked or expired', 401, 'session_revoked');
                return false;
            }
        }

        return true;
    }

    /**
     * Attach auth user when a valid Bearer token is present; never fails the request.
     */
    public static function optional(Request $request): bool
    {
        if ($request->bearerToken === null || $request->bearerToken === '') {
            return false;
        }

        try {
            $request->authUser = JwtAuth::verify($request->bearerToken);
        } catch (\Throwable) {
            return false;
        }

        $jti = $request->authUser['jti'] ?? null;
        if ($jti !== null && $jti !== '') {
            $auth = new AuthRepository();
            if ($auth->findActiveSession($jti) === null) {
                $request->authUser = [];
                return false;
            }
        }

        return true;
    }
}
