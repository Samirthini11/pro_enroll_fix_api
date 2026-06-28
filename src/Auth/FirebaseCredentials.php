<?php

declare(strict_types=1);

namespace ProEnroll\Api\Auth;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use ProEnroll\Api\Config;

/**
 * Resolves Firebase Admin service account JSON for HTTP v1 (FCM send + ID token verify).
 *
 * google-services.json and the Android API key are client-only — they cannot send push.
 * The server needs this service account file; Kreait obtains OAuth access tokens from it.
 */
final class FirebaseCredentials
{
    private static ?Factory $factory = null;

    private static ?Messaging $messaging = null;

    /** Empty string = resolved but not found. */
    private static ?string $resolvedPath = null;

    /** @var array<string, mixed>|null|null null = not yet parsed, [] = invalid */
    private static ?array $parsedCache = null;

    public static function isAvailable(): bool
    {
        return self::parsedAccount() !== null;
    }

    public static function isAuthEnabled(): bool
    {
        if (Config::bool('FIREBASE_AUTH_DISABLED', false)) {
            return false;
        }

        return self::isAvailable();
    }

    public static function projectId(): ?string
    {
        $parsed = self::parsedAccount();

        return isset($parsed['project_id']) ? (string) $parsed['project_id'] : Config::get('FIREBASE_PROJECT_ID');
    }

    public static function resolvePath(): ?string
    {
        if (self::$resolvedPath !== null) {
            return self::$resolvedPath !== '' ? self::$resolvedPath : null;
        }

        $root = dirname(__DIR__, 2);
        $candidates = [];

        $configured = Config::get('FIREBASE_CREDENTIALS');
        if ($configured !== null && $configured !== '') {
            $candidates[] = $configured;
            $candidates[] = $root . '/' . ltrim(str_replace('\\', '/', $configured), '/');
        }

        $candidates[] = $root . '/config/firebase-service-account.json';

        $googleAppCreds = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if (is_string($googleAppCreds) && $googleAppCreds !== '') {
            $candidates[] = $googleAppCreds;
        }

        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                self::$resolvedPath = $path;

                return $path;
            }
        }

        self::$resolvedPath = '';

        return null;
    }

    public static function factory(): Factory
    {
        if (self::$factory !== null) {
            return self::$factory;
        }

        $path = self::resolvePath();
        if ($path === null || self::parsedAccount() === null) {
            throw new \RuntimeException(self::setupHint());
        }

        self::$factory = (new Factory())->withServiceAccount($path);

        return self::$factory;
    }

    public static function messaging(): Messaging
    {
        if (self::$messaging !== null) {
            return self::$messaging;
        }

        self::$messaging = self::factory()->createMessaging();

        return self::$messaging;
    }

    public static function setupHint(): string
    {
        return 'Firebase service account JSON missing or invalid. Download from Firebase Console → '
            . 'Project settings → Service accounts → Generate new private key, save as '
            . 'config/firebase-service-account.json, set FIREBASE_CREDENTIALS=config/firebase-service-account.json in .env. '
            . 'google-services.json cannot send push from the server.';
    }

    /** @return array<string, mixed> */
    public static function diagnostics(): array
    {
        $path = self::resolvePath();
        $parsed = self::parsedAccount();
        $oauthOk = false;
        $oauthError = null;

        if ($parsed !== null) {
            try {
                self::messaging();
                $oauthOk = true;
            } catch (\Throwable $e) {
                $oauthError = $e->getMessage();
            }
        }

        return [
            'fcm_http_v1_ready' => $parsed !== null && $oauthOk,
            'credentials_found' => $path !== null,
            'credentials_valid' => $parsed !== null,
            'credentials_path' => $path,
            'project_id' => self::projectId(),
            'client_email' => $parsed['client_email'] ?? null,
            'oauth_messaging_ok' => $oauthOk,
            'oauth_error' => $oauthError,
            'firebase_auth_disabled' => Config::bool('FIREBASE_AUTH_DISABLED', false),
            'env_FIREBASE_CREDENTIALS' => Config::get('FIREBASE_CREDENTIALS'),
            'note' => 'Server push uses FCM HTTP v1 + service account OAuth. google-services.json is Android client-only.',
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parsedAccount(): ?array
    {
        if (self::$parsedCache !== null) {
            return self::$parsedCache !== [] ? self::$parsedCache : null;
        }

        self::$parsedCache = [];

        $path = self::resolvePath();
        if ($path === null) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account') {
            return null;
        }

        foreach (['project_id', 'private_key', 'client_email', 'token_uri'] as $key) {
            if (!isset($data[$key]) || (string) $data[$key] === '') {
                return null;
            }
        }

        self::$parsedCache = $data;

        return $data;
    }
}
