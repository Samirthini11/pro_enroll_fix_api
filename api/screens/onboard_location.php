<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;

/**
 * Flutter: HomeLocationScreen
 * GET /v1/screens/onboard-location
 * PUT /v1/screens/onboard-location
 */
final class OnboardLocationScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'onboard_location',
                'cities' => ReferenceData::cities(),
            ]);
            return;
        }

        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'PUT') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $cityId = (int) $request->input('city_id', 0);
        $radius = (int) $request->input('work_radius_km', 5);
        if ($cityId < 1) {
            Response::fail('city_id required', 422);
            return;
        }

        $this->ensurePro($request);
        $this->pros->updateProfile($this->uid($request), [
            'city_id' => $cityId,
            'work_radius_km' => max(1, min(25, $radius)),
        ]);

        $city = ReferenceData::cityById($cityId);
        Response::ok([
            'screen' => 'onboard_location',
            'city_id' => $cityId,
            'work_radius_km' => $radius,
            'map' => $city ? [
                'latitude' => $city['latitude'],
                'longitude' => $city['longitude'],
                'name' => $city['name'],
            ] : null,
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
