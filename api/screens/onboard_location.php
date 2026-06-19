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

        $fields = [
            'city_id' => $cityId,
            'work_radius_km' => max(1, min(25, $radius)),
        ];

        $rawLat = $request->input('home_lat');
        $rawLng = $request->input('home_lng');
        if (is_numeric($rawLat) && is_numeric($rawLng)) {
            $fields['home_lat'] = round((float) $rawLat, 6);
            $fields['home_lng'] = round((float) $rawLng, 6);
        }

        $this->ensurePro($request);
        $this->pros->updateProfile($this->uid($request), $fields);

        $city = ReferenceData::cityById($cityId);
        $mapLat = isset($fields['home_lat'])
            ? (float) $fields['home_lat']
            : (is_array($city) ? (float) $city['latitude'] : null);
        $mapLng = isset($fields['home_lng'])
            ? (float) $fields['home_lng']
            : (is_array($city) ? (float) $city['longitude'] : null);
        Response::ok([
            'screen' => 'onboard_location',
            'city_id' => $cityId,
            'work_radius_km' => $radius,
            'map' => $mapLat !== null && $mapLng !== null ? [
                'latitude' => $mapLat,
                'longitude' => $mapLng,
                'name' => is_array($city) ? ($city['name'] ?? null) : null,
            ] : null,
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
