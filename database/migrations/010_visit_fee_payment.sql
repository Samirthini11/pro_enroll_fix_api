USE pro_enroll;

-- Visit fee collected via app at booking time (repair amount is separate / after job).
ALTER TABLE service_bookings
    ADD COLUMN visit_fee_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER visit_fee_paise,
    ADD COLUMN visit_fee_paid_at DATETIME NULL AFTER visit_fee_paid,
    ADD COLUMN visit_fee_payment_method VARCHAR(32) NULL AFTER visit_fee_paid_at;
