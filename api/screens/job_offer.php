<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;

/**
 * Flutter: OfferDetailScreen
 * GET /v1/screens/job-offer/{id}
 * POST /v1/screens/job-offer/{id}/accept
 * POST /v1/screens/job-offer/{id}/reject
 */
final class JobOfferScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $profile = $this->pros->profilePayload($this->uid($request));
        $codes = array_column($profile['skills'] ?? [], 'category_code');
        $offers = ReferenceData::demoJobOffers($codes);

        $offerId = null;
        if (preg_match('#/job-offer/([^/]+)#', $request->path, $m)) {
            $offerId = $m[1];
        }

        $offer = null;
        foreach ($offers as $o) {
            if ($o['id'] === $offerId) {
                $offer = $o;
                break;
            }
        }

        if ($offer === null) {
            Response::fail('Offer not found', 404);
            return;
        }

        if ($request->method === 'GET') {
            Response::ok(['screen' => 'job_offer', 'offer' => $offer]);
            return;
        }

        if ($request->method === 'POST' && str_ends_with($request->path, '/accept')) {
            Response::ok([
                'screen' => 'job_offer',
                'accepted' => true,
                'active_job' => $this->toActiveJob($offer),
                'next_route' => '/job/active',
            ]);
            return;
        }

        if ($request->method === 'POST' && str_ends_with($request->path, '/reject')) {
            Response::ok(['screen' => 'job_offer', 'rejected' => true]);
            return;
        }

        Response::fail('Method not allowed', 405);
    }

    /** @param array<string, mixed> $offer */
    private function toActiveJob(array $offer): array
    {
        return [
            'id' => $offer['id'],
            'code' => $offer['code'],
            'category_code' => $offer['category_code'],
            'problem' => $offer['problem'],
            'customer_name' => $offer['customer_name'],
            'customer_phone_masked' => '+91 78xxx xx42',
            'customer_address' => $offer['customer_area_name'],
            'customer_area_name' => $offer['customer_area_name'],
            'distance_km' => $offer['distance_km'],
            'visit_fee_paise' => $offer['visit_fee_paise'],
            'status' => 'accepted',
        ];
    }
}
