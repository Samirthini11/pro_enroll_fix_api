<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Customer;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;
use ProEnroll\Api\Services\ProRepository;

/** GET /v1/customer/pros/{id} */
final class ProDetailEndpoint
{
    public function handle(Request $request, int $proId): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $category = $request->input('category_code');
        $category = is_string($category) && $category !== '' ? $category : null;

        $rawLat = $request->input('lat');
        $rawLng = $request->input('lng');
        $lat = is_numeric($rawLat) ? (float) $rawLat : null;
        $lng = is_numeric($rawLng) ? (float) $rawLng : null;

        $pros = new ProRepository();
        $detail = $pros->customerDetail($proId, $category, $lat, $lng);
        if ($detail === null) {
            Response::fail('Technician not found', 404, 'not_found');
            return;
        }

        foreach (ReferenceData::categories() as $c) {
            if ($c['code'] === $detail['primary_category_code']) {
                $detail['primary_category_name'] = $c['name_en'];
                break;
            }
        }

        Response::ok(['pro' => $detail]);
    }
}
