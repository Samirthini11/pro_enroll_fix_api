-- KYC document file URL for S3 objects
-- 030_pro_documents_file_url.sql

ALTER TABLE pro_documents
    ADD COLUMN IF NOT EXISTS file_url VARCHAR(1024) NULL AFTER status;
