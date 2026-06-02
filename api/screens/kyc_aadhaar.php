<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: AadhaarScreen
 * POST /v1/screens/kyc-aadhaar/initiate
 * POST /v1/screens/kyc-aadhaar/verify
 */
final class KycAadhaarScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $this->ensurePro($request);
        $action = (string) $request->input('action', 'initiate');

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if ($action === 'initiate') {
            $last4 = preg_replace('/\D/', '', (string) $request->input('aadhaar_last4', ''));
            if (strlen($last4) !== 4) {
                Response::fail('aadhaar_last4 must be 4 digits', 422);
                return;
            }
            $this->pros->updateProfile($this->uid($request), [
                'aadhaar_last4' => $last4,
                'kyc_status' => 'aadhaar_pending',
            ]);
            Response::ok([
                'screen' => 'kyc_aadhaar',
                'kyc_ref_id' => 'kyc_' . bin2hex(random_bytes(8)),
                'message' => 'Aadhaar OTP sent (integrate UIDAI provider in production)',
            ]);
            return;
        }

        if ($action === 'verify') {
            $otp = (string) $request->input('otp', '');
            if (strlen($otp) < 4 || $otp === '0000') {
                Response::fail('Invalid Aadhaar OTP', 422);
                return;
            }
            $this->pros->updateProfile($this->uid($request), ['kyc_status' => 'selfie_pending']);
            Response::ok(['screen' => 'kyc_aadhaar', 'verified' => true, 'next_route' => '/kyc/selfie']);
            return;
        }

        Response::fail('Unknown action', 422);
    }
}
