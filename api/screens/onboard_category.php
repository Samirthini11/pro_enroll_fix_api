<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;
use ProEnroll\Api\Services\CategoryRepository;

/**
 * Flutter: CategorySelectScreen
 * GET /v1/screens/onboard-category
 * PUT /v1/screens/onboard-category
 */
final class OnboardCategoryScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'onboard_category',
                'categories' => ReferenceData::categories(),
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

        $codes = $request->input('category_codes', []);
        if (!is_array($codes) || $codes === []) {
            Response::fail('category_codes array required', 422);
            return;
        }

        $categories = new CategoryRepository();
        $invalid = $categories->validateCodes(array_map('strval', $codes));
        if ($invalid !== null) {
            Response::fail("Unknown category code: $invalid", 422, 'invalid_category');
            return;
        }

        $yearsByCategory = $request->input('experience_by_category', []);
        if (!is_array($yearsByCategory)) {
            $yearsByCategory = [];
        }

        $this->ensurePro($request);
        $skills = [];
        foreach ($codes as $i => $code) {
            $code = (string) $code;
            $years = 0;
            if (isset($yearsByCategory[$code])) {
                $years = (int) $yearsByCategory[$code];
            }
            $skills[] = [
                'category_code' => $code,
                'experience_years' => max(0, min(50, $years)),
                'is_primary' => $i === 0,
            ];
        }
        $this->pros->replaceSkills($this->uid($request), $skills);

        Response::ok([
            'screen' => 'onboard_category',
            'saved' => true,
            'category_codes' => $codes,
        ]);
    }
}
