<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use ProEnroll\Api\Auth\JwtAuth;
use ProEnroll\Api\Config;
use ProEnroll\Api\Http\Request;

final class AuthService
{
    private AuthRepository $auth;
    private ProRepository $pros;
    private OtpService $otp;

    public function __construct()
    {
        $this->auth = new AuthRepository();
        $this->pros = new ProRepository();
        $this->otp = new OtpService();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendOtp(string $phoneE164, string $purpose, Request $request): array
    {
        $purpose = $purpose === 'sign_in' ? 'sign_in' : 'sign_up';
        try {
            $result = $this->otp->send($phoneE164, $purpose, self::clientMeta($request));
            $this->auth->logAttempt($phoneE164, 'otp_send', true, self::ip($request), self::ua($request));
            return $result;
        } catch (\Throwable $e) {
            $this->auth->logAttempt($phoneE164, 'otp_send', false, self::ip($request), self::ua($request));
            throw $e;
        }
    }

    /**
     * Exchange a Firebase Phone Auth ID token for a Pro-Enroll JWT session.
     *
     * @return array<string, mixed>
     */
    public function sessionFromFirebaseIdToken(
        string $idToken,
        string $mode,
        Request $request,
    ): array {
        $claims = \ProEnroll\Api\Auth\FirebaseAuth::verifyIdToken($idToken);
        $phone = (string) ($claims['phone'] ?? '');
        if ($phone === '') {
            throw new \InvalidArgumentException('Firebase account has no verified phone number');
        }

        $this->auth->logAttempt($phone, 'firebase_session', true, self::ip($request), self::ua($request));

        return $this->issueSessionForPhone($phone, $mode, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyOtp(
        string $requestId,
        string $otp,
        string $mode,
        Request $request,
    ): array {
        $verified = $this->otp->verify($requestId, $otp);
        if ($verified === null) {
            $this->auth->logAttempt('', 'otp_verify', false, self::ip($request), self::ua($request));
            throw new \RuntimeException('Invalid or expired OTP');
        }

        $phone = $verified['phone_e164'];
        $this->auth->logAttempt($phone, 'otp_verify', true, self::ip($request), self::ua($request));

        return $this->issueSessionForPhone($phone, $mode, $request);
    }

    /**
     * Switch JWT session between customer and professional for the same phone.
     *
     * @return array<string, mixed>
     */
    public function switchRole(Request $request): array
    {
        $phone = (string) ($request->authUser['phone'] ?? '');
        if ($phone === '') {
            throw new \RuntimeException('Phone not found in session');
        }

        $target = (string) $request->input('role', '');
        if (!in_array($target, ['customer', 'professional'], true)) {
            throw new \InvalidArgumentException('role must be customer or professional');
        }

        if ($target === 'professional') {
            $pro = $this->pros->findByPhone($phone);
            if ($pro === null) {
                throw new \RuntimeException('No professional profile for this number. Enroll as a Pro first.');
            }
            $app = 'pro_enroll';
        } else {
            $app = 'pro_fix_customer';
        }

        $this->auth->logAttempt($phone, 'role_switch', true, self::ip($request), self::ua($request));

        return $this->issueSessionForPhone($phone, 'sign_in', $request, $app);
    }

    /**
     * @return array<string, mixed>
     */
    private function issueSessionForPhone(
        string $phone,
        string $mode,
        Request $request,
        ?string $appOverride = null,
    ): array {
        $app = $appOverride ?? (string) $request->input('app', 'pro_enroll');
        $customers = new CustomerRepository();

        if ($app === 'pro_fix_customer') {
            $name = (string) $request->input('full_name', '');
            $customer = $customers->upsertFromPhone($phone, $name !== '' ? $name : null);
            $authUid = (string) $customer['auth_uid'];
            $account = $this->auth->ensureCustomerAccount($phone, $authUid);
            $profile = $customers->profilePayload($authUid);
            $next = $customers->resolveNextRouteFromProfile($profile);
            $role = 'customer';
        } else {
            $pro = $this->pros->upsertFromPhone($phone);
            $authUid = (string) ($pro['firebase_uid'] ?? '');
            $account = $this->auth->ensureAccount($phone, $authUid, (int) $pro['id']);
            $profile = $this->pros->profilePayload($authUid);
            $next = $this->pros->resolveNextRouteFromProfile($profile);
            if ($app === 'pro_fix') {
                $next = '/dashboard';
            }
            $role = 'professional';
        }

        $accessTtl = (int) Config::get('JWT_TTL_SECONDS', '604800');
        $refreshTtl = (int) Config::get('JWT_REFRESH_TTL_SECONDS', '2592000');
        $deviceLabel = (string) $request->input(
            'device_label',
            match ($app) {
                'pro_fix_customer' => 'Pro Fix Customer',
                'pro_fix' => 'Pro Fix App',
                default => 'Pro-Enroll App',
            },
        );
        $session = $this->auth->createSession(
            (int) $account['id'],
            $accessTtl,
            $refreshTtl,
            $deviceLabel,
            self::ip($request),
            self::ua($request),
        );

        $accessToken = JwtAuth::issue([
            'sub' => $authUid,
            'phone' => $phone,
            'role' => $role,
            'jti' => $session['session_id'],
        ], $accessTtl);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $session['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'session_id' => $session['session_id'],
            'phone_e164' => $phone,
            'auth_uid' => $authUid,
            'role' => $role,
            'profile' => $profile,
            'next_route' => $next,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, Request $request): array
    {
        $row = $this->auth->findByRefreshToken($refreshToken);
        if ($row === null) {
            $this->auth->logAttempt('', 'refresh', false, self::ip($request), self::ua($request));
            throw new \RuntimeException('Invalid or expired refresh token');
        }

        $accessTtl = (int) Config::get('JWT_TTL_SECONDS', '604800');
        $phone = (string) $row['phone_e164'];
        $deviceLabel = (string) ($row['device_label'] ?? '');
        $isCustomer = str_contains($deviceLabel, 'Customer');

        if ($isCustomer) {
            $customers = new CustomerRepository();
            $customer = $customers->findByPhone($phone);
            if ($customer === null) {
                $customer = $customers->upsertFromPhone($phone);
            }
            $sub = (string) ($customer['auth_uid'] ?? $row['auth_uid']);
            $role = 'customer';
        } else {
            $sub = (string) $row['auth_uid'];
            $role = 'professional';
        }

        $accessToken = JwtAuth::issue([
            'sub' => $sub,
            'phone' => $phone,
            'role' => $role,
            'jti' => (string) $row['session_id'],
        ], $accessTtl);

        $this->auth->logAttempt((string) $row['phone_e164'], 'refresh', true, self::ip($request), self::ua($request));

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'session_id' => $row['session_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(Request $request): array
    {
        $authUid = (string) ($request->authUser['sub'] ?? '');
        $phone = (string) ($request->authUser['phone'] ?? '');

        if ($this->isCustomerJwt($request)) {
            return $this->customerMePayload($authUid, $phone);
        }

        return $this->professionalMePayload($authUid, $phone);
    }

    private function isCustomerJwt(Request $request): bool
    {
        $jwtRole = (string) ($request->authUser['role'] ?? '');
        if ($jwtRole === 'customer') {
            return true;
        }

        $jti = (string) ($request->authUser['jti'] ?? '');
        if ($jti === '') {
            return false;
        }

        $session = $this->auth->findActiveSession($jti);

        return $session !== null
            && str_contains((string) ($session['device_label'] ?? ''), 'Customer');
    }

    /**
     * @return array<string, mixed>
     */
    private function customerMePayload(string $authUid, string $phone): array
    {
        $customers = new CustomerRepository();
        $customer = $customers->findByAuthUid($authUid);
        if ($customer === null && $phone !== '') {
            $customer = $customers->findByPhone($phone);
        }
        if ($customer === null) {
            throw new \RuntimeException('Customer profile not found');
        }

        $custUid = (string) $customer['auth_uid'];
        $account = $phone !== ''
            ? $this->auth->findByPhone($phone)
            : $this->auth->findByAuthUid($authUid);
        if ($account === null) {
            throw new \RuntimeException('Account not found');
        }

        return [
            'auth_uid' => $custUid,
            'phone_e164' => (string) $account['phone_e164'],
            'status' => $account['status'],
            'role' => 'customer',
            'profile' => $customers->profilePayload($custUid),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function professionalMePayload(string $authUid, string $phone): array
    {
        $account = $this->auth->findByAuthUid($authUid);
        if ($account === null && $phone !== '') {
            $account = $this->auth->findByPhone($phone);
        }
        if ($account === null) {
            throw new \RuntimeException('Account not found');
        }

        $proUid = $authUid;
        $pro = $this->pros->findByFirebaseUid($authUid);
        if ($pro === null && $phone !== '') {
            $pro = $this->pros->findByPhone($phone);
        }
        if ($pro !== null) {
            $proUid = (string) ($pro['firebase_uid'] ?? $account['auth_uid']);
        } elseif ((string) $account['auth_uid'] !== '') {
            $proUid = (string) $account['auth_uid'];
        }

        return [
            'auth_uid' => $proUid,
            'phone_e164' => (string) $account['phone_e164'],
            'status' => $account['status'],
            'role' => 'professional',
            'profile' => $this->pros->profilePayload($proUid),
        ];
    }

    public function customerIdForAuthUid(string $authUid): ?int
    {
        $customers = new CustomerRepository();
        $c = $customers->findByAuthUid($authUid);
        return $c !== null ? (int) $c['id'] : null;
    }

    /**
     * Resolve customer id for customer-app routes (bookings, ratings).
     * Falls back to JWT phone so legacy/pro sessions can still book.
     */
    public function resolveCustomerId(Request $request): ?int
    {
        $authUid = (string) ($request->authUser['sub'] ?? '');
        $phone = (string) ($request->authUser['phone'] ?? '');

        $customers = new CustomerRepository();
        $byUid = $customers->findByAuthUid($authUid);
        if ($byUid !== null) {
            return (int) $byUid['id'];
        }

        if ($phone === '') {
            return null;
        }

        $byPhone = $customers->findByPhone($phone);
        if ($byPhone === null) {
            $byPhone = $customers->upsertFromPhone($phone);
        }

        $custUid = (string) ($byPhone['auth_uid'] ?? '');
        if ($custUid !== '') {
            $this->auth->ensureCustomerAccount($phone, $custUid);
        }

        return isset($byPhone['id']) ? (int) $byPhone['id'] : null;
    }

    public function logout(?string $sessionId, ?string $authUid): void
    {
        if ($sessionId !== null && $sessionId !== '') {
            $this->auth->revokeSession($sessionId);
        } elseif ($authUid !== null) {
            $account = $this->auth->findByAuthUid($authUid);
            if ($account !== null) {
                $this->auth->revokeAllForAccount((int) $account['id']);
            }
        }
    }

    public function validateSession(string $sessionId): bool
    {
        return $this->auth->findActiveSession($sessionId) !== null;
    }

    /** @return array{ip: ?string, user_agent: ?string} */
    private static function clientMeta(Request $request): array
    {
        return ['ip' => self::ip($request), 'user_agent' => self::ua($request)];
    }

    private static function ip(Request $request): ?string
    {
        $ip = $request->headers['X-Forwarded-For'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if (is_string($ip) && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return is_string($ip) ? substr($ip, 0, 45) : null;
    }

    private static function ua(Request $request): ?string
    {
        $ua = $request->headers['User-Agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) ? substr($ua, 0, 255) : null;
    }
}
