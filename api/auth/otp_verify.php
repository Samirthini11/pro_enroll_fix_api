<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/otp/verify
 * { "request_id": "...", "otp": "123456", "mode": "sign_in"|"sign_up" }
 */
final class OtpVerifyEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $requestId = (string) $request->input('request_id', '');
        $otp = (string) $request->input('otp', '');
        $mode = (string) $request->input('mode', 'sign_up');

        if ($requestId === '' || strlen($otp) < 6) {
            Response::fail('request_id and 6-digit otp are required', 422, 'validation');
            return;
        }

        try {
            $service = new AuthService();
            Response::ok($service->verifyOtp($requestId, $otp, $mode, $request));
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'JWT_SECRET')) {
                Response::fail($e->getMessage(), 503, 'jwt_not_configured');
                return;
            }
            Response::fail($e->getMessage(), 401, 'invalid_otp');
        } catch (\Throwable $e) {
            Response::fail('Verification failed', 500, 'otp_verify_failed');
        }
    }
}
