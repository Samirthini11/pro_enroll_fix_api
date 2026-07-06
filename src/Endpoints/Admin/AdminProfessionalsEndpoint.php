<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET /v1/admin/professionals?page=1&limit=20
 * GET /v1/admin/professionals/{id}
 * GET /v1/admin/professionals/{id}/bookings?status=all&page=1&limit=20
 */
final class AdminProfessionalsEndpoint
{
    public function handle(Request $request, ?int $proId = null, ?string $sub = null): void
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

            if ($proId !== null && $sub === 'bookings') {
                $page = max(1, (int) ($request->query['page'] ?? 1));
                $limit = max(1, min(50, (int) ($request->query['limit'] ?? 20)));
                $status = (string) ($request->query['status'] ?? 'all');
                Response::ok($repo->listProfessionalBookings($proId, $page, $limit, $status));
                return;
            }

            if ($proId !== null) {
                $detail = $repo->professionalDetail($proId);
                if ($detail === null) {
                    Response::fail('Professional not found', 404, 'not_found');
                    return;
                }
                Response::ok(['item' => $detail]);
                return;
            }

            $page = max(1, (int) ($request->query['page'] ?? 1));
            $limit = max(1, min(50, (int) ($request->query['limit'] ?? 20)));
            Response::ok($repo->listProfessionals($page, $limit));
        } catch (\Throwable $e) {
            Response::fail(
                'Could not load professionals: ' . $e->getMessage(),
                500,
                'admin_professionals_failed',
            );
        }
    }
}
