<?php
// OpenRanch Dashboard v1 — ingest endpoint
// Boards POST here. Same JSON shape as TagoIO so sketches barely change:
//
//   POST /ingest.php
//   Header:  Device-Token: <token>
//   Body:    [{"variable":"relay_state","value":1},{"variable":"rssi","value":-61}]
//
// Rules:
//  - Unknown token          -> 401
//  - Device enabled=0       -> 403 "device is a template, not programmed yet"
//  - Unknown variable name  -> silently skipped (keeps templates clean)
//  - Every ~50th request also prunes readings older than RETENTION_DAYS
//    so data never piles up. No cron needed.
//
// v2: after the readings are stored, any variable that has a row in the
// thresholds table is evaluated and its ok/warn/alarm state is written to
// threshold_state, so the dashboard reads a decision instead of making one.
// See thresholds_lib.php for the rules and the hysteresis.
//
// The response is unchanged — same keys, same codes, no Set-Cookie — and the
// evaluation is wrapped so that a problem with the threshold tables can never
// cost a board its reading. Storing the data is the job; grading it is not.

require 'config.php';
require_once 'thresholds_lib.php';

$token = $_SERVER['HTTP_DEVICE_TOKEN'] ?? '';
if ($token === '') json_out(['error' => 'missing Device-Token header'], 401);

$stmt = db()->prepare('SELECT * FROM devices WHERE token = ?');
$stmt->execute([$token]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) json_out(['error' => 'unknown token'], 401);

if (!$device['enabled']) {
  json_out(['error' => 'device is a template, not programmed yet. Enable it in admin.php first.'], 403);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) json_out(['error' => 'body must be a JSON array'], 400);

// Boards post one of two shapes. Both end up in the same loop below.
//
//   [{"variable":"pressure_psi","value":123.4}, ...]   the TagoIO shape, v1
//   {"pressure_psi":123.4,"rssi":-61}                  a flat object, v3
//
// The flat object is what a sketch produces when it just serialises the
// readings it already has, and it used to fail in the worst way available: an
// object decodes to an associative array, so it passed the is_array() check
// above, then every element was a bare scalar that matched nothing in the loop,
// and the board got back 200 "saved":0 and reported into nowhere forever. It is
// now translated into the item shape and takes exactly the same path.
//
// A list is left completely alone, so every board already in the field posts
// byte-identical requests and runs byte-identical code.
//
// Keys that are not variables need no special handling: a firmware that also
// sends its own "slug" hits the existing allow-list check and is skipped, the
// same as any unknown variable. Note that the slug is only ever ignored, never
// obeyed — the Device-Token above is the sole authority on which device a
// reading belongs to, so a board cannot write into another device's history by
// naming it in the body.
if (!array_is_list($body)) {
  $items = [];
  foreach ($body as $name => $value) $items[] = ['variable' => $name, 'value' => $value];
  $body = $items;
}

$allowed = array_map('trim', explode(',', $device['variables']));
$ins = db()->prepare('INSERT INTO readings (device_id, variable, value) VALUES (?, ?, ?)');

$saved = 0;
$values = [];                                  // variable => newest value in this batch
foreach ($body as $item) {
  if (!isset($item['variable'], $item['value'])) continue;
  $var = $item['variable'];
  if (!in_array($var, $allowed)) continue;      // not in this device's template
  if (!is_numeric($item['value'])) continue;
  $ins->execute([$device['id'], $var, (float)$item['value']]);
  $values[$var] = (float)$item['value'];        // a repeated variable: newest wins
  $saved++;
}

// Grade the batch against this device's thresholds. Best effort by design: the
// reading is already committed above, so a failure here loses an alarm state,
// never data.
try {
  or_threshold_ingest((int)$device['id'], $device['slug'], $values);
} catch (Throwable $e) {
  error_log('ingest threshold eval failed for ' . $device['slug'] . ': ' . $e->getMessage());
}

// Self-prune: cheap, runs on ~2% of requests
if (random_int(1, 50) === 1) {
  db()->prepare('DELETE FROM readings WHERE created < NOW() - INTERVAL ? DAY')
      ->execute([RETENTION_DAYS]);
}

json_out(['status' => 'ok', 'saved' => $saved]);
