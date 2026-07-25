-- Prepaid wallet for professionals:
-- first 5 jobs free, then maintain min ₹50, 10% of visit fee deducted per job.
-- Recharge via company UPI + UTR.

CREATE TABLE IF NOT EXISTS pro_wallet_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    professional_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(32) NOT NULL,
    amount_paise INT NOT NULL COMMENT '+ credit / - debit',
    balance_after_paise INT NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    utr VARCHAR(64) NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pro_wallet_created (professional_id, created_at),
    KEY idx_pro_wallet_booking (booking_id),
    UNIQUE KEY uq_pro_wallet_utr (utr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('visit_commission_percent', '10', NOW()),
    ('wallet_min_accept_paise', '5000', NOW()),
    ('wallet_recharge_min_paise', '5000', NOW()),
    ('free_booking_limit', '5', NOW())
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = NOW();
