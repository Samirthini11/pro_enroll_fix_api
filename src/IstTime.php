<?php

declare(strict_types=1);

namespace ProEnroll\Api;

/**
 * All API datetimes are India Standard Time (Asia/Kolkata, UTC+05:30).
 */
final class IstTime
{
    public const ZONE = 'Asia/Kolkata';
    public const OFFSET = '+05:30';

    public static function bootstrap(): void
    {
        date_default_timezone_set(self::ZONE);
    }

    /**
     * Format a MySQL DATETIME / unix timestamp as ISO-8601 with +05:30.
     * Treats naive DB values as already being IST wall-clock.
     */
    public static function format(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || trim($mysqlDatetime) === '') {
            return null;
        }

        $raw = trim($mysqlDatetime);
        // Already has offset / Z
        if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $raw) === 1) {
            try {
                $dt = new \DateTimeImmutable($raw);
                return $dt->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d\TH:i:sP');
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone(self::ZONE));

            return $dt->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            $ts = strtotime($raw);
            if ($ts === false) {
                return null;
            }

            return (new \DateTimeImmutable('@' . $ts))
                ->setTimezone(new \DateTimeZone(self::ZONE))
                ->format('Y-m-d\TH:i:sP');
        }
    }

    public static function formatTs(int $unixTs): string
    {
        return (new \DateTimeImmutable('@' . $unixTs))
            ->setTimezone(new \DateTimeZone(self::ZONE))
            ->format('Y-m-d\TH:i:sP');
    }

    /** Current IST as MySQL DATETIME. */
    public static function nowMysql(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONE)))->format('Y-m-d H:i:s');
    }
}
