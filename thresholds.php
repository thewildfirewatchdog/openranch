<?php
// OpenRanch Dashboard — threshold reader / writer
//
//   POST /thresholds.php   action=read  & slug=<device_slug>
//   POST /thresholds.php   action=save  & slug=<device_slug> & variable=<name>
//                          [& low_alarm= & low_warn= & high_warn= & high_alarm=]
//                          [& enabled=0|1]
//
// Auth is cmd.php's, unchanged in shape: the admin PIN in the body, or a
// logged-in customer session as an alternative — and a customer may only touch
// devices assigned to them. As in cmd.php, a matching PIN never starts a
// session, so admin/scripted calls carry no cookie.
//
// POST for both actions, including the read, so the PIN is never sitting in a
// URL, a proxy log or a browser history entry.
//
// Limits: send a number to set one, an empty string to clear it (NULL = that
// side is simply not policed), or leave the field out entirely to keep what is
// already stored. They must read low_alarm <= low_warn <= high_warn <=
// high_alarm across whichever ones are set — a device cannot warn at a level it
// has already alarmed at, and silently accepting that ordering would produce a
// card that never leaves one state.
//
// A row may name a slug that has no device yet: the limits are then waiting for
// the board, which picks them up on its first reading. Only the admin PIN can
// write those, since there is no owner to check them against.

require 'config.php';
require_once 'thresholds_lib.php';

$customer = null;
if (($_POST['pin'] ?? '') !== ADMIN_PIN) {
  or_session_start();
  $customer = current_customer();
  if (!$customer) json_out(['error' => 'bad pin'], 401);   // same reply as cmd.php
}

$action = $_POST['action'] ?? 'read';
$slug   = $_POST['slug'] ?? '';
if ($slug === '') json_out(['error' => 'missing slug'], 400);

$stmt = db()->prepare('SELECT id, slug, variables, customer_id FROM devices WHERE slug = ?');
$stmt->execute([$slug]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$device && $customer) json_out(['error' => 'unknown device'], 404);
if ($device && $customer && (int)$device['customer_id'] !== (int)$customer['id']) {
  json_out(['error' => 'not your device'], 403);
}

// ---- read ----------------------------------------------------------------
// Returns disabled rows too: this is the editor's view, not the dashboard's.
function or_rows($slug) {
  $stmt = db()->prepare(
    'SELECT slug, variable, low_alarm, low_warn, high_warn, high_alarm, enabled
       FROM thresholds WHERE slug = ? ORDER BY variable');
  $stmt->execute([$slug]);
  $rows = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach (['low_alarm','low_warn','high_warn','high_alarm'] as $k) {
      $r[$k] = $r[$k] === null ? null : (float)$r[$k];
    }
    $r['enabled'] = (int)$r['enabled'];
    $rows[] = $r;
  }
  return $rows;
}

if ($action === 'read') json_out(['slug' => $slug, 'thresholds' => or_rows($slug)]);
if ($action !== 'save') json_out(['error' => 'action must be read or save'], 400);

// ---- save ----------------------------------------------------------------
$variable = trim($_POST['variable'] ?? '');
if ($variable === '') json_out(['error' => 'missing variable'], 400);

// Same rule as index.php's ?history=: a device can only be given limits on a
// variable it actually declares. A slug with no device yet is unconstrained.
if ($device) {
  $vars = array_map('trim', explode(',', $device['variables']));
  if (!in_array($variable, $vars, true)) json_out(['error' => 'unknown variable'], 404);
}

$stmt = db()->prepare('SELECT * FROM thresholds WHERE slug = ? AND variable = ?');
$stmt->execute([$slug, $variable]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$cols = ['low_alarm', 'low_warn', 'high_warn', 'high_alarm'];
$vals = [];
foreach ($cols as $c) {
  if (!array_key_exists($c, $_POST)) {          // field omitted -> keep it
    $vals[$c] = $existing && $existing[$c] !== null ? (float)$existing[$c] : null;
    continue;
  }
  $raw = trim((string)$_POST[$c]);
  if ($raw === '') { $vals[$c] = null; continue; }   // sent empty -> clear it
  if (!is_numeric($raw)) json_out(['error' => $c . ' must be a number or empty'], 400);
  $vals[$c] = (float)$raw;
}

// Ordering, across only the limits that are actually set.
$prevCol = null;
foreach ($cols as $c) {                          // $cols is already low -> high
  if ($vals[$c] === null) continue;
  if ($prevCol !== null && $vals[$c] < $vals[$prevCol]) {
    json_out(['error' => $c . ' must be >= ' . $prevCol], 400);
  }
  $prevCol = $c;
}

if (array_key_exists('enabled', $_POST)) {
  $enabled = (int)((string)$_POST['enabled'] === '1' || $_POST['enabled'] === 'on');
} else {
  $enabled = $existing ? (int)$existing['enabled'] : 1;
}

db()->prepare(
  'INSERT INTO thresholds (slug, variable, low_alarm, low_warn, high_warn, high_alarm, enabled)
   VALUES (?, ?, ?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE low_alarm  = VALUES(low_alarm),  low_warn  = VALUES(low_warn),
                           high_warn  = VALUES(high_warn),  high_alarm = VALUES(high_alarm),
                           enabled    = VALUES(enabled)')
  ->execute([$slug, $variable, $vals['low_alarm'], $vals['low_warn'],
             $vals['high_warn'], $vals['high_alarm'], $enabled]);

// The stored ok/warn/alarm was decided against the OLD limits, so it is now
// meaningless — drop it rather than leave a card glowing red against a limit
// that no longer exists. The next reading re-grades from a clean 'ok', which
// also restarts the hysteresis so the band is measured from the new numbers.
if ($device) or_threshold_reset_state((int)$device['id'], $variable);

json_out(['status' => 'ok', 'slug' => $slug, 'thresholds' => or_rows($slug)]);
