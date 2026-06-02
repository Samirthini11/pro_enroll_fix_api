<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/switch-role
 * { "role": "customer"|"professional" }
 *
 * Same phone can be both a Pro (enrolled) and a customer (books services).
 */
final class SwitchRoleEndpoint
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

        try {
            $service = new AuthService();
            Response::ok($service->switchRole($request));
        } catch (\InvalidArgumentException $e) {
            Response::fail($e->getMessage(), 422, 'validation');
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 404, 'profile_not_found');
        } catch (\Throwable $e) {
            Response::fail('Could not switch role', 500, 'role_switch_failed');
        }
    }
}
