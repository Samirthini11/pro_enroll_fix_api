<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;

/**
 * Flutter: JobsTab (home shell)
 * GET /v1/screens/home-jobs
 */
final class HomeJobsScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $profile = $this->pros->profilePayload($this->uid($request));
        $codes = array_column($profile['skills'] ?? [], 'category_code');

        Response::ok([
            'screen' => 'home_jobs',
            'is_available' => $profile['is_available'] ?? false,
            'offers' => ReferenceData::demoJobOffers($codes),
        ]);
    }
}
