-- OpenRanch — assistant API tokens and Telegram linking.
--
-- Adds to customers:
--   api_token         bearer token for /api/v1/, generated on the Assistant page
--   telegram_chat_id  the chat this account is bound to, set by /link
--   link_code         short single-use code the customer types into the bot
--   link_expires      link codes are short-lived; expired ones are refused
--   bot_voice         send a voice note alongside replies
--   bot_briefing      include this account in the 07:00 briefing
--   bot_bind_next     one-shot: bind the next chat that sends /start to this
--                     account, without a code. Cleared the moment it is used.
--
-- Safe to run more than once.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'api_token');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN api_token CHAR(40) DEFAULT NULL',
                     'SELECT "customers.api_token exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'uniq_api_token');
SET @s := IF(@i = 0, 'ALTER TABLE customers ADD UNIQUE KEY uniq_api_token (api_token)',
                     'SELECT "uniq_api_token exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'telegram_chat_id');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN telegram_chat_id BIGINT DEFAULT NULL',
                     'SELECT "customers.telegram_chat_id exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_telegram');
SET @s := IF(@i = 0, 'ALTER TABLE customers ADD KEY idx_telegram (telegram_chat_id)',
                     'SELECT "idx_telegram exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'link_code');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN link_code CHAR(6) DEFAULT NULL',
                     'SELECT "customers.link_code exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'link_expires');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN link_expires DATETIME DEFAULT NULL',
                     'SELECT "customers.link_expires exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'bot_voice');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN bot_voice TINYINT NOT NULL DEFAULT 1',
                     'SELECT "customers.bot_voice exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'bot_briefing');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN bot_briefing TINYINT NOT NULL DEFAULT 1',
                     'SELECT "customers.bot_briefing exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'bot_bind_next');
SET @s := IF(@c = 0, 'ALTER TABLE customers ADD COLUMN bot_bind_next TINYINT NOT NULL DEFAULT 0',
                     'SELECT "customers.bot_bind_next exists" AS note');
PREPARE x FROM @s; EXECUTE x; DEALLOCATE PREPARE x;
