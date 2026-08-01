<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\IstTime;

/**
 * Flutter: ExperienceScreen
 * PUT /v1/screens/onboard-experience
 *
 * Prefers experience_start_year_by_category; falls back to experience_by_category (years).
 * Backend stores start year and returns calculated experience_years (IST current year − start).
 */
final class OnboardExperienceScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'onboard_experience',
                'profile' => $this->pros->profilePayload($this->uid($request)),
                'current_year' => IstTime::currentYear(),
            ]);
            return;
        }

        if ($request->method !== 'PUT') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $fullName = trim((string) $request->input('full_name', ''));
        if ($fullName === '') {
            Response::fail('full_name required', 422);
            return;
        }

        $this->ensurePro($request);
        $this->pros->updateProfile($this->uid($request), ['full_name' => $fullName]);

        $startByCategory = $request->input('experience_start_year_by_category', []);
        $yearsByCategory = $request->input('experience_by_category', []);
        if (!is_array($startByCategory)) {
            $startByCategory = [];
        }
        if (!is_array($yearsByCategory)) {
            $yearsByCategory = [];
        }

        $codes = array_unique(array_merge(
            array_map('strval', array_keys($startByCategory)),
            array_map('strval', array_keys($yearsByCategory)),
        ));

        if ($codes !== []) {
            $skills = [];
            $first = true;
            foreach ($codes as $code) {
                $skill = [
                    'category_code' => (string) $code,
                    'is_primary' => $first,
                ];
                if (isset($startByCategory[$code])) {
                    $skill['experience_start_year'] = IstTime::clampStartYear((int) $startByCategory[$code]);
                } elseif (isset($yearsByCategory[$code])) {
                    $skill['experience_years'] = max(0, min(50, (int) $yearsByCategory[$code]));
                } else {
                    $skill['experience_start_year'] = IstTime::currentYear();
                }
                $skills[] = $skill;
                $first = false;
            }
            try {
                $this->pros->replaceSkills($this->uid($request), $skills);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'EXPERIENCE_EDIT_LOCKED') {
                    Response::fail(
                        'Experience year is locked. Open Help and request admin approval to edit.',
                        403,
                        'experience_edit_locked',
                    );
                    return;
                }
                throw $e;
            }
        }

        Response::ok([
            'screen' => 'onboard_experience',
            'current_year' => IstTime::currentYear(),
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
