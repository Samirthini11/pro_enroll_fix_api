<?php

/**
 * Smoke-test auth + onboarding + KYC + home screens against local API.
 * Run: php scripts/smoke_all_endpoints.php
 * Requires: php -S localhost:8080 -t public
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/Config.php';

use ProEnroll\Api\Config;

Config::load(dirname(__DIR__));

$base = getenv('API_BASE') ?: (Config::get('APP_URL') ?? 'http://98.93.105.128/pro_enroll_api/public');

function req(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    return ['http' => $code, 'json' => $json, 'raw' => $raw];
}

function assertOk(array $r, string $label): void
{
    $ok = ($r['json']['success'] ?? false) === true;
    if (!$ok) {
        $msg = $r['json']['error']['message'] ?? $r['raw'];
        fwrite(STDERR, "FAIL $label http={$r['http']} $msg\n");
        exit(1);
    }
    echo "OK  $label\n";
}

function assertFail(array $r, string $label, string $code): void
{
    $got = $r['json']['error']['code'] ?? '';
    if (($r['json']['success'] ?? true) !== false || $got !== $code) {
        fwrite(STDERR, "FAIL $label expected error $code got http={$r['http']} raw={$r['raw']}\n");
        exit(1);
    }
    echo "OK  $label ($code)\n";
}

$phone = '+919' . random_int(100000000, 999999999);

echo "=== Auth OTP ===\n";
$send = req('POST', '/v1/auth/otp/send', ['phone_e164' => $phone, 'mode' => 'sign_up']);
assertOk($send, 'otp/send');
$rid = $send['json']['data']['request_id'];
$otp = $send['json']['data']['debug_otp'] ?? null;
if (!$otp) {
    fwrite(STDERR, "Set OTP_DEBUG_RETURN=true for smoke test\n");
    exit(1);
}

$bad = req('POST', '/v1/auth/otp/verify', [
    'request_id' => $rid,
    'otp' => '000000',
    'mode' => 'sign_up',
]);
assertFail($bad, 'otp/verify wrong', 'invalid_otp');

$verify = req('POST', '/v1/auth/otp/verify', [
    'request_id' => $rid,
    'otp' => $otp,
    'mode' => 'sign_up',
]);
assertOk($verify, 'otp/verify');
$token = $verify['json']['data']['access_token'];
$skills = $verify['json']['data']['profile']['skills'] ?? [];

echo "=== Auth session ===\n";
assertOk(req('GET', '/v1/auth/validate', null, $token), 'auth/validate');
assertOk(req('GET', '/v1/auth/me', null, $token), 'auth/me');
assertOk(req('POST', '/v1/screens/auth-otp', ['mode' => 'sign_up'], $token), 'screens/auth-otp');

echo "=== Onboarding (skills) ===\n";
assertOk(req('GET', '/v1/screens/onboard-category', null, $token), 'onboard-category GET');
assertOk(req('PUT', '/v1/screens/onboard-category', ['category_codes' => ['ac', 'plumber']], $token), 'onboard-category PUT');
assertOk(req('PUT', '/v1/screens/onboard-experience', [
    'full_name' => 'Smoke Test Pro',
    'experience_by_category' => ['ac' => 3, 'plumber' => 2],
], $token), 'onboard-experience PUT');
assertOk(req('PUT', '/v1/screens/onboard-location', ['city_id' => 1, 'work_radius_km' => 8], $token), 'onboard-location PUT');
assertOk(req('PUT', '/v1/screens/onboard-fee', ['visit_fee_paise' => 19900], $token), 'onboard-fee PUT');

echo "=== KYC ===\n";
assertOk(req('POST', '/v1/screens/kyc-aadhaar', ['action' => 'initiate', 'aadhaar_last4' => '1234'], $token), 'kyc-aadhaar initiate');
$kycRef = req('POST', '/v1/screens/kyc-aadhaar', ['action' => 'initiate', 'aadhaar_last4' => '1234'], $token)['json']['data']['kyc_ref_id'] ?? '';
assertOk(req('POST', '/v1/screens/kyc-aadhaar', [
    'action' => 'verify',
    'kyc_ref_id' => $kycRef,
    'otp' => '123456',
], $token), 'kyc-aadhaar verify');
assertOk(req('POST', '/v1/screens/kyc-selfie', null, $token), 'kyc-selfie');
assertOk(req('POST', '/v1/screens/kyc-docs', ['documents' => ['tools', 'pan']], $token), 'kyc-docs');

echo "=== Home ===\n";
assertOk(req('GET', '/v1/screens/home-profile', null, $token), 'home-profile');
assertOk(req('GET', '/v1/screens/home-jobs', null, $token), 'home-jobs');
assertOk(req('GET', '/v1/screens/home-earnings', null, $token), 'home-earnings');

$jobs = req('GET', '/v1/screens/home-jobs', null, $token);
$offers = $jobs['json']['data']['offers'] ?? [];
if ($offers !== []) {
    $id = $offers[0]['id'];
    assertOk(req('GET', "/v1/screens/job-offer/$id", null, $token), 'job-offer GET');
}

assertOk(req('POST', '/v1/auth/logout', null, $token), 'logout');

echo "\nAll smoke checks passed for $phone\n";
