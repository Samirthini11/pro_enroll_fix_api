<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use ProEnroll\Api\Auth\JwtAuth;
use ProEnroll\Api\Config;
use ProEnroll\Api\Http\Request;

final class AdminAuthService
{
    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, Request $request): array
    {
        $expectedEmail = (string) Config::get('ADMIN_EMAIL', 'admin@proenroll.in');
        $expectedPassword = (string) Config::get('ADMIN_PASSWORD', 'admin123');
        $adminName = (string) Config::get('ADMIN_NAME', 'Admin User');
        $adminRole = (string) Config::get('ADMIN_ROLE', 'ops');

        if ($email === '' || $password === '') {
            throw new \InvalidArgumentException('email and password are required');
        }

        if (!hash_equals(strtolower($expectedEmail), strtolower($email))
            || !hash_equals($expectedPassword, $password)) {
            throw new \RuntimeException('Invalid email or password');
        }

        $accessTtl = (int) Config::get('JWT_TTL_SECONDS', '604800');
        $adminId = 1;

        $accessToken = JwtAuth::issue([
            'sub' => 'admin_' . $adminId,
            'email' => $email,
            'role' => 'admin',
            'admin_role' => $adminRole,
        ], $accessTtl);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'admin' => [
                'id' => $adminId,
                'email' => $email,
                'name' => $adminName,
                'role' => $adminRole,
            ],
        ];
    }
}
