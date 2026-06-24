-- Auth tables migration (run on existing pro_enroll database)
USE pro_enroll;

CREATE TABLE IF NOT EXISTS auth_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auth_uid VARCHAR(128) NOT NULL UNIQUE,
    phone_e164 VARCHAR(20) NOT NULL UNIQUE,
    professional_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    phone_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_auth_professional (professional_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL UNIQUE,
    auth_account_id BIGINT UNSIGNED NOT NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    access_expires_at DATETIME NOT NULL,
    refresh_expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    device_label VARCHAR(120) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_auth_sessions_account (auth_account_id),
    INDEX idx_auth_sessions_refresh (refresh_token_hash),
    INDEX idx_auth_sessions_revoked (revoked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone_e164 VARCHAR(20) NOT NULL,
    attempt_type ENUM('otp_send', 'otp_verify', 'refresh', 'logout') NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_login_phone_time (phone_e164, created_at)
) ENGINE=InnoDB;

-- Extend otp_requests (ignore errors if columns already exist)
ALTER TABLE otp_requests
    ADD COLUMN IF NOT EXISTS purpose ENUM('sign_in', 'sign_up') NOT NULL DEFAULT 'sign_up' AFTER phone_e164,
    ADD COLUMN IF NOT EXISTS attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_code,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER verified_at,
    ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER ip_address;
