<?php
// OpenRanch — internal JSON API for the assistant (MCP server / Telegram bot).
//
//   GET  /api/v1/devices                       devices + latest readings
//   GET  /api/v1/history?device=&metric=&hours= one metric over time
//   GET  /api/v1/zones                         zones + what is running
//   POST /api/v1/zones/start  {zone_id,minutes}
//   POST /api/v1/zones/stop   {zone_id}
//   POST /api/v1/zones/stopall
//   GET  /api/v1/programs                      programs + next run
//   POST /api/v1/programs/run {program_id}     run once, now
//   POST /api/v1/rain-delay   {hours}          0 clears the hold
//   GET  /api/v1/rules
//   POST /api/v1/rules        {name,device_id,metric,op,value,...}
//   POST /api/v1/rules/delete {rule_id}
//   GET  /api/v1/notifications?hours=24        skip / automation / flow log
//   GET  /api/v1/summary?hours=24              everything the briefing needs
//   POST /api/v1/link         {code,chat_id}   bind a Telegram chat
//   GET  /api/v1/me                            who this token belongs to
//
// Auth: "Authorization: Bearer <api_token>" or "X-API-Token: <api_token>",
// matched against customers.api_token. Every query is scoped to that customer;
// there is no endpoint that can read another account's rows.
//
// Mirrored devices (is_mirrored = 1) are copies of another system's hardware.
// They are readable only by the admin account (the one whose email is
// ALERT_EMAIL) and are never controllable from here -- irr_send_cmd and cmd.php
// refuse them, and this API does not offer a path around that.

require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../irrigation_lib.php';
// claim_lib is where claim_has_mirror_column() lives. Without it the
// mirrored-row filter below silently no-ops and every account sees
// mirrored hardware, so this require is load-bearing, not cosmetic.
require_once __DIR__ . '/../../claim_lib.php';
require_once __DIR__ . '/../../camera_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function fail($msg, $code = 400)  { out(['error' => $msg], $code); }

// ---- routing ---------------------------------------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^/api/v1/?#', '', $path);
$path = trim(preg_replace('#^index\.php/?#', '', $path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$body = [];
if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = $_POST;
}
function arg($k, $d = null) { global $body; return $body[$k] ?? $_GET[$k] ?? $d; }

// ---- auth ------------------------------------------------------------------
$tok = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION']) &&
    preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) $tok = $m[1];
if ($tok === '' && !empty($_SERVER['HTTP_X_API_TOKEN'])) $tok = trim($_SERVER['HTTP_X_API_TOKEN']);

// The link endpoint is the one thing a chat can call before it has a token:
// it trades a short-lived code for a binding. Everything else needs the token.
$db = db();
if ($path === 'link' && $method === 'POST') {
  $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)arg('code', '')));
  $chat = (int)arg('chat_id', 0);
  // Two unrelated failures used to share one message: a client that left a
  // field out, and a customer who typed something that is not a code. The
  // second is by far the common one -- "/link <code>" normalises to CODE --
  // and "code and chat_id are required" reads to them as if the bot is broken,
  // so each case says what is actually wrong. These strings reach the customer
  // verbatim: the bot echoes whatever comes back here.
  if (!$chat)              fail('chat_id is required', 400);
  if ($code === '')        fail('Send the code with the command, like /link ABC234.', 400);
  if (strlen($code) !== 6) fail('A link code is 6 characters, like ABC234. '
                              . 'Open the assistant page on the dashboard to see yours.', 400);

  $q = $db->prepare('SELECT id, email, name FROM customers
                      WHERE link_code = ? AND (link_expires IS NULL OR link_expires > NOW())');
  $q->execute([$code]);
  $c = $q->fetch(PDO::FETCH_ASSOC);
  if (!$c) fail('that code is not valid, or it has expired', 404);

  $db->prepare('UPDATE customers SET telegram_chat_id = ?, link_code = NULL,
                  link_expires = NULL, bot_bind_next = 0 WHERE id = ?')
     ->execute([$chat, $c['id']]);
  out(['status' => 'linked', 'customer' => ['id' => (int)$c['id'],
       'email' => $c['email'], 'name' => $c['name']]]);
}

// The bot's two privileged endpoints. Neither can require a customer token --
// they are how a chat acquires one -- so both are gated on the shared secret
// instead, and both are useless to anyone who does not already hold it.
function bot_secret_ok() {
  if (!defined('BOT_SHARED_SECRET') || BOT_SHARED_SECRET === '') return false;
  $got = $_SERVER['HTTP_X_BOT_SECRET'] ?? '';
  return $got !== '' && hash_equals(BOT_SHARED_SECRET, $got);
}

// Which account owns a Telegram chat, and the token to act as it.
if ($path === 'resolve' && $method === 'POST') {
  if (!bot_secret_ok()) fail('bad bot secret', 401);
  $chat = (int)arg('chat_id', 0);
  if (!$chat) fail('chat_id is required', 400);
  $q = $db->prepare('SELECT id, email, name, api_token, bot_voice, bot_briefing
                       FROM customers WHERE telegram_chat_id = ?');
  $q->execute([$chat]);
  $c = $q->fetch(PDO::FETCH_ASSOC);
  if (!$c)                out(['status' => 'unlinked']);
  if (!$c['api_token'])   out(['status' => 'no_token', 'email' => $c['email']]);
  out(['status' => 'linked', 'customer' => ['id' => (int)$c['id'], 'email' => $c['email'],
       'name' => $c['name'], 'api_token' => $c['api_token'],
       'voice' => (bool)$c['bot_voice'], 'briefing' => (bool)$c['bot_briefing']]]);
}

// Per-chat voice preference, flipped by /voice on|off in the chat.
if ($path === 'set-voice' && $method === 'POST') {
  if (!bot_secret_ok()) fail('bad bot secret', 401);
  $chat = (int)arg('chat_id', 0);
  $on   = !empty(arg('on', 0)) ? 1 : 0;
  $st = $db->prepare('UPDATE customers SET bot_voice = ? WHERE telegram_chat_id = ?');
  $st->execute([$on, $chat]);
  out(['status' => $st->rowCount() ? 'ok' : 'unlinked', 'voice' => (bool)$on]);
}

// Every chat that should get a 07:00 briefing.
if ($path === 'briefing-list' && $method === 'POST') {
  if (!bot_secret_ok()) fail('bad bot secret', 401);
  $q = $db->query('SELECT id, email, name, api_token, telegram_chat_id FROM customers
                    WHERE telegram_chat_id IS NOT NULL AND api_token IS NOT NULL
                      AND bot_briefing = 1');
  out(['customers' => $q->fetchAll(PDO::FETCH_ASSOC)]);
}

// One-shot bind: an operator armed an account to adopt the next chat that says
// /start. Deliberately does not need a code -- it is armed from the signed-in
// settings page, and it disarms the instant it is used.
if ($path === 'bind-next' && $method === 'POST') {
  if (!bot_secret_ok()) fail('bad bot secret', 401);
  $chat = (int)arg('chat_id', 0);
  if (!$chat) fail('chat_id is required', 400);
  $q = $db->query('SELECT id, email, name FROM customers WHERE bot_bind_next = 1 ORDER BY id LIMIT 1');
  $c = $q->fetch(PDO::FETCH_ASSOC);
  if (!$c) out(['status' => 'none_armed']);
  $db->prepare('UPDATE customers SET telegram_chat_id = ?, bot_bind_next = 0 WHERE id = ?')
     ->execute([$chat, $c['id']]);
  out(['status' => 'linked', 'customer' => ['id' => (int)$c['id'],
       'email' => $c['email'], 'name' => $c['name']]]);
}

if ($tok === '' || strlen($tok) < 20) fail('missing or malformed API token', 401);
$q = $db->prepare('SELECT id, email, name, plan, telegram_chat_id, bot_voice, bot_briefing
                     FROM customers WHERE api_token = ?');
$q->execute([$tok]);
$cust = $q->fetch(PDO::FETCH_ASSOC);
if (!$cust) fail('unknown API token', 401);
$cid = (int)$cust['id'];

// The admin account is the one notifications already go to. It is the only
// account allowed to READ mirrored rows; nobody may control them.
$isAdmin = defined('ALERT_EMAIL') && strcasecmp($cust['email'], ALERT_EMAIL) === 0;

function mirror_sql($isAdmin) {
  if (!function_exists('claim_has_mirror_column')) return '';
  return claim_has_mirror_column(db()) && !$isAdmin ? ' AND d.is_mirrored = 0' : '';
}
function mirror_col() {
  return function_exists('claim_has_mirror_column') && claim_has_mirror_column(db())
       ? 'd.is_mirrored' : '0 AS is_mirrored';
}

// A device this customer may touch. Mirrored rows are never controllable.
function own_device($db, $cid, $ref, $isAdmin, $forControl = false) {
  $col = mirror_col();
  $q = $db->prepare("SELECT d.*, $col FROM devices d
                      WHERE d.customer_id = ? AND (d.slug = ? OR d.id = ?)");
  $q->execute([$cid, (string)$ref, (int)$ref]);
  $d = $q->fetch(PDO::FETCH_ASSOC);
  if (!$d) return null;
  if (!empty($d['is_mirrored']) && ($forControl || !$isAdmin)) return null;
  return $d;
}

// ---- endpoints -------------------------------------------------------------
switch ("$method $path") {

case 'GET me':
  out(['customer' => ['id' => $cid, 'email' => $cust['email'], 'name' => $cust['name'],
       'plan' => $cust['plan'], 'is_admin' => $isAdmin,
       'telegram_linked' => $cust['telegram_chat_id'] !== null,
       'voice_replies' => (bool)$cust['bot_voice'],
       'briefing' => (bool)$cust['bot_briefing']]]);

case 'GET devices':
  $col = mirror_col();
  $sql = "SELECT d.id, d.slug, d.name, d.variables, d.commandable, d.enabled,
                 d.expected_interval, d.notes, d.is_camera, $col
            FROM devices d WHERE d.customer_id = ?" . mirror_sql($isAdmin) . ' ORDER BY d.name';
  $q = $db->prepare($sql); $q->execute([$cid]);
  $devices = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $vals = [];
    foreach (array_filter(array_map('trim', explode(',', $d['variables']))) as $v) {
      $r = $db->prepare('SELECT value, created FROM readings
                          WHERE device_id = ? AND variable = ? ORDER BY created DESC, id DESC LIMIT 1');
      $r->execute([$d['id'], $v]);
      $row = $r->fetch(PDO::FETCH_ASSOC);
      if ($row) $vals[$v] = ['value' => (float)$row['value'], 'at' => $row['created']];
    }
    $last = $db->prepare('SELECT MAX(created) FROM readings WHERE device_id = ?');
    $last->execute([$d['id']]);
    $lastSeen = $last->fetchColumn() ?: null;

    // A camera reports by uploading pictures, not readings. Judging it by the
    // readings table makes every camera look permanently offline, which is both
    // wrong and the kind of thing the assistant will repeat to the customer.
    $camera_last = null;
    if (!empty($d['is_camera'])) {
      $cs = $db->prepare('SELECT taken FROM snapshots WHERE device_id = ? ORDER BY taken DESC, id DESC LIMIT 1');
      $cs->execute([$d['id']]);
      $camera_last = $cs->fetchColumn() ?: null;
      $lastSeen = $camera_last;
    }
    $ageMin = $lastSeen ? round((time() - strtotime($lastSeen . ' UTC')) / 60) : null;
    $devices[] = [
      'id' => (int)$d['id'], 'slug' => $d['slug'], 'name' => $d['name'],
      'enabled' => (bool)$d['enabled'], 'commandable' => (bool)$d['commandable'],
      'mirrored' => (bool)$d['is_mirrored'],
      'is_camera' => (bool)($d['is_camera'] ?? 0),
      'note' => !empty($d['is_camera'])
        ? 'This is a camera: it sends pictures, not readings. Use list_cameras or get_latest_snapshot.'
        : null,
      'controllable' => (bool)$d['commandable'] && (bool)$d['enabled'] && empty($d['is_mirrored']),
      'last_seen' => $lastSeen, 'minutes_since_report' => $ageMin,
      'offline' => $ageMin === null ? true : $ageMin > max(15, ((int)$d['expected_interval'] * 3) / 60),
      'readings' => $vals,
    ];
  }
  out(['devices' => $devices, 'count' => count($devices)]);

case 'GET history':
  $ref = (string)arg('device', '');
  $metric = preg_replace('/[^A-Za-z0-9_]/', '', (string)arg('metric', ''));
  $hours = max(1, min(720, (int)arg('hours', 24)));
  $d = own_device($db, $cid, $ref, $isAdmin);
  if (!$d)            fail('no such device on this account', 404);
  if ($metric === '') fail('metric is required', 400);
  $q = $db->prepare('SELECT value, created FROM readings
                      WHERE device_id = ? AND variable = ? AND created >= (NOW() - INTERVAL ? HOUR)
                      ORDER BY created');
  $q->execute([$d['id'], $metric, $hours]);
  $pts = $q->fetchAll(PDO::FETCH_ASSOC);
  $vals = array_map(fn($p) => (float)$p['value'], $pts);
  out(['device' => $d['slug'], 'metric' => $metric, 'hours' => $hours,
       'count' => count($pts),
       'min' => $vals ? min($vals) : null, 'max' => $vals ? max($vals) : null,
       'latest' => $vals ? end($vals) : null,
       'points' => array_map(fn($p) => ['t' => $p['created'], 'v' => (float)$p['value']], $pts)]);

case 'GET zones':
  $zones = irr_zones($db, $cid);
  $running = [];
  foreach (irr_running($db, $cid) as $r) $running[$r['zone_id']] = $r;
  $loc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(irr_tz());
  $next = [];
  $pq = $db->prepare('SELECT * FROM irr_programs WHERE customer_id = ? AND enabled = 1');
  $pq->execute([$cid]);
  foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $n = irr_next_occurrence($p, $loc);
    if (!$n) continue;
    $zq = $db->prepare('SELECT zone_id FROM irr_program_zones WHERE program_id = ?');
    $zq->execute([$p['id']]);
    foreach ($zq->fetchAll(PDO::FETCH_COLUMN) as $zid) {
      if (!isset($next[$zid]) || $n->format('c') < $next[$zid]['at']) {
        $next[$zid] = ['at' => $n->format('c'), 'program' => $p['name']];
      }
    }
  }
  $outz = [];
  foreach ($zones as $z) {
    $r = $running[$z['id']] ?? null;
    $outz[] = ['id' => (int)$z['id'], 'name' => $z['name'],
      'enabled' => (bool)$z['enabled'], 'is_master' => (bool)$z['is_master'],
      'default_minutes' => (float)($z['default_minutes'] ?? 10),
      'running' => $r !== null, 'ends_at' => $r['ends_at'] ?? null,
      'next_run' => $next[$z['id']] ?? null];
  }
  $s = irr_settings($db, $cid);
  out(['zones' => $outz,
       'rain_delay_until' => $s['rain_delay_until'],
       'held' => $s['rain_delay_until'] !== null && strtotime($s['rain_delay_until']) > time()]);

case 'POST zones/start':
  $z = irr_zone($db, $cid, (int)arg('zone_id', 0));
  if (!$z)            fail('no such zone on this account', 404);
  if (!$z['enabled']) fail('that zone is switched off', 409);
  $min = max(0.5, min(720, (float)arg('minutes', $z['default_minutes'] ?? 10)));
  $chk = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
  $chk->execute([$z['id']]);
  if ((int)$chk->fetchColumn() > 0) fail($z['name'] . ' is already running', 409);
  $id  = irr_queue_run($db, $cid, $z['id'], $min, 'manual');
  $run = ['id' => $id, 'customer_id' => $cid, 'program_id' => null, 'planned_min' => $min];
  irr_ensure_master($db, $cid, true);
  $why = '';
  if (!irr_start_run($db, $z, $run, $why)) {
    irr_ensure_master($db, $cid, irr_any_running($db, $cid));
    fail('could not start ' . $z['name'] . ': ' . $why, 409);
  }
  out(['status' => 'started', 'zone' => $z['name'], 'minutes' => $min]);

case 'POST zones/stop':
  $z = irr_zone($db, $cid, (int)arg('zone_id', 0));
  if (!$z) fail('no such zone on this account', 404);
  $q = $db->prepare("SELECT * FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
  $q->execute([$z['id']]);
  $n = 0;
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $run) { irr_stop_run($db, $z, $run, 'stopped'); $n++; }
  irr_ensure_master($db, $cid, irr_any_running($db, $cid));
  out(['status' => 'stopped', 'zone' => $z['name'], 'runs_stopped' => $n]);

case 'POST zones/stopall':
  $q = $db->prepare("SELECT * FROM irr_runs WHERE customer_id = ? AND status IN ('queued','running')");
  $q->execute([$cid]);
  $n = 0;
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $run) {
    $z = irr_zone($db, $cid, $run['zone_id']);
    if ($z) { irr_stop_run($db, $z, $run, 'stopped'); $n++; }
  }
  irr_ensure_master($db, $cid, false);
  out(['status' => 'stopped', 'runs_stopped' => $n]);

case 'GET programs':
  $q = $db->prepare('SELECT * FROM irr_programs WHERE customer_id = ? ORDER BY name');
  $q->execute([$cid]);
  $loc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(irr_tz());
  $ps = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $zq = $db->prepare('SELECT pz.minutes, z.name FROM irr_program_zones pz
                          JOIN irr_zones z ON z.id = pz.zone_id
                         WHERE pz.program_id = ? ORDER BY pz.sort_order, pz.id');
    $zq->execute([$p['id']]);
    $n = irr_next_occurrence($p, $loc);
    $ps[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'enabled' => (bool)$p['enabled'],
      'start_times' => $p['start_times'], 'days_mode' => $p['days_mode'],
      'days_of_week' => $p['days_of_week'], 'interval_days' => (int)$p['interval_days'],
      'seasonal_pct' => (int)$p['seasonal_pct'], 'sequential' => (bool)$p['sequential'],
      'weather_adjust' => (bool)$p['weather_adjust'],
      'next_run' => $n ? $n->format('c') : null,
      'zones' => $zq->fetchAll(PDO::FETCH_ASSOC)];
  }
  out(['programs' => $ps]);

case 'POST programs/run':
  $q = $db->prepare('SELECT * FROM irr_programs WHERE id = ? AND customer_id = ?');
  $q->execute([(int)arg('program_id', 0), $cid]);
  $p = $q->fetch(PDO::FETCH_ASSOC);
  if (!$p) fail('no such program on this account', 404);
  $zs = $db->prepare('SELECT pz.*, z.id zid FROM irr_program_zones pz
                        JOIN irr_zones z ON z.id = pz.zone_id
                       WHERE pz.program_id = ? AND z.enabled = 1 AND z.is_master = 0
                       ORDER BY pz.sort_order, pz.id');
  $zs->execute([$p['id']]);
  $n = 0;
  foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $i => $z) {
    $min = irr_adjust_minutes($z['minutes'], $p['seasonal_pct'], 1.0);
    if ($min <= 0) continue;
    irr_queue_run($db, $cid, $z['zid'], $min, 'once', $p['id'], $i);
    $n++;
  }
  out(['status' => $n ? 'queued' : 'nothing_to_run', 'program' => $p['name'], 'zones_queued' => $n]);

case 'POST rain-delay':
  $h = (int)arg('hours', 24);
  if ($h <= 0) {
    $db->prepare('UPDATE irr_settings SET rain_delay_until = NULL WHERE customer_id = ?')->execute([$cid]);
    out(['status' => 'cleared']);
  }
  $h = min(240, $h);
  irr_settings($db, $cid);
  $db->prepare('UPDATE irr_settings SET rain_delay_until = (NOW() + INTERVAL ? HOUR) WHERE customer_id = ?')
     ->execute([$h, $cid]);
  $s = irr_settings($db, $cid);
  out(['status' => 'held', 'hours' => $h, 'until' => $s['rain_delay_until']]);

case 'GET rules':
  $q = $db->prepare('SELECT r.*, d.name device_name, d.slug device_slug
                       FROM irr_rules r LEFT JOIN devices d ON d.id = r.device_id
                      WHERE r.customer_id = ? ORDER BY r.name');
  $q->execute([$cid]);
  out(['rules' => array_map(fn($r) => [
    'id' => (int)$r['id'], 'name' => $r['name'], 'enabled' => (bool)$r['enabled'],
    'device' => $r['device_name'], 'device_slug' => $r['device_slug'],
    'metric' => $r['metric'], 'op' => $r['op'], 'value' => (float)$r['value'],
    'for_minutes' => (int)$r['for_minutes'], 'action' => $r['action'],
    'message' => $r['message'], 'last_fired' => $r['last_fired'],
  ], $q->fetchAll(PDO::FETCH_ASSOC))]);

case 'POST rules':
  $name = trim((string)arg('name', ''));
  $dev  = own_device($db, $cid, (string)arg('device', arg('device_id', '')), $isAdmin);
  $metric = preg_replace('/[^A-Za-z0-9_]/', '', (string)arg('metric', ''));
  $op = (string)arg('op', '>');
  $action = (string)arg('action', 'notify');
  if ($name === '')                     fail('name is required', 400);
  if (!$dev)                            fail('no such device on this account', 404);
  if ($metric === '')                   fail('metric is required', 400);
  if (!in_array($op, IRR_OPS, true))    fail('op must be one of ' . implode(' ', IRR_OPS), 400);
  if (!in_array($action, ['notify'], true))
    fail('this API only creates notify automations; build command or zone automations on the Automations page', 400);
  $db->prepare('INSERT INTO irr_rules (customer_id, name, enabled, device_id, metric, op, value,
                  for_minutes, action, message, cooldown_min)
                VALUES (?,?,1,?,?,?,?,?,?,?,?)')
     ->execute([$cid, $name, $dev['id'], $metric, $op, (float)arg('value', 0),
                max(0, (int)arg('for_minutes', 0)), 'notify',
                trim((string)arg('message', '')), max(0, (int)arg('cooldown_min', 30))]);
  out(['status' => 'created', 'rule_id' => (int)$db->lastInsertId(), 'name' => $name]);

case 'POST rules/delete':
  $st = $db->prepare('DELETE FROM irr_rules WHERE id = ? AND customer_id = ?');
  $st->execute([(int)arg('rule_id', 0), $cid]);
  if (!$st->rowCount()) fail('no such automation on this account', 404);
  out(['status' => 'deleted']);

case 'GET cameras':   // snapshot_age
  $cams = [];
  foreach (cam_devices($db, $cid) as $d) {
    if (!empty($d['is_mirrored']) && !$isAdmin) continue;
    $latest = cam_latest($db, $d['id']);
    $cams[] = [
      'slug' => $d['slug'], 'name' => $d['name'], 'enabled' => (bool)$d['enabled'],
      'mirrored' => (bool)($d['is_mirrored'] ?? 0),
      'latest' => $latest ? [
        'file' => $latest['filename'], 'taken' => $latest['taken'],
        'taken_utc' => $latest['taken'] . ' UTC',
        'age_minutes' => (int)round((time() - strtotime($latest['taken'] . ' UTC')) / 60),
        'source' => $latest['source'],
        'url' => (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '')
               . '/snapshot.php?device=' . rawurlencode($d['slug'])
               . '&file=' . rawurlencode($latest['filename']),
      ] : null,
    ];
  }
  out(['now_utc' => gmdate('Y-m-d H:i:s') . ' UTC',
       'cameras' => $cams, 'count' => count($cams)]);

case 'POST cameras/request':
  $q = $db->prepare('SELECT * FROM devices WHERE slug = ? AND customer_id = ? AND is_camera = 1');
  $q->execute([(string)arg('camera', ''), $cid]);
  $d = $q->fetch(PDO::FETCH_ASSOC);
  if (!$d)                        fail('no such camera on this account', 404);
  if (!$d['enabled'])             fail('that camera is switched off', 409);
  if (!empty($d['is_mirrored']))  fail('that camera is mirrored and is read-only here', 409);
  $before = cam_latest($db, $d['id']);
  $db->prepare('INSERT INTO commands (device_id, cmd) VALUES (?, ?)')
     ->execute([$d['id'], CAM_CMD_SNAPSHOT]);
  out(['status' => 'requested', 'camera' => $d['slug'],
       'note' => 'The camera takes the picture on its next poll; fetch cameras again to see it.',
       'previous' => $before['filename'] ?? null]);

case 'GET notifications':
  $hours = max(1, min(720, (int)arg('hours', 24)));
  $q = $db->prepare('SELECT s.created, s.reason, s.detail, z.name zone, p.name program
                       FROM irr_skips s
                       LEFT JOIN irr_zones z ON z.id = s.zone_id
                       LEFT JOIN irr_programs p ON p.id = s.program_id
                      WHERE s.customer_id = ? AND s.created >= (NOW() - INTERVAL ? HOUR)
                      ORDER BY s.created DESC LIMIT 200');
  $q->execute([$cid, $hours]);
  out(['hours' => $hours, 'entries' => $q->fetchAll(PDO::FETCH_ASSOC)]);

case 'GET summary':
  $hours = max(1, min(168, (int)arg('hours', 24)));
  $runs = $db->prepare("SELECT z.name zone, r.started, r.ended, r.planned_min, r.gallons, r.status, r.source
                          FROM irr_runs r JOIN irr_zones z ON z.id = r.zone_id
                         WHERE r.customer_id = ? AND r.started >= (NOW() - INTERVAL ? HOUR)
                         ORDER BY r.started");
  $runs->execute([$cid, $hours]);
  $skips = $db->prepare('SELECT s.created, s.reason, s.detail, z.name zone
                           FROM irr_skips s LEFT JOIN irr_zones z ON z.id = s.zone_id
                          WHERE s.customer_id = ? AND s.created >= (NOW() - INTERVAL ? HOUR)
                          ORDER BY s.created');
  $skips->execute([$cid, $hours]);
  $s = irr_settings($db, $cid);
  $w = $s['weather_json'] ? irr_weather_extract(json_decode($s['weather_json'], true)) : null;

  $col = mirror_col();
  $dq = $db->prepare("SELECT d.id, d.name, d.slug, d.expected_interval, d.is_camera, $col
                        FROM devices d WHERE d.customer_id = ?" . mirror_sql($isAdmin));
  $dq->execute([$cid]);
  $offline = []; $levels = []; $cameras = [];
  foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $d) {
    if (!empty($d['is_camera'])) {
      // Cameras send pictures, not readings. Measuring them against the
      // readings table reports every camera as offline, which the assistant
      // then repeats to the customer as a fault that does not exist.
      $cs = $db->prepare('SELECT taken FROM snapshots WHERE device_id = ? ORDER BY taken DESC, id DESC LIMIT 1');
      $cs->execute([$d['id']]);
      $last = $cs->fetchColumn() ?: null;
      $ageH = $last ? (time() - strtotime($last . ' UTC')) / 3600 : null;
      $cameras[] = ['name' => $d['name'], 'slug' => $d['slug'],
                    'latest_picture_utc' => $last,
                    'picture_age_minutes' => $ageH === null ? null : (int)round($ageH * 60)];
      if ($ageH === null || $ageH > 12) {
        $offline[] = ['name' => $d['name'], 'last_seen' => $last,
                      'hours' => $ageH === null ? null : round($ageH, 1),
                      'kind' => 'camera'];
      }
      continue;
    }
    $l = $db->prepare('SELECT MAX(created) FROM readings WHERE device_id = ?');
    $l->execute([$d['id']]);
    $last = $l->fetchColumn();
    $ageH = $last ? (time() - strtotime($last . ' UTC')) / 3600 : null;
    if ($ageH === null || $ageH > 12) {
      $offline[] = ['name' => $d['name'], 'last_seen' => $last,
                    'hours' => $ageH === null ? null : round($ageH, 1)];
    }
    foreach (['tank_level_pct', 'pressure_psi', 'moisture', 'total_gal'] as $v) {
      $r = $db->prepare('SELECT value, created FROM readings WHERE device_id = ? AND variable = ?
                          ORDER BY created DESC LIMIT 1');
      $r->execute([$d['id'], $v]);
      if ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $levels[] = ['device' => $d['name'], 'metric' => $v,
                     'value' => (float)$row['value'], 'at' => $row['created']];
      }
    }
  }
  out(['hours' => $hours, 'now_utc' => gmdate('c'),
       'runs' => $runs->fetchAll(PDO::FETCH_ASSOC),
       'skips' => $skips->fetchAll(PDO::FETCH_ASSOC),
       'levels' => $levels, 'offline_devices' => $offline, 'cameras' => $cameras,
       'weather' => $w, 'weather_cached_at' => $s['weather_at'],
       'rain_delay_until' => $s['rain_delay_until']]);
}

fail('unknown endpoint: ' . $method . ' /' . $path, 404);
