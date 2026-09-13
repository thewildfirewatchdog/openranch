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
  plan VARCHAR(16) NOT NULL DEFAULT 'free',  -- 'free' (FREE_DEVICE_LIMIT applies) or 'pro'
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
  claim_code        CHAR(6) DEFAULT NULL,   -- single-use code; NULL once claimed
  UNIQUE KEY slug (slug),
  UNIQUE KEY uniq_mac (mac),                -- many NULLs allowed; real MACs unique
  UNIQUE KEY uniq_claim_code (claim_code),  -- likewise: many NULLs, live codes unique
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

-- ===========================================================================
-- Irrigation scheduling and automations. Everything below is namespaced irr_*
-- and scoped to a customer. An install that does not irrigate simply never
-- writes to these tables; nothing else in the schema references them.
-- ===========================================================================

-- Zones: one output device, optionally a flow meter and a soil probe. ---------
CREATE TABLE IF NOT EXISTS irr_zones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  customer_id     INT NOT NULL,
  name            VARCHAR(80) NOT NULL,
  device_id       INT NOT NULL,              -- the valve/pump; commandable = 1
  cmd_on          TINYINT NOT NULL DEFAULT 1, -- code sent to open
  cmd_off         TINYINT NOT NULL DEFAULT 0, -- code sent to close
  flow_device_id  INT DEFAULT NULL,
  flow_variable   VARCHAR(50) NOT NULL DEFAULT 'flow_gpm',
  total_variable  VARCHAR(50) NOT NULL DEFAULT 'total_gal',
  soil_device_id  INT DEFAULT NULL,
  soil_variable   VARCHAR(50) NOT NULL DEFAULT 'moisture',
  soil_skip_above DOUBLE DEFAULT NULL,       -- NULL = never skip on moisture
  is_master       TINYINT NOT NULL DEFAULT 0,-- opens before, closes after others
  sort_order      INT NOT NULL DEFAULT 0,
  enabled         TINYINT NOT NULL DEFAULT 1,
  created         DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id),
  KEY idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Programs: when to run. ------------------------------------------------------
CREATE TABLE IF NOT EXISTS irr_programs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT NOT NULL,
  name           VARCHAR(80) NOT NULL,
  enabled        TINYINT NOT NULL DEFAULT 1,
  start_times    VARCHAR(255) NOT NULL DEFAULT '06:00',  -- comma list, HH:MM local
  days_mode      VARCHAR(10) NOT NULL DEFAULT 'dow',     -- 'dow' | 'interval'
  days_of_week   VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6', -- 0=Sun
  interval_days  INT NOT NULL DEFAULT 2,
  interval_anchor DATE DEFAULT NULL,          -- day 0 of the interval cycle
  seasonal_pct   INT NOT NULL DEFAULT 100,    -- scales every duration
  sequential     TINYINT NOT NULL DEFAULT 1,  -- 0 = all zones together
  weather_adjust TINYINT NOT NULL DEFAULT 1,
  created        DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which zones a program waters, for how long, in what order. ------------------
CREATE TABLE IF NOT EXISTS irr_program_zones (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  zone_id    INT NOT NULL,
  minutes    DOUBLE NOT NULL DEFAULT 10,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pz (program_id, zone_id),
  KEY idx_program (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every watering, planned or finished. Also the scheduler's work queue. -------
CREATE TABLE IF NOT EXISTS irr_runs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT NOT NULL,
  zone_id       INT NOT NULL,
  program_id    INT DEFAULT NULL,
  source        VARCHAR(10) NOT NULL DEFAULT 'program', -- program|manual|once|rule
  status        VARCHAR(10) NOT NULL DEFAULT 'queued',  -- queued|running|done|stopped
  seq           INT NOT NULL DEFAULT 0,
  planned_min   DOUBLE NOT NULL DEFAULT 0,
  queued_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  started       DATETIME DEFAULT NULL,
  ends_at       DATETIME DEFAULT NULL,
  ended         DATETIME DEFAULT NULL,
  start_total   DOUBLE DEFAULT NULL,   -- flow meter total_gal when it opened
  gallons       DOUBLE DEFAULT NULL,   -- filled in when it closes
  KEY idx_customer_time (customer_id, started),
  KEY idx_status (status),
  KEY idx_zone (zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Why a watering did not happen. ---------------------------------------------
CREATE TABLE IF NOT EXISTS irr_skips (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  program_id  INT DEFAULT NULL,
  zone_id     INT DEFAULT NULL,
  reason      VARCHAR(24) NOT NULL,    -- rain|forecast|soil|delay|disabled|no_zones
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  created     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer_time (customer_id, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One settings row per customer. ---------------------------------------------
CREATE TABLE IF NOT EXISTS irr_settings (
  customer_id      INT PRIMARY KEY,
  lat              DOUBLE DEFAULT NULL,
  lon              DOUBLE DEFAULT NULL,
  zip              VARCHAR(16) NOT NULL DEFAULT '',
  weather_enabled  TINYINT NOT NULL DEFAULT 1,
  rain_skip_mm     DOUBLE NOT NULL DEFAULT 6,   -- skip above this, past or forecast
  temp_baseline_c  DOUBLE NOT NULL DEFAULT 21,  -- no scaling at this max temp
  temp_pct_per_c   DOUBLE NOT NULL DEFAULT 3,   -- +/- % of duration per degree
  rain_delay_until DATETIME DEFAULT NULL,
  leak_minutes     INT NOT NULL DEFAULT 10,     -- unscheduled flow tolerated
  leak_min_gpm     DOUBLE NOT NULL DEFAULT 0.2, -- below this is meter noise
  weather_json     TEXT DEFAULT NULL,           -- cached Open-Meteo response
  weather_at       DATETIME DEFAULT NULL        -- cached at; refetched hourly
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rules: IF <device.metric> <op> <value> [for N min] THEN <action>. -----------
CREATE TABLE IF NOT EXISTS irr_rules (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT NOT NULL,
  name           VARCHAR(80) NOT NULL,
  enabled        TINYINT NOT NULL DEFAULT 1,
  device_id      INT NOT NULL,
  metric         VARCHAR(50) NOT NULL,
  op             VARCHAR(4) NOT NULL DEFAULT '>',  -- > >= < <= == !=
  value          DOUBLE NOT NULL DEFAULT 0,
  for_minutes    INT NOT NULL DEFAULT 0,
  action         VARCHAR(10) NOT NULL DEFAULT 'notify', -- notify|command|zone
  action_device_id INT DEFAULT NULL,
  action_cmd     TINYINT DEFAULT NULL,
  action_zone_id INT DEFAULT NULL,
  action_minutes DOUBLE NOT NULL DEFAULT 10,       -- for action = zone
  message        VARCHAR(255) NOT NULL DEFAULT '',
  cooldown_min   INT NOT NULL DEFAULT 30,
  met_since      DATETIME DEFAULT NULL,            -- condition true since
  last_fired     DATETIME DEFAULT NULL,
  created        DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unscheduled-flow watch, one row per zone that has a flow meter. ------------
CREATE TABLE IF NOT EXISTS irr_leak_state (
  zone_id     INT PRIMARY KEY,
  customer_id INT NOT NULL,
  since       DATETIME DEFAULT NULL,   -- flow first seen with nothing running
  notified    DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which program start times have already fired, so a restart inside the same
-- minute cannot water twice. One row per program per local start slot.
CREATE TABLE IF NOT EXISTS irr_fires (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  fire_key   VARCHAR(32) NOT NULL,   -- 'YYYY-MM-DD HH:MM' in the local zone
  created    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fire (program_id, fire_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
