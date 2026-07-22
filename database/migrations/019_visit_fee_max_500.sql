-- Raise visit fee cap to ₹500 (50000 paise).
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('visit_fee_max_paise', '50000', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
