<?php
 
declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/otp/send
 * { "phone_e164": "+919876543210", "mode": "sign_in"|"sign_up" }
 */
final class OtpSendEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $phone = (string) $request->input('phone_e164', '');
        if ($phone === '') {
            Response::fail('phone_e164 is required', 422, 'validation');
            return;
        }

        $purpose = (string) $request->input('mode', $request->input('purpose', 'sign_up'));

      try {
            $service = new AuthService();
            Response::ok($service->sendOtp($phone, $purpose, $request));
         } catch (\InvalidArgumentException $e) {
             Response::fail($e->getMessage(), 422, 'validation');
         } catch (\Throwable $e) {
             $message = 'Failed to send OTP';
             if (\ProEnroll\Api\Config::bool('APP_DEBUG', false)) {
                 $message = $e->getMessage();
             }
             Response::fail($message, 500, 'otp_send_failed');
         }
    }
}
