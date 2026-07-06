<?php

declare(strict_types=1);

namespace ProEnroll\Api;

use ProEnroll\Api\Endpoints\Admin\AdminCustomersEndpoint;
use ProEnroll\Api\Endpoints\Admin\AdminDashboardEndpoint;
use ProEnroll\Api\Endpoints\Admin\AdminDocumentsEndpoint;
use ProEnroll\Api\Endpoints\Admin\AdminKycEndpoint;
use ProEnroll\Api\Endpoints\Admin\AdminProfessionalsEndpoint;
use ProEnroll\Api\Endpoints\Auth\AdminLoginEndpoint;
use ProEnroll\Api\Endpoints\Auth\FirebaseSessionEndpoint;
use ProEnroll\Api\Endpoints\Auth\LogoutEndpoint;
use ProEnroll\Api\Endpoints\Auth\MeEndpoint;
use ProEnroll\Api\Endpoints\Auth\OtpSendEndpoint;
use ProEnroll\Api\Endpoints\Auth\OtpVerifyEndpoint;
use ProEnroll\Api\Endpoints\Auth\SwitchRoleEndpoint;
use ProEnroll\Api\Endpoints\Auth\RefreshEndpoint;
use ProEnroll\Api\Endpoints\Auth\ValidateEndpoint;
use ProEnroll\Api\Endpoints\Screens\AuthLandingScreen;
use ProEnroll\Api\Endpoints\Screens\AuthOtpScreen;
use ProEnroll\Api\Endpoints\Screens\AuthPhoneScreen;
use ProEnroll\Api\Endpoints\Screens\HomeEarningsScreen;
use ProEnroll\Api\Endpoints\Screens\HomeHelpScreen;
use ProEnroll\Api\Endpoints\Screens\HomeJobsScreen;
use ProEnroll\Api\Endpoints\Screens\HomeProfileScreen;
use ProEnroll\Api\Endpoints\Screens\JobActiveScreen;
use ProEnroll\Api\Endpoints\Screens\JobOfferScreen;
use ProEnroll\Api\Endpoints\Screens\KycAadhaarScreen;
use ProEnroll\Api\Endpoints\Screens\KycDocsScreen;
use ProEnroll\Api\Endpoints\Screens\KycIntroScreen;
use ProEnroll\Api\Endpoints\Screens\KycPendingScreen;
use ProEnroll\Api\Endpoints\Screens\KycSelfieScreen;
use ProEnroll\Api\Endpoints\Screens\OnboardCategoryScreen;
use ProEnroll\Api\Endpoints\Screens\OnboardExperienceScreen;
use ProEnroll\Api\Endpoints\Screens\OnboardFeeScreen;
use ProEnroll\Api\Endpoints\Screens\OnboardLocationScreen;
use ProEnroll\Api\Endpoints\Customer\BookingDetailEndpoint;
use ProEnroll\Api\Endpoints\Customer\BookingsEndpoint;
use ProEnroll\Api\Endpoints\Customer\CategoriesEndpoint;
use ProEnroll\Api\Endpoints\Reference\CategoriesEndpoint as ReferenceCategoriesEndpoint;
use ProEnroll\Api\Endpoints\Customer\CitiesEndpoint;
use ProEnroll\Api\Endpoints\Customer\ProDetailEndpoint;
use ProEnroll\Api\Endpoints\Customer\ProfileEndpoint;
use ProEnroll\Api\Endpoints\Customer\ProsSearchEndpoint;
use ProEnroll\Api\Endpoints\Device\PushHealthEndpoint;
use ProEnroll\Api\Endpoints\Device\PushTestEndpoint;
use ProEnroll\Api\Endpoints\Device\PushTokenEndpoint;
use ProEnroll\Api\Endpoints\Screens\SplashScreen;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

final class Router
{
    /** @var array<string, class-string> */
    private const ROUTES = [
        'POST /v1/auth/admin/login' => AdminLoginEndpoint::class,
        'POST /v1/auth/otp/send' => OtpSendEndpoint::class,
        'POST /v1/auth/otp/verify' => OtpVerifyEndpoint::class,
        'POST /v1/auth/firebase/session' => FirebaseSessionEndpoint::class,
        'POST /v1/auth/refresh' => RefreshEndpoint::class,
        'GET /v1/auth/me' => MeEndpoint::class,
        'GET /v1/auth/validate' => ValidateEndpoint::class,
        'POST /v1/auth/logout' => LogoutEndpoint::class,
        'POST /v1/auth/switch-role' => SwitchRoleEndpoint::class,
        'POST /v1/device/push-token' => PushTokenEndpoint::class,
        'POST /v1/device/push-test' => PushTestEndpoint::class,
        'GET /v1/health/push' => PushHealthEndpoint::class,
        'GET /v1/screens/splash' => SplashScreen::class,
        'GET /v1/screens/auth-landing' => AuthLandingScreen::class,
        'GET /v1/screens/auth-phone' => AuthPhoneScreen::class,
        'POST /v1/screens/auth-phone' => AuthPhoneScreen::class,
        'GET /v1/screens/auth-otp' => AuthOtpScreen::class,
        'POST /v1/screens/auth-otp' => AuthOtpScreen::class,
        'GET /v1/screens/onboard-category' => OnboardCategoryScreen::class,
        'PUT /v1/screens/onboard-category' => OnboardCategoryScreen::class,
        'GET /v1/screens/onboard-experience' => OnboardExperienceScreen::class,
        'PUT /v1/screens/onboard-experience' => OnboardExperienceScreen::class,
        'GET /v1/screens/onboard-location' => OnboardLocationScreen::class,
        'PUT /v1/screens/onboard-location' => OnboardLocationScreen::class,
        'GET /v1/screens/onboard-fee' => OnboardFeeScreen::class,
        'PUT /v1/screens/onboard-fee' => OnboardFeeScreen::class,
        'GET /v1/screens/kyc-intro' => KycIntroScreen::class,
        'POST /v1/screens/kyc-aadhaar' => KycAadhaarScreen::class,
        'POST /v1/screens/kyc-selfie' => KycSelfieScreen::class,
        'POST /v1/screens/kyc-docs' => KycDocsScreen::class,
        'GET /v1/screens/kyc-pending' => KycPendingScreen::class,
        'POST /v1/screens/kyc-pending/simulate-approval' => KycPendingScreen::class,
        'GET /v1/screens/home-jobs' => HomeJobsScreen::class,
        'GET /v1/screens/home-earnings' => HomeEarningsScreen::class,
        'GET /v1/screens/home-profile' => HomeProfileScreen::class,
        'PUT /v1/screens/home-profile' => HomeProfileScreen::class,
        'GET /v1/screens/home-help' => HomeHelpScreen::class,
        'GET /v1/screens/job-active' => JobActiveScreen::class,
        'PUT /v1/screens/job-active' => JobActiveScreen::class,
        'POST /v1/screens/job-active' => JobActiveScreen::class,
        'GET /v1/categories' => ReferenceCategoriesEndpoint::class,
        'GET /v1/customer/categories' => CategoriesEndpoint::class,
        'GET /v1/customer/cities' => CitiesEndpoint::class,
        'GET /v1/customer/pros/search' => ProsSearchEndpoint::class,
        'GET /v1/customer/bookings' => BookingsEndpoint::class,
        'POST /v1/customer/bookings' => BookingsEndpoint::class,
        'GET /v1/customer/profile' => ProfileEndpoint::class,
        'PUT /v1/customer/profile' => ProfileEndpoint::class,
        'GET /v1/admin/dashboard' => AdminDashboardEndpoint::class,
        'GET /v1/admin/kyc' => AdminKycEndpoint::class,
        'GET /v1/admin/documents' => AdminDocumentsEndpoint::class,
        'GET /v1/admin/professionals' => AdminProfessionalsEndpoint::class,
        'GET /v1/admin/customers' => AdminCustomersEndpoint::class,
    ];

    public static function dispatch(Request $request): void
    {
        if ($request->method === 'OPTIONS') {
            Response::ok(['ok' => true]);
            return;
        }

        $key = $request->method . ' ' . $request->path;

        if (isset(self::ROUTES[$key])) {
            $handler = new (self::ROUTES[$key])();
            $handler->handle($request);
            return;
        }

        if (preg_match('#^GET /v1/screens/job-offer/([^/]+)$#', $key, $m)) {
            (new JobOfferScreen())->handle($request);
            return;
        }

        if (preg_match('#^POST /v1/screens/job-offer/([^/]+)/(accept|reject)$#', $key, $m)) {
            (new JobOfferScreen())->handle($request);
            return;
        }

        if (preg_match('#^GET /v1/customer/pros/(\d+)$#', $key, $m)) {
            (new ProDetailEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if (preg_match('#^GET /v1/customer/bookings/(\d+)$#', $key, $m)) {
            (new BookingDetailEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if (preg_match('#^POST /v1/customer/bookings/(\d+)/complete$#', $key, $m)) {
            (new BookingDetailEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if (preg_match('#^POST /v1/customer/bookings/(\d+)/rating$#', $key, $m)) {
            (new BookingDetailEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if ($request->method === 'POST' && $request->path === '/v1/customer/profile/photo') {
            (new ProfileEndpoint())->handle($request);
            return;
        }

        if (preg_match('#^GET /v1/admin/kyc/(\d+)$#', $key, $m)) {
            (new AdminKycEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if (preg_match('#^POST /v1/admin/kyc/(\d+)/(approve|reject)$#', $key, $m)) {
            (new AdminKycEndpoint())->handle($request, (int) $m[1], $m[2]);
            return;
        }

        if (preg_match('#^POST /v1/admin/documents/(\d+)/(approve|reject)$#', $key, $m)) {
            (new AdminDocumentsEndpoint())->handle($request, (int) $m[1], $m[2]);
            return;
        }

        if (preg_match('#^GET /v1/admin/professionals/(\d+)/bookings$#', $key, $m)) {
            (new AdminProfessionalsEndpoint())->handle($request, (int) $m[1], 'bookings');
            return;
        }

        if (preg_match('#^GET /v1/admin/professionals/(\d+)$#', $key, $m)) {
            (new AdminProfessionalsEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if (preg_match('#^GET /v1/admin/customers/(\d+)/bookings$#', $key, $m)) {
            (new AdminCustomersEndpoint())->handle($request, (int) $m[1], 'bookings');
            return;
        }

        if (preg_match('#^GET /v1/admin/customers/(\d+)$#', $key, $m)) {
            (new AdminCustomersEndpoint())->handle($request, (int) $m[1]);
            return;
        }

        if ($request->path === '/' || $request->path === '/v1') {
            Response::ok([
                'name' => 'Pro-Enroll API',
                'version' => '1.0.0',
                'authorization' => 'Authorization: Bearer <jwt>',
                'auth_endpoints' => [
                    'POST /v1/auth/otp/send' => ['phone_e164' => '+919876543210', 'mode' => 'sign_up'],
                    'POST /v1/auth/otp/verify' => ['request_id' => '…', 'otp' => '123456', 'mode' => 'sign_up'],
                    'POST /v1/auth/refresh' => ['refresh_token' => '…'],
                    'GET /v1/auth/me' => 'Bearer required',
                    'GET /v1/auth/validate' => 'Bearer required',
                    'POST /v1/auth/logout' => 'Bearer required',
                ],
                'screens' => array_keys(self::ROUTES),
            ]);
            return;
        }

        Response::fail('Route not found', 404, 'not_found');
    }
}
