<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AdminAuthService;

/**
 * POST /v1/auth/admin/login
 * { "email": "admin@proenroll.in", "password": "admin123" }
 */
final class AdminLoginEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        try {
            $service = new AdminAuthService();
            Response::ok($service->login($email, $password, $request));
        } catch (\InvalidArgumentException $e) {
            Response::fail($e->getMessage(), 422, 'validation');
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 401, 'invalid_credentials');
        } catch (\Throwable $e) {
            Response::fail('Login failed', 500, 'admin_login_failed');
        }
    }
}
