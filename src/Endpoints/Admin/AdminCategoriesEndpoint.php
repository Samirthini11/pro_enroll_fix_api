<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;
use ProEnroll\Api\Services\CategoryRepository;

/**
 * GET  /v1/admin/categories?include_inactive=1
 * POST /v1/admin/categories
 */
final class AdminCategoriesEndpoint
{
    public function handle(Request $request, ?string $code = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new CategoryRepository();

            if ($request->method === 'GET' && $code === null) {
                $includeInactive = ($request->query['include_inactive'] ?? '1') !== '0';
                $items = $repo->listAll($includeInactive);
                $adminRepo = new AdminRepository();
                $from = trim((string) ($request->query['from'] ?? ''));
                $to = trim((string) ($request->query['to'] ?? ''));
                $month = trim((string) ($request->query['month'] ?? ''));
                $counts = $adminRepo->categoryBookingCounts($from, $to, $month);
                foreach ($items as $i => $item) {
                    $codeKey = (string) ($item['code'] ?? '');
                    $items[$i]['booking_count'] = $counts[$codeKey] ?? 0;
                }
                Response::ok(['items' => $items, 'total' => count($items)]);
                return;
            }

            if ($request->method === 'GET' && $code !== null) {
                $item = $repo->findByCode($code);
                if ($item === null) {
                    Response::fail('Category not found', 404);
                    return;
                }
                Response::ok(['item' => $item]);
                return;
            }

            if ($request->method === 'POST' && $code === null) {
                $body = $request->body;
                $item = $repo->create($body);
                if ($item === null) {
                    Response::fail(
                        'Invalid category data or code already exists',
                        422,
                        'validation',
                    );
                    return;
                }
                Response::ok(['item' => $item, 'created' => true]);
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'Category request failed: ' . $e->getMessage(),
                500,
                'admin_categories_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
