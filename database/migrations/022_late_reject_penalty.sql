-- Late-reject penalty: a professional may reject a job while on the way (en_route),
-- but a penalty equal to the commission % of the visit fee is charged to the wallet,
-- and a reason is required. Toggle the penalty on/off via platform_settings.

-- Store why a booking was cancelled/rejected (pro or customer).
ALTER TABLE service_bookings
    ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER status;

-- Enable the wallet penalty for late (en_route) rejections. Default ON.
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('late_reject_penalty_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = NOW();
