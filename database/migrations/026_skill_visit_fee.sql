-- Per-service visit fees on professional_skills (fallback remains professionals.visit_fee_paise).
ALTER TABLE professional_skills
  ADD COLUMN visit_fee_paise INT UNSIGNED NOT NULL DEFAULT 15000 AFTER is_primary;

UPDATE professional_skills ps
INNER JOIN professionals p ON p.id = ps.professional_id
SET ps.visit_fee_paise = COALESCE(NULLIF(p.visit_fee_paise, 0), 15000);
