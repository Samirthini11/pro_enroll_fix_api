USE pro_enroll;

-- Marks when a booking credit was paid out to the professional.
-- Wallet balance = SUM(pro_credit) WHERE paid_out_at IS NULL.
ALTER TABLE service_bookings
    ADD COLUMN paid_out_at DATETIME NULL AFTER pro_credit_paise;
