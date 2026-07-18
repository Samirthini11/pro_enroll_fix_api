<?php

declare(strict_types=1);

namespace ProEnroll\Api;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    /** MySQL hostname only — not the website URL (use APP_URL for http://98.93.105.128/...). */
    public static function resolveHost(?string $raw): string
    {
        $host = trim((string) $raw);
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = rtrim($host, '/');
        $host = explode('/', $host)[0];

        // API and MySQL on same VPS — connect via loopback, not public IP
        if ($host === '98.93.105.128') {
            return '127.0.0.1';
        }

        return $host !== '' ? $host : '127.0.0.1';
    }

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = self::resolveHost(Config::get('DB_HOST', '127.0.0.1'));
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME', 'pro_enroll');
        $user = Config::get('DB_USER', 'proadmin');
        $pass = Config::get('DB_PASS', 'Krishna@123');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Store / read DATETIME in India Standard Time.
            self::$pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }
}
