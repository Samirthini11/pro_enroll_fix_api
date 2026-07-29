<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET /v1/admin/bookings/stats?from=&to=&month=YYYY-MM
 * GET /v1/admin/bookings?from=&to=&month=&status=&page=1&limit=20
 */
final class AdminBookingsEndpoint
{
    public function handle(Request $request, ?string $action = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new AdminRepository();
            $from = trim((string) ($request->query['from'] ?? ''));
            $to = trim((string) ($request->query['to'] ?? ''));
            $month = trim((string) ($request->query['month'] ?? ''));
            $status = trim((string) ($request->query['status'] ?? 'all'));
            $isStats = $action === 'stats' || str_ends_with($request->path, '/stats');

            if ($request->method === 'GET' && $isStats) {
                Response::ok($repo->bookingAnalytics($from, $to, $month));
                return;
            }

            if ($request->method === 'GET' && $action === null) {
                $page = max(1, (int) ($request->query['page'] ?? 1));
                $limit = max(1, min(50, (int) ($request->query['limit'] ?? 20)));
                Response::ok(
                    $repo->listAllBookings($page, $limit, $status, $from, $to, $month)
                );
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'Booking request failed: ' . $e->getMessage(),
                500,
                'admin_bookings_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
