-- OpenRanch — per-zone default run duration, used by the Controls screen.
-- Tapping a zone tile runs it for this many minutes. Safe to run more than once.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'irr_zones' AND COLUMN_NAME = 'default_minutes');
SET @sql := IF(@col = 0,
  'ALTER TABLE irr_zones ADD COLUMN default_minutes DOUBLE NOT NULL DEFAULT 10',
  'SELECT "irr_zones.default_minutes already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
