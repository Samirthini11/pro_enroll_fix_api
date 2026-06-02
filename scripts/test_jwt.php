<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Auth\JwtAuth;
use ProEnroll\Api\Config;

Config::load(dirname(__DIR__));

$token = JwtAuth::issue(['sub' => 'test', 'phone' => '+91999', 'jti' => 'sess-1'], 3600);
echo "issued\n";

try {
    print_r(JwtAuth::verify($token));
    echo "verify ok\n";
} catch (Throwable $e) {
    echo "verify fail: {$e->getMessage()}\n";
}

// HTTP round-trip via file_get_contents
$ch = curl_init('http://localhost:8080/v1/auth/otp/send');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['phone_e164' => '+919877665544', 'mode' => 'sign_up']),
    CURLOPT_RETURNTRANSFER => true,
]);
$send = json_decode(curl_exec($ch), true);
curl_close($ch);

$rid = $send['data']['request_id'];
$otp = $send['data']['debug_otp'] ?? null;
echo "otp=$otp rid=$rid\n";

$ch = curl_init('http://localhost:8080/v1/auth/otp/verify');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'request_id' => $rid,
        'otp' => $otp,
        'mode' => 'sign_up',
    ]),
    CURLOPT_RETURNTRANSFER => true,
]);
$verify = json_decode(curl_exec($ch), true);
curl_close($ch);

$access = $verify['data']['access_token'] ?? '';
echo "access len=" . strlen($access) . "\n";

$ch = curl_init('http://localhost:8080/v1/auth/validate');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $access"],
    CURLOPT_RETURNTRANSFER => true,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "validate http=$code body=$body\n";
