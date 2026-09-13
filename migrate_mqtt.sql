-- OpenRanch — MQTT access per customer.
--
--   customers.mqtt_username   openranch_<id>, set when MQTT is switched on
--   customers.mqtt_enabled    publish this customer's readings to the broker
--   customers.share_token     public read-only status page (see /s/<token>)
--   customers.share_enabled
--
-- Safe to run more than once.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'mqtt_username');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN mqtt_username VARCHAR(40) DEFAULT NULL',
                     'SELECT "mqtt_username exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'mqtt_enabled');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN mqtt_enabled TINYINT NOT NULL DEFAULT 0',
                     'SELECT "mqtt_enabled exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'share_token');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN share_token CHAR(32) DEFAULT NULL',
                     'SELECT "share_token exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND INDEX_NAME = 'uniq_share_token');
SET @s := IF(@i = 0, 'ALTER TABLE customers ADD UNIQUE KEY uniq_share_token (share_token)',
                     'SELECT "uniq_share_token exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'share_enabled');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN share_enabled TINYINT NOT NULL DEFAULT 0',
                     'SELECT "share_enabled exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

-- Where the MQTT bridge got to, so a restart does not republish history.
CREATE TABLE IF NOT EXISTS mqtt_state (
  k VARCHAR(40) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How many Claude-written notices each customer has had today, so the daily
-- cap can be enforced without scanning the notification log.
CREATE TABLE IF NOT EXISTS notice_budget (
  customer_id INT NOT NULL,
  day         DATE NOT NULL,
  used        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional monthly water-use email, sent on the 1st.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'monthly_email');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN monthly_email TINYINT NOT NULL DEFAULT 0',
                     'SELECT "monthly_email exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
