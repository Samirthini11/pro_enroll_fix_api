<?php

declare(strict_types=1);

namespace ProEnroll\Api\Auth;

use Kreait\Firebase\Contract\Auth as FirebaseAuthContract;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

/**
 * Verifies Firebase ID tokens issued after Phone Auth sign-in.
 */
final class FirebaseAuth
{
    private static ?FirebaseAuthContract $auth = null;

    public static function isConfigured(): bool
    {
        return FirebaseCredentials::isAuthEnabled();
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

        self::$auth = FirebaseCredentials::factory()->createAuth();

        return self::$auth;
    }
}
