ALTER TABLE professionals
  ADD COLUMN can_edit_experience TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS experience_edit_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  professional_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('pending', 'approved', 'rejected', 'used') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(500) NULL,
  reviewed_by VARCHAR(128) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_exp_edit_status (status),
  KEY idx_exp_edit_pro (professional_id),
  KEY idx_exp_edit_pro_status (professional_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
