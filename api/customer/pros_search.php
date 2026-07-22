<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Customer;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\ReferenceData;
use ProEnroll\Api\Services\AuthService;
use ProEnroll\Api\Services\ProRepository;

/** GET /v1/customer/pros/search?city_id=1&category_code=ac&q=&lat=&lng= */
final class ProsSearchEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $cityId = (int) $request->input('city_id', '1');
        if (ReferenceData::cityById($cityId) === null) {
            Response::fail('Invalid city_id', 422, 'validation');
            return;
        }

        $category = $request->input('category_code');
        $category = is_string($category) && $category !== '' ? $category : null;
        $q = $request->input('q');
        $q = is_string($q) ? $q : null;

        $rawLat = $request->input('lat');
        $rawLng = $request->input('lng');
        $lat = is_numeric($rawLat) ? (float) $rawLat : null;
        $lng = is_numeric($rawLng) ? (float) $rawLng : null;

        // Optional auth: when logged-in customer, hide pros they already booked (in process).
        $excludeCustomerId = null;
        if (JwtTokenMiddleware::optional($request)) {
            $auth = new AuthService();
            $excludeCustomerId = $auth->resolveCustomerId($request);
        }

        $pros = new ProRepository();
        $results = $pros->searchForCustomer(
            $cityId,
            $category,
            $q,
            $lat,
            $lng,
            $excludeCustomerId,
        );

        foreach ($results as &$r) {
            foreach (ReferenceData::categories() as $c) {
                if ($c['code'] === $r['primary_category_code']) {
                    $r['primary_category_name'] = $c['name_en'];
                    break;
                }
            }
        }
        unset($r);

        Response::ok([
            'city_id' => $cityId,
            'category_code' => $category,
            'total' => count($results),
            'pros' => $results,
        ]);
    }
}
