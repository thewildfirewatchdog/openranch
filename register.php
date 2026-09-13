<?php
// OpenRanch Dashboard — device auto-provisioning endpoint
//
// A board calls this once on first boot (and harmlessly on every boot after)
// to claim a slug and a device token for itself:
//
//   POST /register.php
//   Header:  Provision-Key: <PROVISION_KEY>
//   Body:    {"mac":"AA:BB:CC:DD:EE:FF","name":"Ridge Sprinkler",
//             "variables":"sprinkler_state,battery_v,rssi",
//             "interval":60,"commandable":1,"notes":"roof unit"}
//
//   Returns: {"status":"created"|"exists","slug":...,"token":...,"note":...}
//
// Idempotent by MAC: calling it again with the same MAC returns the SAME slug
// and token rather than creating a second device, so firmware can call it every
// boot without thought.
//
// Newly registered devices are always enabled=0 and customer_id NULL. Until
// somebody enables them in admin.php, ingest.php rejects their data with 403 —
// that is deliberate, so a half-built board on the bench can't quietly start
// filling the dashboard.

require 'config.php';
require_once 'claim_lib.php';

// ---- auth ----
$key = $_SERVER['HTTP_PROVISION_KEY'] ?? '';
if ($key === '' || !hash_equals(PROVISION_KEY, $key)) {
  json_out(['error' => 'bad or missing Provision-Key header'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'POST required'], 405);
}

// ---- body ----
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) json_out(['error' => 'body must be a JSON object'], 400);

// MAC — required, AA:BB:CC:DD:EE:FF, stored uppercase so lookups are stable.
$mac = strtoupper(trim((string)($body['mac'] ?? '')));
if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) {
  json_out(['error' => 'mac is required in AA:BB:CC:DD:EE:FF format'], 400);
}

// Variables — required. Keep only names ingest.php could ever match.
$vars = [];
foreach (explode(',', (string)($body['variables'] ?? '')) as $v) {
  $v = preg_replace('/[^A-Za-z0-9_]/', '', trim($v));
  if ($v !== '' && !in_array($v, $vars, true)) $vars[] = $v;
}
if (!$vars) json_out(['error' => 'variables is required (comma-separated list)'], 400);
$variables = implode(',', $vars);
if (strlen($variables) > 65535) json_out(['error' => 'variables list too long'], 400);

// This PHP build has no mbstring, and device names legitimately contain
// multibyte characters (the existing ones use em-dashes), so truncate with a
// UTF-8-aware regex rather than substr() — which would split a character and
// leave invalid UTF-8 in the column.
function utf8_cut($s, $max) {
  return preg_replace('/^(.{0,' . (int)$max . '}).*$/us', '$1', $s);
}

$name        = trim((string)($body['name'] ?? ''));
if ($name === '') $name = 'Device ' . substr(str_replace(':', '', $mac), -5);
$name = utf8_cut($name, 100);

$interval    = (int)($body['interval'] ?? 60);
if ($interval < 5 || $interval > 86400) $interval = 60;

$commandable = !empty($body['commandable']) ? 1 : 0;

$notes       = utf8_cut(trim((string)($body['notes'] ?? '')), 255);

// ---- already registered? then hand back what it already has ----
function existing_device($mac) {
  $stmt = db()->prepare(
    'SELECT id, slug, token, enabled, claim_code, customer_id, ' . claim_mirror_col(db()) .
    '  FROM devices WHERE mac = ?');
  $stmt->execute([$mac]);
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($d = existing_device($mac)) {
  $claimed = $d['customer_id'] !== null;

  // A board that re-registers on every boot must get its code back, not a new
  // one -- the owner may be holding a label printed weeks ago. The exception is
  // a device that has no code and no owner: either it predates claiming or its
  // row was made by hand in admin.php. Minting one here is what lets already
  // deployed boards be claimed at all, so it is done once and then stable.
  //
  // Mirrored rows are skipped: they belong to the system they were copied from
  // and claim.php refuses them anyway.
  if (!$claimed && $d['claim_code'] === null && empty($d['is_mirrored'])) {
    if ($fresh = claim_unique_code(db())) {
      db()->prepare('UPDATE devices SET claim_code = ? WHERE id = ?')
          ->execute([$fresh, $d['id']]);
      $d['claim_code'] = $fresh;
    }
  }

  json_out([
    'status'     => 'exists',
    'slug'       => $d['slug'],
    'token'      => $d['token'],
    'claim_code' => $claimed ? null : $d['claim_code'],
    'claimed'    => $claimed,
    'note'       => $claimed
      ? 'Already registered and claimed. Use this token for ingest.php and poll.php.'
      : ($d['enabled']
          ? 'Already registered and enabled. Use this token for ingest.php and poll.php.'
          : 'Already registered, not yet claimed. Show claim_code to the owner; claiming enables the device.'),
  ]);
}

// ---- build a slug: slugified name + last 5 MAC characters ----
$base = strtolower($name);
$base = preg_replace('/[^a-z0-9]+/', '_', $base);
$base = trim((string)$base, '_');
if ($base === '') $base = 'device';

$suffix = strtolower(substr(str_replace(':', '', $mac), -5));   // e.g. "dEeFf" -> "deeff"

// slug column is VARCHAR(50); leave room for "_" + suffix (+ a collision counter)
$base = substr($base, 0, 50 - 1 - strlen($suffix));
$slug = $base . '_' . $suffix;

// Different boards could share a name AND the same last-5 MAC only by accident,
// but slug is UNIQUE so make sure we never collide.
$try = $slug; $n = 1;
$chk = db()->prepare('SELECT 1 FROM devices WHERE slug = ?');
$chk->execute([$try]);
while ($chk->fetch()) {
  $n++;
  $try = substr($base, 0, 50 - 2 - strlen($suffix) - strlen((string)$n)) . '_' . $suffix . '_' . $n;
  $chk->execute([$try]);
}
$slug = $try;

$token = bin2hex(random_bytes(16));   // 32 hex characters, same shape as the seeded devices

// Single-use claim code. The board shows this to whoever installed it, and they
// spend it once in claim.php to attach the device to their account. Minted here
// rather than in claim.php because the board has to be able to display it
// before any customer has ever seen the device.
$claim = claim_unique_code(db());
if ($claim === null) json_out(['error' => 'could not allocate a claim code'], 500);

// ---- create ----
try {
  db()->prepare(
    'INSERT INTO devices (slug, name, token, variables, commandable, enabled,
                          expected_interval, notes, mac, customer_id, claim_code)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, NULL, ?)')
    ->execute([$slug, $name, $token, $variables, $commandable, $interval, $notes,
               $mac, $claim]);
} catch (PDOException $e) {
  // Two boards with the same MAC racing each other: the unique index wins and
  // we simply return whichever row landed first.
  if (($e->errorInfo[1] ?? 0) == 1062 && ($d = existing_device($mac))) {
    json_out([
      'status'     => 'exists',
      'slug'       => $d['slug'],
      'token'      => $d['token'],
      'claim_code' => $d['claim_code'],
      'claimed'    => $d['customer_id'] !== null,
      'note'       => 'Already registered (concurrent registration).',
    ]);
  }
  json_out(['error' => 'could not register device'], 500);
}

json_out([
  'status'     => 'created',
  'slug'       => $slug,
  'token'      => $token,
  'claim_code' => $claim,
  'claimed'    => false,
  'note'       => 'Registered and disabled. Show claim_code to the owner: claiming it in claim.php assigns the device and enables it. ingest.php returns 403 until then.',
]);
