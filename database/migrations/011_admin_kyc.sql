-- Admin approval app: KYC review fields + pro documents

USE pro_enroll;

ALTER TABLE professionals
    ADD COLUMN display_name VARCHAR(120) NULL AFTER full_name;

ALTER TABLE professionals
    ADD COLUMN face_match_score DECIMAL(4,3) NULL AFTER aadhaar_last4;

ALTER TABLE professionals
    ADD COLUMN kyc_rejected_reason VARCHAR(500) NULL AFTER kyc_status;

CREATE TABLE IF NOT EXISTS pro_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('aadhaar', 'pan', 'selfie', 'shop_photo', 'cert', 'other') NOT NULL,
    label VARCHAR(120) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    thumbnail_url VARCHAR(512) NULL,
    rejected_reason VARCHAR(500) NULL,
    uploaded_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    INDEX idx_pro_doc_pro (professional_id),
    INDEX idx_pro_doc_queue (status, kind)
) ENGINE=InnoDB;
