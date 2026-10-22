<?php
// OpenRanch — LoRaWAN receiver.
//
// Point your network server's HTTP integration / webhook here:
//
//   POST /lorawan.php                  (ChirpStack: /lorawan.php?event=up)
//   Header:  X-OpenRanch-Key: <LORAWAN_KEY>      (or Authorization: Bearer <key>)
//   Body:    the network server's own uplink JSON; see lorawan_lib.php
//
// LoRaWAN sensors cannot carry a Device-Token, so the device is found by its
// DevEUI instead, and the shared LORAWAN_KEY proves the request came from your
// network server. Anyone holding that key can write readings for any LoRaWAN
// device on this install, so keep it as private as the PROVISION_KEY.
//
// A DevEUI seen for the first time becomes a TEMPLATE device, exactly like a
// board calling register.php: disabled, unassigned, variables taken from the
// uplink. While it is still a template, later uplinks only add variable names.
// Enable it in admin.php (or claim it) and readings start being stored.
//
// Every recognised request is answered 200, including ignored ones (joins,
// templates, unknown events): a network server that keeps getting errors will
// retry and eventually switch the webhook off, which would silently cost every
// other sensor its data.

require 'config.php';
require_once 'lorawan_lib.php';
require_once 'thresholds_lib.php';
require_once 'claim_lib.php';

$key = defined('LORAWAN_KEY') ? (string)LORAWAN_KEY : '';
if ($key === '') json_out(['error' => 'LoRaWAN receiver is off: set LORAWAN_KEY in config.php'], 404);

$given = $_SERVER['HTTP_X_OPENRANCH_KEY'] ?? '';
if ($given === '' && preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $given = $m[1];
if ($given === '' || !hash_equals($key, $given)) json_out(['error' => 'bad or missing X-OpenRanch-Key'], 401);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'POST required'], 405);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) json_out(['error' => 'body must be JSON'], 400);

$up = lw_parse($body, (string)($_GET['event'] ?? ''));
if ($up['source'] === null) json_out(['error' => 'not a recognised TTN or ChirpStack message'], 400);
if ($up['kind'] !== 'uplink')  json_out(['status' => 'ignored', 'reason' => 'not an uplink']);
if ($up['dev_eui'] === null)   json_out(['error' => 'uplink has no valid DevEUI'], 400);

$db = db();
$q = $db->prepare('SELECT * FROM devices WHERE dev_eui = ?');
$q->execute([$up['dev_eui']]);
$device = $q->fetch(PDO::FETCH_ASSOC);

// ---- first uplink from this DevEUI: make a template ----
if (!$device) {
  $vars = array_keys($up['values']);
  if (!$vars) json_out(['status' => 'ignored', 'reason' => 'no decoded values; add a payload decoder on the network server']);

  $base = lw_slug_base($up['name'], $up['dev_eui']);
  $slug = $base; $n = 1;
  $chk = $db->prepare('SELECT 1 FROM devices WHERE slug = ?');
  for ($chk->execute([$slug]); $chk->fetch(); $chk->execute([$slug])) $slug = $base . '_' . (++$n);

  $name = $up['name'] !== '' ? substr($up['name'], 0, 100) : 'LoRaWAN ' . substr($up['dev_eui'], -5);
  $interval = defined('LORAWAN_INTERVAL') ? (int)LORAWAN_INTERVAL : 900;
  try {
    $db->prepare('INSERT INTO devices (slug, name, token, variables, commandable, enabled,
                                       expected_interval, notes, dev_eui, claim_code)
                  VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?)')
       ->execute([$slug, $name, bin2hex(random_bytes(16)), implode(',', $vars), $interval,
                  'LoRaWAN via ' . $up['source'], $up['dev_eui'], claim_unique_code($db)]);
  } catch (PDOException $e) {
    // Two uplinks racing for the same new DevEUI: the unique index keeps one row.
    if (($e->errorInfo[1] ?? 0) != 1062) throw $e;
  }
  json_out(['status' => 'registered', 'slug' => $slug,
            'note' => 'New LoRaWAN device saved as a template. Enable it in admin.php to start storing readings.']);
}

// ---- still a template: learn variable names, store nothing ----
if (!$device['enabled']) {
  $merged = lw_merge_vars($device['variables'], array_keys($up['values']));
  if ($merged !== $device['variables']) {
    $db->prepare('UPDATE devices SET variables = ? WHERE id = ?')->execute([$merged, $device['id']]);
  }
  json_out(['status' => 'template', 'slug' => $device['slug']]);
}

// ---- enabled: same rules as ingest.php ----
$allowed = array_map('trim', explode(',', $device['variables']));
$ins = $db->prepare('INSERT INTO readings (device_id, variable, value) VALUES (?, ?, ?)');
$saved = 0; $values = [];
foreach ($up['values'] as $var => $value) {
  if (!in_array($var, $allowed, true)) continue;
  $ins->execute([$device['id'], $var, $value]);
  $values[$var] = $value;
  $saved++;
}

try {
  or_threshold_ingest((int)$device['id'], $device['slug'], $values);
} catch (Throwable $e) {
  error_log('lorawan threshold eval failed for ' . $device['slug'] . ': ' . $e->getMessage());
}

if (random_int(1, 50) === 1) {
  $db->prepare('DELETE FROM readings WHERE created < NOW() - INTERVAL ? DAY')->execute([RETENTION_DAYS]);
}

json_out(['status' => 'ok', 'slug' => $device['slug'], 'saved' => $saved]);
