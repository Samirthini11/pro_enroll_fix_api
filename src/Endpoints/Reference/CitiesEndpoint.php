<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Reference;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;

/**
 * Public reference data — cities for Pro + Customer apps.
 * GET /v1/cities
 */
final class CitiesEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        Response::ok(['cities' => ReferenceData::cities()]);
    }
}
