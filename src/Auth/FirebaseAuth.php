<?php

declare(strict_types=1);

namespace ProEnroll\Api\Auth;

use Kreait\Firebase\Contract\Auth as FirebaseAuthContract;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use ProEnroll\Api\Config;

/**
 * Verifies Firebase ID tokens issued after Phone Auth sign-in.
 */
final class FirebaseAuth
{
    private static ?FirebaseAuthContract $auth = null;

    public static function isConfigured(): bool
    {
        if (Config::bool('FIREBASE_AUTH_DISABLED', false)) {
            return false;
        }

        $path = self::credentialsPath();
        return $path !== null && is_readable($path);
    }

    public static function verifyIdToken(string $idToken): array
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Firebase credentials are not configured');
        }

        try {
            $verified = self::instance()->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            throw new \RuntimeException('Invalid Firebase ID token', 0, $e);
        }

        $claims = $verified->claims()->all();
        $uid = (string) ($claims['sub'] ?? '');
        if ($uid === '') {
            throw new \RuntimeException('Firebase token missing sub claim');
        }

        return [
            'sub' => $uid,
            'phone' => isset($claims['phone_number']) ? (string) $claims['phone_number'] : null,
            'email' => isset($claims['email']) ? (string) $claims['email'] : null,
        ];
    }

    private static function instance(): FirebaseAuthContract
    {
        if (self::$auth !== null) {
            return self::$auth;
        }

        $path = self::credentialsPath();
        if ($path === null || !is_readable($path)) {
            throw new \RuntimeException('Firebase service account JSON not found');
        }

        self::$auth = (new Factory())
            ->withServiceAccount($path)
            ->createAuth();

        return self::$auth;
    }

    private static function credentialsPath(): ?string
    {
        $configured = Config::get('FIREBASE_CREDENTIALS');
        if ($configured !== null && $configured !== '') {
            if (is_readable($configured)) {
                return $configured;
            }

            $rootRelative = dirname(__DIR__, 2) . '/' . ltrim($configured, '/');
            if (is_readable($rootRelative)) {
                return $rootRelative;
            }
        }

        $default = dirname(__DIR__, 2) . '/config/firebase-service-account.json';
        return is_readable($default) ? $default : null;
    }
}
