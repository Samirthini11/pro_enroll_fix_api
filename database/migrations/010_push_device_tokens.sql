-- FCM device tokens for push notifications (Android / iOS).

CREATE TABLE IF NOT EXISTS push_device_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auth_uid VARCHAR(128) NOT NULL,
    phone_e164 VARCHAR(20) NOT NULL,
    fcm_token VARCHAR(512) NOT NULL,
    platform ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
    role ENUM('professional', 'customer') NOT NULL DEFAULT 'professional',
    device_label VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_fcm_token (fcm_token),
    INDEX idx_auth_uid (auth_uid),
    INDEX idx_phone (phone_e164),
    INDEX idx_role (role)
) ENGINE=InnoDB;
