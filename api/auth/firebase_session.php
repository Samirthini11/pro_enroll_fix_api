<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Auth;

use ProEnroll\Api\Auth\FirebaseAuth;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\AuthService;

/**
 * POST /v1/auth/firebase/session
 * { "id_token": "...", "mode": "sign_in"|"sign_up", "app": "pro_enroll" }
 *
 * After Firebase Phone Auth sign-in in the Flutter app, exchange the ID token
 * for a Pro-Enroll JWT session (same shape as /v1/auth/otp/verify).
 */
final class FirebaseSessionEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if (!FirebaseAuth::isConfigured()) {
            Response::fail('Firebase is not configured on the server', 503, 'firebase_not_configured');
            return;
        }

        $idToken = (string) $request->input('id_token', '');
        if ($idToken === '') {
            Response::fail('id_token is required', 422, 'validation');
            return;
        }

        $mode = (string) $request->input('mode', $request->input('purpose', 'sign_up'));

        try {
            $service = new AuthService();
            Response::ok($service->sessionFromFirebaseIdToken($idToken, $mode, $request));
        } catch (\InvalidArgumentException $e) {
            Response::fail($e->getMessage(), 422, 'validation');
        } catch (\RuntimeException $e) {
            Response::fail('Invalid Firebase token', 401, 'invalid_firebase_token');
        } catch (\Throwable $e) {
            Response::fail('Firebase session failed', 500, 'firebase_session_failed');
        }
    }
}
