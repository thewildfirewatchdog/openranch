<?php
// OpenRanch — device claiming helpers
//
// Shared by register.php (mints a code) and claim.php (spends it). Kept in one
// file so the alphabet and the sensor-type table cannot drift apart.

require_once __DIR__ . '/session_compat.php';

// Claim-code alphabet: uppercase, with 0/O and 1/I removed so a code read off a
// board's display or a label is not ambiguous. 32 characters, 6 positions, so
// 32^6 = ~1.07e9 codes -- collisions are handled by retrying, not hoped away.
const CLAIM_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
const CLAIM_LEN      = 6;

// Normalise what a human typed: drop spaces, dashes and any other punctuation
// someone might add while reading a code aloud, then uppercase it.
//
// No character folding. The alphabet drops one of each ambiguous pair -- 0 and
// O, 1 and I -- keeping the letter in the first case and neither in the second,
// so a code can never contain 0, O, 1 or I. A typed O is therefore a misreading
// of some other character, not of a 0 that was never there, and there is
// nothing to map it onto. Guessing would risk claiming the wrong device, so a
// code that is not exactly right is simply rejected.
function claim_normalise($s) {
  return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
}

// Valid only if every character is in the alphabet -- which rejects 0, O, 1
// and I outright. L is kept: with 1 gone there is nothing left to confuse it
// with.
function claim_valid($code) {
  if (strlen($code) !== CLAIM_LEN) return false;
  for ($i = 0; $i < CLAIM_LEN; $i++) {
    if (strpos(CLAIM_ALPHABET, $code[$i]) === false) return false;
  }
  return true;
}

function claim_generate() {
  $max = strlen(CLAIM_ALPHABET) - 1;
  $out = '';
  for ($i = 0; $i < CLAIM_LEN; $i++) $out .= CLAIM_ALPHABET[random_int(0, $max)];
  return $out;
}

// Mint a code that no device currently holds. UNIQUE on the column is the real
// guard; this just avoids burning an INSERT on a collision. After a few tries
// something is badly wrong (or the table is full), so give up rather than spin.
function claim_unique_code(PDO $db) {
  $chk = $db->prepare('SELECT 1 FROM devices WHERE claim_code = ?');
  for ($try = 0; $try < 12; $try++) {
    $code = claim_generate();
    $chk->execute([$code]);
    if (!$chk->fetch()) return $code;
  }
  return null;
}

// ---- sensor type -----------------------------------------------------------
// A device announces the variables it reports; that list is the only reliable
// signal of what it physically is, so the claim names the device from it. Order
// matters: the first rule whose required variables are all present wins, so the
// specific combinations sit above the single-variable catch-alls.
const CLAIM_TYPES = [
  ['Camera',              ['snapshot']],
  ['Flow Meter',          ['flow_gpm', 'total_gal']],
  ['Flow Meter',          ['flow_gpm', 'total_gallons']],
  ['Flame / Smoke Sensor',['flame_status', 'smoke_ppm']],
  ['Flame Sensor',        ['flame_status']],
  ['Tank Level',          ['tank_level_pct']],
  ['Float Switch',        ['float_state']],
  ['Pressure Gauge',      ['pressure_psi']],
  ['Camera Trigger',      ['pir_active']],
  ['Sprinkler',           ['sprinkler_state']],
  ['Pump',                ['pump_state']],
  ['Pump',                ['engine_state']],
  ['Weather Station',     ['moisture', 'rain']],
  ['Temp / Humidity',     ['temp_c', 'humidity_pct']],
  ['Battery Monitor',     ['batt1_v']],
  ['Battery Monitor',     ['battery_v']],
];

function claim_type_label($variables, $isCamera = false) {
  // A camera has no variables worth naming it from, so the flag decides.
  if ($isCamera) return 'Camera';
  $have = array_flip(array_map('trim', explode(',', (string)$variables)));
  foreach (CLAIM_TYPES as [$label, $need]) {
    $ok = true;
    foreach ($need as $v) { if (!isset($have[$v])) { $ok = false; break; } }
    if ($ok) return $label;
  }
  return 'Sensor';
}

// Two flow meters on one account should not both be called "Flow Meter", so
// append the smallest free counter. Scoped to this customer: what anyone else
// called their devices is none of their business.
function claim_unique_name(PDO $db, $customerId, $label) {
  $stmt = $db->prepare('SELECT name FROM devices WHERE customer_id = ?');
  $stmt->execute([$customerId]);
  $taken = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) $taken[strtolower($n)] = true;

  if (!isset($taken[strtolower($label)])) return $label;
  for ($n = 2; $n < 100; $n++) {
    if (!isset($taken[strtolower("$label $n")])) return "$label $n";
  }
  return $label . ' ' . random_int(100, 999);
}

// ---- mirrored rows ---------------------------------------------------------
// devices.is_mirrored exists only where this dashboard is fed by a one-way
// mirror from another install; a stock OpenRanch has no such column. Every
// query that cares therefore has to ask first, or it breaks on a plain install.
function claim_has_mirror_column(PDO $db) {
  static $has = null;
  if ($has !== null) return $has;
  try {
    $n = $db->query(
      "SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'is_mirrored'")->fetchColumn();
    return $has = ((int)$n > 0);
  } catch (PDOException $e) {
    return $has = false;
  }
}

// " AND is_mirrored = 0", or nothing where the column does not exist.
function claim_not_mirrored_sql(PDO $db) {
  return claim_has_mirror_column($db) ? ' AND is_mirrored = 0' : '';
}

// Selectable expression, so callers get an is_mirrored key either way and can
// test it without knowing which install they are on.
function claim_mirror_col(PDO $db) {
  return claim_has_mirror_column($db) ? 'is_mirrored' : '0 AS is_mirrored';
}

// ---- free tier -------------------------------------------------------------
// Mirrored rows are copies of another system's devices. They are not the
// customer's, cannot be claimed, and must never be counted against a limit.
function claim_device_count(PDO $db, $customerId) {
  $stmt = $db->prepare(
    'SELECT COUNT(*) FROM devices WHERE customer_id = ?' . claim_not_mirrored_sql($db));
  $stmt->execute([$customerId]);
  return (int)$stmt->fetchColumn();
}

// Read the plan directly rather than widening current_customer()'s SELECT:
// that function backs login, the dashboard and cmd.php, and adding a column to
// it would take all of them down on an install that has the new code but has
// not run migrate_claim.sql yet. Here a missing column just means 'free'.
function claim_plan(PDO $db, $customerId) {
  try {
    $stmt = $db->prepare('SELECT plan FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $plan = $stmt->fetchColumn();
    return $plan === false || $plan === null || $plan === '' ? 'free' : (string)$plan;
  } catch (PDOException $e) {
    return 'free';
  }
}

function claim_plan_is_paid($plan) {
  return $plan === 'pro';
}

function claim_limit() {
  return defined('FREE_DEVICE_LIMIT') ? (int)FREE_DEVICE_LIMIT : 3;
}

// True when Stripe is actually configured. With empty keys the upgrade prompt
// has nowhere to send anyone, so claim.php offers a contact link instead.
function claim_stripe_ready() {
  return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== ''
      && defined('STRIPE_PRICE_ID')   && STRIPE_PRICE_ID   !== '';
}
