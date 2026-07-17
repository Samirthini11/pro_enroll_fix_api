-- Fix: Unknown column 'display_name' on professionals (live DB / older installs)
-- Safe to run once on pro_enroll. Ignore "Duplicate column name" if already applied.

USE pro_enroll;

ALTER TABLE professionals
    ADD COLUMN display_name VARCHAR(120) NULL AFTER full_name;

-- Optional columns from 011_admin_kyc.sql (skip if you already ran that file)
ALTER TABLE professionals
    ADD COLUMN face_match_score DECIMAL(4,3) NULL AFTER aadhaar_last4;

ALTER TABLE professionals
    ADD COLUMN kyc_rejected_reason VARCHAR(500) NULL AFTER kyc_status;
