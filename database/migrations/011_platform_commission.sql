USE pro_enroll;

-- Platform knobs (editable in DB; .env can override as fallback).
CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('visit_commission_percent', '5', NOW()),
    ('free_booking_limit', '5', NOW()),
    ('hold_pro_after_free_limit', '1', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Per-booking commission audit (commission on visit fee only).
ALTER TABLE service_bookings
    ADD COLUMN commission_paise INT UNSIGNED NOT NULL DEFAULT 0 AFTER visit_fee_payment_method,
    ADD COLUMN pro_credit_paise INT UNSIGNED NULL AFTER commission_paise,
    ADD COLUMN commission_waived TINYINT(1) NOT NULL DEFAULT 0 AFTER pro_credit_paise;

-- Free-tier tracking + hold (hidden from customer search when held).
ALTER TABLE professionals
    ADD COLUMN free_bookings_used INT UNSIGNED NOT NULL DEFAULT 0 AFTER jobs_completed,
    ADD COLUMN listing_held TINYINT(1) NOT NULL DEFAULT 0 AFTER free_bookings_used;
