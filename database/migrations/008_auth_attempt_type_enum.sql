-- Fix switch-role / firebase session on live: attempt_type ENUM was missing values.
USE pro_enroll;

ALTER TABLE auth_login_attempts
    MODIFY COLUMN attempt_type ENUM(
        'otp_send',
        'otp_verify',
        'refresh',
        'logout',
        'role_switch',
        'firebase_session'
    ) NOT NULL;
