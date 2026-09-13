-- OpenRanch — irrigation scheduling and automations
--
-- Eight tables, all namespaced irr_*, all scoped to a customer. Safe to run
-- more than once: every table is CREATE TABLE IF NOT EXISTS and the two ALTERs
-- check information_schema first.
--
-- Nothing here touches devices, readings or commands. The scheduler drives
-- hardware by writing the same `commands` rows the dashboard buttons write, so
-- firmware needs no change: poll.php still returns the latest cmd per device.

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
  default_minutes DOUBLE NOT NULL DEFAULT 10, -- Controls screen: tap = run this long
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
  start_after   DATETIME DEFAULT NULL,
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
  master_lead_seconds INT NOT NULL DEFAULT 15,
  master_close_after  DATETIME DEFAULT NULL,
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
