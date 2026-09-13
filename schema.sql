-- ---------------------------------------------------------------------------
-- OpenRanch Dashboard — complete schema.
--
-- This is the whole database. Import it once into an empty database and the
-- application runs; there are no follow-up migrations to apply.
--
-- The database name is NOT set here on purpose. install.sh creates the database
-- and selects it before importing, so you can name it whatever you like and
-- this file stays the same. To import by hand:
--
--     mysql -u root -p openranch < schema.sql
--
-- Column and JSON key names are a wire contract shared with device firmware.
-- Renaming `variable`, `value`, `cmd`, or any device variable name will stop
-- existing boards from reporting. Add columns freely; do not rename these.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- customers — dashboard logins. Created by the administrator in admin.php;
-- there is no self-signup. A customer sees only devices assigned to them.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,      -- password_hash(), PASSWORD_DEFAULT
  name          VARCHAR(100) NOT NULL DEFAULT '',
  created       DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- devices — one row per board. A device with enabled=0 is a TEMPLATE: it shows
-- on the dashboard as a shell listing the variables it will report, and
-- ingest.php rejects its data, so half-built units never pile up readings.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  slug              VARCHAR(50) NOT NULL,   -- used in URLs and by boards
  name              VARCHAR(100) NOT NULL,
  token             CHAR(32) NOT NULL,      -- per-device auth token
  variables         TEXT NOT NULL,          -- comma list; ingest drops anything not named here
  commandable       TINYINT DEFAULT 0,      -- 1 = dashboard shows ON/OFF buttons
  enabled           TINYINT DEFAULT 0,      -- 0 = template shell, ingest rejected
  expected_interval INT DEFAULT 60,         -- seconds between reports; 3x this = STALE
  notes             VARCHAR(255) DEFAULT '',
  customer_id       INT DEFAULT NULL,       -- NULL = unassigned / operator-owned
  mac               VARCHAR(17) DEFAULT NULL, -- set by register.php self-provisioning
  UNIQUE KEY slug (slug),
  UNIQUE KEY uniq_mac (mac),                -- many NULLs allowed; real MACs unique
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- readings — the time series. ingest.php self-prunes rows older than
-- RETENTION_DAYS on roughly 2% of requests, so this table stays bounded
-- without a cron job.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS readings (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  variable  VARCHAR(50) NOT NULL,
  value     DOUBLE NOT NULL,
  created   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dev_var_time (device_id, variable, created),
  KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- commands — the command slot each board polls. cmd.php keeps only the most
-- recent five rows per device; poll.php hands back the newest.
--   0 = OFF/stop   1 = ON/start   2 = one-shot action (flow meter: zero total)
--   3,4,5 = board-specific auxiliary codes
-- The meaning of a code is the receiving board's business.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commands (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  cmd       INT NOT NULL,
  created   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dev (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- thresholds — limits per variable, keyed by SLUG rather than device id so a
-- row survives a device being deleted and re-registered. Any of the four
-- limits may be NULL, meaning "no limit on that side".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS thresholds (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(50) NOT NULL,
  variable   VARCHAR(50) NOT NULL,
  low_warn   DOUBLE DEFAULT NULL,
  low_alarm  DOUBLE DEFAULT NULL,
  high_warn  DOUBLE DEFAULT NULL,
  high_alarm DOUBLE DEFAULT NULL,
  enabled    TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_slug_var (slug, variable)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- threshold_state — the ok/warn/alarm decision ingest.php already made for a
-- variable, with hysteresis applied. The dashboard paints this rather than
-- re-deriving it, so one reading produces one decision in one place.
-- `state` and `reason` are wire values read by the UI: do not rename them.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS threshold_state (
  device_id INT NOT NULL,
  variable  VARCHAR(50) NOT NULL,
  state     VARCHAR(5) NOT NULL DEFAULT 'ok',   -- ok | warn | alarm
  value     DOUBLE DEFAULT NULL,
  reason    VARCHAR(16) NOT NULL DEFAULT '',
  updated   DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id, variable)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- alert_state — de-duplication for outage notifications, so a device that has
-- gone quiet generates one message rather than one per check.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_state (
  device_id INT NOT NULL,
  alerted   TINYINT DEFAULT 0,
  updated   DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- push_subscriptions — Web Push endpoints registered by the PWA. Requires a
-- VAPID keypair in config.php; see README.md.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  endpoint    VARCHAR(500) NOT NULL,
  p256dh      VARCHAR(255) NOT NULL,
  auth        VARCHAR(255) NOT NULL,
  ua          VARCHAR(255) NOT NULL DEFAULT '',
  created     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_endpoint (endpoint(255)),
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- EXAMPLE TEMPLATE DEVICES
--
-- All enabled=0, so they render as template shells and reject ingest until you
-- turn one on in admin.php. They exist to show the shape of a device row and to
-- give you something on screen on first load. Delete them and add your own:
--
--     DELETE FROM devices WHERE slug LIKE 'example_%';
--
-- `variables` is the contract: ingest.php silently drops any variable not named
-- in this list, which is what keeps a template clean while it is being built.
-- Tokens are random here; regenerate them from admin.php.
-- ---------------------------------------------------------------------------
INSERT INTO devices (slug, name, token, variables, commandable, enabled, expected_interval, notes) VALUES
('example_pump', 'Example — Pump Controller', MD5(RAND()),
 'engine_state,flow_gpm,battery_v,rssi,heartbeat',
 1, 0, 30, 'Relay-driven pump with a flow sensor. Commandable: ON/OFF.'),

('example_flow_meter', 'Example — Flow Meter', MD5(RAND()),
 'flow_gpm,total_gal,flow_gph,flow_ok,low_flow_alarm,rssi,heartbeat',
 1, 0, 35, 'Reports both flow_gpm and total_gal, so it gets the flow meter card.'),

('example_tank', 'Example — Tank Level', MD5(RAND()),
 'tank_level_pct,float_state',
 0, 0, 60, 'Read-only sensor: no buttons, just values and charts.'),

('example_gauge', 'Example — Pressure Gauge', MD5(RAND()),
 'pressure_psi,rssi,heartbeat',
 0, 0, 30, 'Give it a row in `thresholds` and the card draws a gauge with limits.');
