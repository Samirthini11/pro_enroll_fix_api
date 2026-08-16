-- Refer & Earn: share code; when referred pro completes 1 job, referrer gets +1 free booking.
ALTER TABLE professionals
  ADD COLUMN referral_code VARCHAR(16) NULL AFTER language_code,
  ADD COLUMN referred_by_professional_id INT UNSIGNED NULL AFTER referral_code,
  ADD COLUMN bonus_free_bookings INT UNSIGNED NOT NULL DEFAULT 0 AFTER free_bookings_used;

CREATE UNIQUE INDEX uq_professionals_referral_code ON professionals (referral_code);
CREATE INDEX idx_professionals_referred_by ON professionals (referred_by_professional_id);

CREATE TABLE IF NOT EXISTS pro_referrals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
