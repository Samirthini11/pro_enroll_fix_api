USE pro_enroll;

-- UTR entered by professional after paying platform fee to company UPI.
ALTER TABLE service_bookings
    ADD COLUMN commission_upi_utr VARCHAR(64) NULL AFTER commission_upi_paid_at;
