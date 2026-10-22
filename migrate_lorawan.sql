-- OpenRanch — LoRaWAN devices are found by DevEUI (see lorawan.php).
--
--   devices.dev_eui   16 uppercase hex characters, NULL for WiFi boards
--
-- Safe to run more than once.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'dev_eui');
SET @s := IF(@c = 0, 'ALTER TABLE devices ADD COLUMN dev_eui CHAR(16) DEFAULT NULL',
                     'SELECT "dev_eui exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'devices' AND INDEX_NAME = 'uniq_dev_eui');
SET @s := IF(@i = 0, 'ALTER TABLE devices ADD UNIQUE KEY uniq_dev_eui (dev_eui)',
                     'SELECT "uniq_dev_eui exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
