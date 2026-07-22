-- Auto-complete unpaid awaiting_payment after 48 hours (customer silent).
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('awaiting_payment_auto_complete_hours', '48', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
