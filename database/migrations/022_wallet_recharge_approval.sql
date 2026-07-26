USE pro_enroll;

-- Wallet recharge requests need admin approval before the wallet is credited.

CREATE TABLE IF NOT EXISTS pro_wallet_recharges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    professional_id INT UNSIGNED NOT NULL,
    amount_paise INT NOT NULL,
    utr VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    note VARCHAR(255) NULL,
    rejected_reason VARCHAR(500) NULL,
    ledger_id BIGINT UNSIGNED NULL,
    reviewed_by VARCHAR(64) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recharge_utr (utr),
    KEY idx_recharge_queue (status, created_at),
    KEY idx_recharge_pro (professional_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pro_wallet_ledger
    ADD COLUMN recharge_id BIGINT UNSIGNED NULL AFTER booking_id;
