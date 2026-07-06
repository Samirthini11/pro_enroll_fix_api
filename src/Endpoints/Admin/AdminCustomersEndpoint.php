<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET /v1/admin/customers?page=1&limit=20
 * GET /v1/admin/customers/{id}
 * GET /v1/admin/customers/{id}/bookings?status=all&page=1&limit=20
 */
final class AdminCustomersEndpoint
{
    public function handle(Request $request, ?int $customerId = null, ?string $sub = null): void
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

            if ($customerId !== null && $sub === 'bookings') {
                $page = max(1, (int) ($request->query['page'] ?? 1));
                $limit = max(1, min(50, (int) ($request->query['limit'] ?? 20)));
                $status = (string) ($request->query['status'] ?? 'all');
                Response::ok($repo->listCustomerBookings($customerId, $page, $limit, $status));
                return;
            }

            if ($customerId !== null) {
                $detail = $repo->customerDetail($customerId);
                if ($detail === null) {
                    Response::fail('Customer not found', 404, 'not_found');
                    return;
                }
                Response::ok(['item' => $detail]);
                return;
            }

            $page = max(1, (int) ($request->query['page'] ?? 1));
            $limit = max(1, min(50, (int) ($request->query['limit'] ?? 20)));
            Response::ok($repo->listCustomers($page, $limit));
        } catch (\Throwable $e) {
            Response::fail(
                'Could not load customers: ' . $e->getMessage(),
                500,
                'admin_customers_failed',
            );
        }
    }
}
