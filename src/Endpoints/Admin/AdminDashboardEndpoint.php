<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET /v1/admin/dashboard
 */
final class AdminDashboardEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new AdminRepository();
            Response::ok($repo->dashboardStats());
        } catch (\Throwable $e) {
            Response::fail(
                'Dashboard unavailable: ' . $e->getMessage(),
                500,
                'admin_dashboard_failed',
            );
        }
    }
}
