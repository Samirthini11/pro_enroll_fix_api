<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: ExperienceScreen
 * PUT /v1/screens/onboard-experience
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

        $yearsByCategory = $request->input('experience_by_category', []);
        if (is_array($yearsByCategory) && $yearsByCategory !== []) {
            $skills = [];
            $first = true;
            foreach ($yearsByCategory as $code => $years) {
                $skills[] = [
                    'category_code' => (string) $code,
                    'experience_years' => max(0, (int) $years),
                    'is_primary' => $first,
                ];
                $first = false;
            }
            $this->pros->replaceSkills($this->uid($request), $skills);
        }

        Response::ok([
            'screen' => 'onboard_experience',
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
