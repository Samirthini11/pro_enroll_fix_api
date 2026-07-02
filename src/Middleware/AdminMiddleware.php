<?php

declare(strict_types=1);

namespace ProEnroll\Api\Middleware;

use ProEnroll\Api\Auth\JwtAuth;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

final class AdminMiddleware
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

        $role = (string) ($request->authUser['role'] ?? '');
        if ($role !== 'admin') {
            Response::fail('Admin access required', 403, 'forbidden');
            return false;
        }

        return true;
    }
}
