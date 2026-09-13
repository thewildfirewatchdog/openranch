-- OpenRanch — cameras as a first-class device type.
--
--   devices.is_camera     1 = this device uploads JPEG snapshots
--   snapshots             one row per stored image, so the gallery, the
--                         timeline and retention are all plain SQL rather than
--                         directory scans
--
-- Images live on disk under snapshots/<device-slug>/; only metadata is here.
-- Safe to run more than once.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'is_camera');
SET @s := IF(@c = 0, 'ALTER TABLE devices ADD COLUMN is_camera TINYINT NOT NULL DEFAULT 0',
                     'SELECT "devices.is_camera exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

CREATE TABLE IF NOT EXISTS snapshots (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id   INT NOT NULL,
  customer_id INT DEFAULT NULL,        -- denormalised: retention is per plan
  filename    VARCHAR(64) NOT NULL,    -- basename only; the directory is the slug
  bytes       INT NOT NULL DEFAULT 0,
  width       INT DEFAULT NULL,
  height      INT DEFAULT NULL,
  source      VARCHAR(10) NOT NULL DEFAULT 'auto',  -- auto | manual | mirror
  taken       DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dev_file (device_id, filename),
  KEY idx_dev_time (device_id, taken),
  KEY idx_customer_time (customer_id, taken)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
