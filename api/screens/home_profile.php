<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: ProfileTab
 * GET /v1/screens/home-profile
 * PUT /v1/screens/home-profile
 */
final class HomeProfileScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $uid = $this->uid($request);
        $this->ensurePro($request);

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'home_profile',
                'profile' => $this->pros->profilePayload($uid),
            ]);
            return;
        }

        if ($request->method === 'PUT') {
            $fields = [];
            foreach (['full_name', 'upi_id', 'bank_account_no', 'bank_ifsc', 'language_code'] as $k) {
                if (array_key_exists($k, $request->body)) {
                    $fields[$k] = $request->body[$k];
                }
            }
            if (array_key_exists('is_available', $request->body)) {
                $wantOnline = (bool) $request->body['is_available'];
                $pro = $this->proRow($request);
                if ($wantOnline && $pro !== null && !empty($pro['listing_held'])) {
                    Response::fail(
                        'Your free bookings are used up. Listing is on hold — contact support to continue receiving jobs.',
                        403,
                        'listing_held',
                    );
                    return;
                }
                $fields['is_available'] = $wantOnline ? 1 : 0;
            }
            $this->pros->updateProfile($uid, $fields);
            Response::ok([
                'screen' => 'home_profile',
                'profile' => $this->pros->profilePayload($uid),
            ]);
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
