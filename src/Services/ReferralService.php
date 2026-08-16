<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Refer & Earn: pro shares a code; when the referred pro completes one customer
 * job, the referrer receives +1 bonus free booking (platform-fee waiver).
 */
final class ReferralService
{
    private PDO $db;

    private static ?bool $schemaReady = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureSchema(): bool
    {
        if (self::$schemaReady === true) {
            return true;
        }
        if (self::$schemaReady === false) {
            return false;
        }

        try {
            $col = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'referral_code'");
            if (!$col || !$col->fetch()) {
                $this->db->exec(
                    'ALTER TABLE professionals
                     ADD COLUMN referral_code VARCHAR(16) NULL,
                     ADD COLUMN referred_by_professional_id INT UNSIGNED NULL,
                     ADD COLUMN bonus_free_bookings INT UNSIGNED NOT NULL DEFAULT 0'
                );
            } else {
                $bonus = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'bonus_free_bookings'");
                if (!$bonus || !$bonus->fetch()) {
                    $this->db->exec(
                        'ALTER TABLE professionals
                         ADD COLUMN bonus_free_bookings INT UNSIGNED NOT NULL DEFAULT 0'
                    );
                }
                $refBy = $this->db->query(
                    "SHOW COLUMNS FROM professionals LIKE 'referred_by_professional_id'"
                );
                if (!$refBy || !$refBy->fetch()) {
                    $this->db->exec(
                        'ALTER TABLE professionals
                         ADD COLUMN referred_by_professional_id INT UNSIGNED NULL'
                    );
                }
            }

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS pro_referrals (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  referrer_professional_id INT UNSIGNED NOT NULL,
                  referred_professional_id INT UNSIGNED NOT NULL,
                  referral_code VARCHAR(16) NOT NULL,
                  status ENUM('pending', 'rewarded') NOT NULL DEFAULT 'pending',
                  rewarded_at DATETIME NULL,
                  created_at DATETIME NOT NULL,
                  updated_at DATETIME NOT NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_pro_referrals_referred (referred_professional_id),
                  KEY idx_pro_referrals_referrer (referrer_professional_id),
                  KEY idx_pro_referrals_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            try {
                $this->db->exec(
                    'CREATE UNIQUE INDEX uq_professionals_referral_code
                     ON professionals (referral_code)'
                );
            } catch (\Throwable) {
                // Index may already exist.
            }

            self::$schemaReady = true;
            return true;
        } catch (\Throwable) {
            self::$schemaReady = false;
            return false;
        }
    }

    public function ensureReferralCode(int $professionalId): string
    {
        if (!$this->ensureSchema()) {
            return '';
        }

        $stmt = $this->db->prepare(
            'SELECT referral_code FROM professionals WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$professionalId]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return strtoupper($existing);
        }

        for ($i = 0; $i < 8; $i++) {
            $code = 'PE' . strtoupper(bin2hex(random_bytes(3)));
            try {
                $upd = $this->db->prepare(
                    'UPDATE professionals
                     SET referral_code = ?, updated_at = NOW()
                     WHERE id = ? AND (referral_code IS NULL OR referral_code = \'\')'
                );
                $upd->execute([$code, $professionalId]);
                if ($upd->rowCount() > 0) {
                    return $code;
                }
                $stmt->execute([$professionalId]);
                $again = $stmt->fetchColumn();
                if (is_string($again) && $again !== '') {
                    return strtoupper($again);
                }
            } catch (\Throwable) {
                // Collision — retry.
            }
        }

        return '';
    }

    public function bonusFreeBookings(int $professionalId): int
    {
        if (!$this->ensureSchema()) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'SELECT COALESCE(bonus_free_bookings, 0) FROM professionals WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$professionalId]);

        return (int) $stmt->fetchColumn();
    }

    public function consumeBonusFreeBooking(int $professionalId): bool
    {
        if (!$this->ensureSchema()) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE professionals
             SET bonus_free_bookings = bonus_free_bookings - 1, updated_at = NOW()
             WHERE id = ? AND bonus_free_bookings > 0'
        );
        $stmt->execute([$professionalId]);

        return $stmt->rowCount() > 0;
    }

    public function grantBonusFreeBooking(int $professionalId, int $count = 1): void
    {
        if (!$this->ensureSchema() || $count < 1) {
            return;
        }
        $this->db->prepare(
            'UPDATE professionals
             SET bonus_free_bookings = bonus_free_bookings + ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$count, $professionalId]);
    }

    /**
     * Link a new (or not-yet-referred) pro to a referrer via share code.
     *
     * @return array{ok: bool, message: string}
     */
    public function applyReferralCode(int $referredProfessionalId, string $rawCode): array
    {
        if (!$this->ensureSchema()) {
            return ['ok' => false, 'message' => 'Referral is temporarily unavailable'];
        }

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawCode) ?? '');
        if (strlen($code) < 4) {
            return ['ok' => false, 'message' => 'Enter a valid referral code'];
        }

        $me = $this->db->prepare(
            'SELECT id, referral_code, referred_by_professional_id, jobs_completed
             FROM professionals WHERE id = ? LIMIT 1'
        );
        $me->execute([$referredProfessionalId]);
        $row = $me->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['ok' => false, 'message' => 'Professional not found'];
        }

        if (!empty($row['referred_by_professional_id'])) {
            return ['ok' => false, 'message' => 'You already used a referral code'];
        }

        if ((int) ($row['jobs_completed'] ?? 0) > 0) {
            return ['ok' => false, 'message' => 'Referral codes can only be applied before your first completed job'];
        }

        $own = strtoupper((string) ($row['referral_code'] ?? ''));
        if ($own !== '' && $own === $code) {
            return ['ok' => false, 'message' => 'You cannot use your own referral code'];
        }

        $ref = $this->db->prepare(
            'SELECT id, referral_code FROM professionals
             WHERE UPPER(referral_code) = ? LIMIT 1'
        );
        $ref->execute([$code]);
        $referrer = $ref->fetch(PDO::FETCH_ASSOC);
        if ($referrer === false) {
            return ['ok' => false, 'message' => 'Referral code not found'];
        }

        $referrerId = (int) $referrer['id'];
        if ($referrerId === $referredProfessionalId) {
            return ['ok' => false, 'message' => 'You cannot use your own referral code'];
        }

        try {
            $this->db->beginTransaction();
            $upd = $this->db->prepare(
                'UPDATE professionals
                 SET referred_by_professional_id = ?, updated_at = NOW()
                 WHERE id = ? AND referred_by_professional_id IS NULL'
            );
            $upd->execute([$referrerId, $referredProfessionalId]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'You already used a referral code'];
            }

            $ins = $this->db->prepare(
                'INSERT INTO pro_referrals
                   (referrer_professional_id, referred_professional_id, referral_code, status, created_at, updated_at)
                 VALUES (?, ?, ?, \'pending\', NOW(), NOW())'
            );
            $ins->execute([
                $referrerId,
                $referredProfessionalId,
                strtoupper((string) $referrer['referral_code']),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'message' => 'Could not apply referral code'];
        }

        return ['ok' => true, 'message' => 'Referral applied. Complete 1 customer job to reward your friend.'];
    }

    /**
     * After a referred pro's first completed job settles, credit referrer +1 free job.
     */
    public function onReferredProJobSettled(int $referredProfessionalId): void
    {
        if (!$this->ensureSchema()) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id, referrer_professional_id, status
             FROM pro_referrals
             WHERE referred_professional_id = ? AND status = \'pending\'
             LIMIT 1'
        );
        $stmt->execute([$referredProfessionalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            // Fallback: link from professionals.referred_by if referral row missing.
            $pro = $this->db->prepare(
                'SELECT referred_by_professional_id, jobs_completed
                 FROM professionals WHERE id = ? LIMIT 1'
            );
            $pro->execute([$referredProfessionalId]);
            $p = $pro->fetch(PDO::FETCH_ASSOC);
            if ($p === false || empty($p['referred_by_professional_id'])) {
                return;
            }
            if ((int) ($p['jobs_completed'] ?? 0) !== 1) {
                return;
            }
            $referrerId = (int) $p['referred_by_professional_id'];
            $code = $this->ensureReferralCode($referrerId);
            try {
                $this->db->prepare(
                    'INSERT INTO pro_referrals
                       (referrer_professional_id, referred_professional_id, referral_code, status, rewarded_at, created_at, updated_at)
                     VALUES (?, ?, ?, \'rewarded\', NOW(), NOW(), NOW())'
                )->execute([$referrerId, $referredProfessionalId, $code !== '' ? $code : 'LEGACY']);
                $this->grantBonusFreeBooking($referrerId, 1);
            } catch (\Throwable) {
                // Already rewarded or race.
            }
            return;
        }

            $completed = (new BookingRepository())->completedJobsCount($referredProfessionalId);
        // Reward only on the referred pro's first completed customer job.
        if ($completed !== 1) {
            return;
        }

        $referrerId = (int) $row['referrer_professional_id'];
        $upd = $this->db->prepare(
            'UPDATE pro_referrals
             SET status = \'rewarded\', rewarded_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = \'pending\''
        );
        $upd->execute([(int) $row['id']]);
        if ($upd->rowCount() === 0) {
            return;
        }

        $this->grantBonusFreeBooking($referrerId, 1);
    }

    /** @return array<string, mixed> */
    public function payloadForProfessional(int $professionalId): array
    {
        $code = $this->ensureReferralCode($professionalId);
        $bonus = $this->bonusFreeBookings($professionalId);

        $invited = 0;
        $rewarded = 0;
        $pending = 0;
        if ($this->ensureSchema()) {
            $c = $this->db->prepare(
                'SELECT
                   COUNT(*) AS invited,
                   SUM(CASE WHEN status = \'rewarded\' THEN 1 ELSE 0 END) AS rewarded,
                   SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending
                 FROM pro_referrals
                 WHERE referrer_professional_id = ?'
            );
            $c->execute([$professionalId]);
            $stats = $c->fetch(PDO::FETCH_ASSOC) ?: [];
            $invited = (int) ($stats['invited'] ?? 0);
            $rewarded = (int) ($stats['rewarded'] ?? 0);
            $pending = (int) ($stats['pending'] ?? 0);
        }

        $me = $this->db->prepare(
            'SELECT referred_by_professional_id FROM professionals WHERE id = ? LIMIT 1'
        );
        $me->execute([$professionalId]);
        $referredBy = $me->fetchColumn();

        $shareText = $code === ''
            ? 'Join Pro-Enroll — get verified, get local jobs, get paid daily.'
            : "Join Pro-Enroll with my code {$code}. Install the Pro app, enroll, and complete 1 customer job — I earn 1 free job credit, and you start earning nearby!";

        return [
            'referral_code' => $code,
            'share_text' => $shareText,
            'invited_count' => $invited,
            'rewarded_count' => $rewarded,
            'pending_count' => $pending,
            'bonus_free_bookings' => $bonus,
            'has_referrer' => $referredBy !== false && $referredBy !== null && (string) $referredBy !== '',
            'how_it_works' => [
                'Share your referral code with another professional.',
                'They install Pro-Enroll and apply your code.',
                'When they complete 1 customer job, you get +1 free job (no platform fee).',
            ],
        ];
    }
}
