-- OpenRanch — master valve lead time.
--
-- The master valve should be fully open before the first zone opens, and stay
-- open until after the last one has closed, so a zone never opens against a
-- shut master. That needs three pieces of state:
--
--   irr_settings.master_lead_seconds  how long the lead is (0 disables it)
--   irr_settings.master_close_after   when the master may finally close
--   irr_runs.start_after              earliest a run may open, set during phase 1
--
-- Safe to run more than once.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'irr_settings' AND COLUMN_NAME = 'master_lead_seconds');
SET @sql := IF(@col = 0,
  'ALTER TABLE irr_settings ADD COLUMN master_lead_seconds INT NOT NULL DEFAULT 15',
  'SELECT "irr_settings.master_lead_seconds already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'irr_settings' AND COLUMN_NAME = 'master_close_after');
SET @sql := IF(@col = 0,
  'ALTER TABLE irr_settings ADD COLUMN master_close_after DATETIME DEFAULT NULL',
  'SELECT "irr_settings.master_close_after already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'irr_runs' AND COLUMN_NAME = 'start_after');
SET @sql := IF(@col = 0,
  'ALTER TABLE irr_runs ADD COLUMN start_after DATETIME DEFAULT NULL',
  'SELECT "irr_runs.start_after already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
