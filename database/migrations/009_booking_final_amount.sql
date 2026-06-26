-- Store pro-entered final bill amount when job completes.
ALTER TABLE service_bookings
    ADD COLUMN final_amount_paise INT UNSIGNED NULL AFTER visit_fee_paise;
