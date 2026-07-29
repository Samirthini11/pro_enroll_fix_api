<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;
use ProEnroll\Api\Services\PlatformSettingsRepository;

/**
 * Flutter: VisitFeeScreen
 * PUT /v1/screens/onboard-fee
 *
 * Accepts either:
 * - { "fees": [ { "category_code": "ac", "visit_fee_paise": 20000 }, ... ] }
 * - { "visit_fee_paise": 20000 }  (legacy — applies to all skills)
 */
final class OnboardFeeScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'onboard_fee',
                'defaults_by_category' => ReferenceData::defaultFees(),
                'base_prices_by_category' => ReferenceData::basePrices(),
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

        $settings = new PlatformSettingsRepository();
        $min = $settings->visitFeeMinPaise();
        $max = $settings->visitFeeMaxPaise();

        $this->ensurePro($request);
        $uid = $this->uid($request);

        $feesRaw = $request->input('fees');
        $fees = [];

        if (is_array($feesRaw) && $feesRaw !== []) {
            foreach ($feesRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['category_code'] ?? ''));
                $fee = (int) ($row['visit_fee_paise'] ?? 0);
                if ($code === '') {
                    continue;
                }
                if ($fee < $min || $fee > $max) {
                    Response::fail(
                        sprintf(
                            'visit_fee_paise for %s must be between ₹%d and ₹%d',
                            $code,
                            (int) ($min / 100),
                            (int) ($max / 100),
                        ),
                        422,
                    );
                    return;
                }
                $fees[] = [
                    'category_code' => $code,
                    'visit_fee_paise' => $settings->clampVisitFeePaise($fee),
                ];
            }
            if ($fees === []) {
                Response::fail('fees must include at least one category', 422, 'validation');
                return;
            }
            $this->pros->updateSkillVisitFees($uid, $fees);
        } else {
            $fee = (int) $request->input('visit_fee_paise', 0);
            if ($fee < $min || $fee > $max) {
                Response::fail(
                    sprintf(
                        'visit_fee_paise must be between %d and %d (₹%d–₹%d)',
                        $min,
                        $max,
                        (int) ($min / 100),
                        (int) ($max / 100),
                    ),
                    422,
                );
                return;
            }
            $fee = $settings->clampVisitFeePaise($fee);
            $pro = $this->proRow($request);
            $skillFees = [];
            if ($pro !== null) {
                foreach ($this->pros->getSkills((int) $pro['id']) as $s) {
                    $skillFees[] = [
                        'category_code' => (string) $s['category_code'],
                        'visit_fee_paise' => $fee,
                    ];
                }
            }
            if ($skillFees !== []) {
                $this->pros->updateSkillVisitFees($uid, $skillFees);
            } else {
                $this->pros->updateProfile($uid, ['visit_fee_paise' => $fee]);
            }
        }

        $profile = $this->pros->profilePayload($uid);

        Response::ok([
            'screen' => 'onboard_fee',
            'visit_fee_paise' => (int) ($profile['visit_fee_paise'] ?? 15000),
            'skills' => $profile['skills'] ?? [],
            'next_route' => '/kyc',
            'profile' => $profile,
        ]);
    }
}
