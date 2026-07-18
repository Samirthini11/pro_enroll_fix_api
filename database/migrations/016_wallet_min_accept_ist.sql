USE pro_enroll;

-- Accept timestamp (stored as IST wall-clock when DB TZ is +05:30).
ALTER TABLE service_bookings
    ADD COLUMN accepted_at DATETIME NULL AFTER updated_at;

-- Net wallet floor for accepting jobs: credits − unpaid platform fee >= -₹200.
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('wallet_min_accept_paise', '-20000', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW();
