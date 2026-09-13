-- OpenRanch — device claiming + free tier
--
-- Adds:
--   devices.claim_code   the single-use code a board shows so its owner can
--                        claim it. NULL once spent (or never minted).
--   customers.plan       'free' or 'pro'. Only 'pro' escapes FREE_DEVICE_LIMIT.
--
-- Safe to run more than once: every step checks information_schema first.
-- Existing rows are untouched -- devices keep claim_code NULL (nothing to
-- claim, they are already assigned or are admin-created templates) and every
-- existing customer lands on 'free'.
--
-- Mirrored rows (devices.is_mirrored = 1) are deliberately not special-cased
-- here. They simply never get a claim_code: rcr-mirror.php writes an explicit
-- column list that does not include it, so the DEFAULT NULL stands, and
-- claim.php refuses is_mirrored rows outright.

-- devices.claim_code --------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'claim_code');
SET @sql := IF(@col = 0,
  'ALTER TABLE devices ADD COLUMN claim_code CHAR(6) NULL DEFAULT NULL',
  'SELECT "devices.claim_code already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- UNIQUE so two boards can never hold the same code. MySQL/MariaDB allow any
-- number of NULLs in a unique index, which is what a spent or never-minted
-- code is, so this does not stop many devices sitting at NULL together.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'devices' AND INDEX_NAME = 'uniq_claim_code');
SET @sql := IF(@idx = 0,
  'ALTER TABLE devices ADD UNIQUE KEY uniq_claim_code (claim_code)',
  'SELECT "devices.uniq_claim_code already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- customers.plan ------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'plan');
SET @sql := IF(@col = 0,
  'ALTER TABLE customers ADD COLUMN plan VARCHAR(16) NOT NULL DEFAULT ''free''',
  'SELECT "customers.plan already exists" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
