-- Store service start year; experience_years is computed as (IST current year - start year).
ALTER TABLE professional_skills
  ADD COLUMN experience_start_year SMALLINT UNSIGNED NULL AFTER experience_years;

UPDATE professional_skills
SET experience_start_year = GREATEST(
  1970,
  YEAR(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+05:30')) - COALESCE(experience_years, 0)
)
WHERE experience_start_year IS NULL;

ALTER TABLE professional_skills
  MODIFY experience_start_year SMALLINT UNSIGNED NOT NULL;
