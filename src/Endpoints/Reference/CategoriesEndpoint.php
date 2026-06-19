<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Reference;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\CategoryRepository;

/**
 * Public reference data — work categories for Pro + Customer apps.
 * GET /v1/categories
 */
final class CategoriesEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $repo = new CategoryRepository();
        Response::ok(['categories' => $repo->listActive()]);
    }
}
