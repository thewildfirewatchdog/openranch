<?php
// OpenRanch — MQTT bridge.
//
// Publishes every new reading to openranch/<customer>/<device>/<metric>,
// announces devices, zones and relays to Home Assistant via its discovery
// topics, and turns commands received on openranch/<customer>/<device>/set
// into rows in the same `commands` table the dashboard writes.
//
//   mqtt_bridge.php            run forever (systemd)
//   mqtt_bridge.php --once     one publish pass, no subscriber; for testing
//   mqtt_bridge.php --discover republish Home Assistant discovery and exit
//
// Uses the mosquitto_pub/sub binaries rather than a PHP MQTT client: there is
// no packaged PHP extension here, and shelling out to the official tools is
// less code to be wrong than a hand-rolled protocol implementation.

require __DIR__ . '/config.php';
require_once __DIR__ . '/claim_lib.php';

const BRIDGE_USER = 'openranch_bridge';
const HOST = '127.0.0.1';
const PORT = 1883;

$once     = in_array('--once', $argv ?? [], true);
$discOnly = in_array('--discover', $argv ?? [], true);
$verbose  = $once || $discOnly || in_array('-v', $argv ?? [], true);
function vlog($m) { global $verbose; if ($verbose) echo date('H:i:s '), $m, "\n"; }

$pw = trim(@file_get_contents('/var/lib/openranch-mqtt/bridge.pw') ?: '');
if ($pw === '') { fwrite(STDERR, "bridge password missing; run openranch-mqtt-sync.php\n"); exit(1); }

function pub($topic, $payload, $retain = true) {
  global $pw;
  $cmd = sprintf('mosquitto_pub -h %s -p %d -u %s -P %s -t %s -m %s %s 2>&1',
    HOST, PORT, escapeshellarg(BRIDGE_USER), escapeshellarg($pw),
    escapeshellarg($topic), escapeshellarg($payload), $retain ? '-r' : '');
  exec($cmd, $out, $rc);
  return $rc === 0;
}

function state_get(PDO $db, $k, $d = '0') {
  $q = $db->prepare('SELECT v FROM mqtt_state WHERE k = ?');
  $q->execute([$k]);
  $v = $q->fetchColumn();
  return $v === false ? $d : $v;
}
function state_set(PDO $db, $k, $v) {
  db()->prepare('INSERT INTO mqtt_state (k, v) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, (string)$v]);
}

// Customers with MQTT switched on, keyed by id.
function mqtt_customers(PDO $db) {
  $out = [];
  foreach ($db->query('SELECT id, email, mqtt_username FROM customers WHERE mqtt_enabled = 1')
               ->fetchAll(PDO::FETCH_ASSOC) as $c) $out[(int)$c['id']] = $c;
  return $out;
}

// Devices a customer owns. Mirrored rows are published read-only: their
// readings are real, but commands for them are refused (cmd.php and the bridge
// both decline), so Home Assistant is told they are sensors, never switches.
function mqtt_devices(PDO $db, $cid) {
  $col = claim_has_mirror_column($db) ? 'is_mirrored' : '0 AS is_mirrored';
  $q = $db->prepare("SELECT id, slug, name, variables, commandable, enabled, $col
                       FROM devices WHERE customer_id = ? ORDER BY id");
  $q->execute([$cid]);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}

// ---- Home Assistant discovery ---------------------------------------------
// One config message per entity under homeassistant/<component>/<object>/config.
// Retained, so HA picks them up whenever it connects.
function ha_discover(PDO $db) {
  $n = 0;
  foreach (mqtt_customers($db) as $cid => $c) {
    foreach (mqtt_devices($db, $cid) as $d) {
      $devBlock = [
        'identifiers'  => ["openranch_{$cid}_{$d['slug']}"],
        'name'         => $d['name'],
        'manufacturer' => 'OpenRanch',
        'model'        => !empty($d['is_mirrored']) ? 'Mirrored device' : 'Sensor / controller',
      ];
      foreach (array_filter(array_map('trim', explode(',', $d['variables']))) as $v) {
        $obj = "openranch_{$cid}_{$d['slug']}_{$v}";
        $cfg = [
          'name'                => $v,
          'unique_id'           => $obj,
          'state_topic'         => "openranch/$cid/{$d['slug']}/$v",
          'device'              => $devBlock,
          'expire_after'        => 3600,
        ];
        // Units HA understands, where the variable name makes them obvious.
        if (str_ends_with($v, '_pct'))      { $cfg['unit_of_measurement'] = '%'; }
        elseif ($v === 'pressure_psi')      { $cfg['unit_of_measurement'] = 'psi'; }
        elseif ($v === 'flow_gpm')          { $cfg['unit_of_measurement'] = 'gal/min'; }
        elseif (str_ends_with($v, '_gal'))  { $cfg['unit_of_measurement'] = 'gal';
                                              $cfg['state_class'] = 'total_increasing'; }
        elseif ($v === 'temp_c')            { $cfg['unit_of_measurement'] = '°C';
                                              $cfg['device_class'] = 'temperature'; }
        elseif (str_ends_with($v, '_v'))    { $cfg['unit_of_measurement'] = 'V';
                                              $cfg['device_class'] = 'voltage'; }
        elseif ($v === 'rssi')              { $cfg['unit_of_measurement'] = 'dBm';
                                              $cfg['device_class'] = 'signal_strength'; }
        pub("homeassistant/sensor/$obj/config", json_encode($cfg));
        $n++;
      }
      // A commandable, non-mirrored device is also a switch HA can operate.
      if ($d['commandable'] && $d['enabled'] && empty($d['is_mirrored'])) {
        $obj = "openranch_{$cid}_{$d['slug']}_relay";
        pub("homeassistant/switch/$obj/config", json_encode([
          'name'          => $d['name'],
          'unique_id'     => $obj,
          'command_topic' => "openranch/$cid/{$d['slug']}/set",
          'state_topic'   => "openranch/$cid/{$d['slug']}/relay_state",
          'payload_on'    => '1', 'payload_off' => '0',
          'state_on'      => '1', 'state_off'   => '0',
          'device'        => $devBlock,
        ]));
        $n++;
      }
    }
    // Zones become switches too, addressed by zone rather than device so the
    // master valve and the scheduler's own rules still apply.
    $zq = $db->prepare('SELECT id, name FROM irr_zones WHERE customer_id = ? AND enabled = 1 AND is_master = 0');
    $zq->execute([$cid]);
    foreach ($zq->fetchAll(PDO::FETCH_ASSOC) as $z) {
      $obj = "openranch_{$cid}_zone{$z['id']}";
      pub("homeassistant/switch/$obj/config", json_encode([
        'name'          => $z['name'] . ' (zone)',
        'unique_id'     => $obj,
        'command_topic' => "openranch/$cid/zone/{$z['id']}/set",
        'state_topic'   => "openranch/$cid/zone/{$z['id']}/state",
        'payload_on'    => '1', 'payload_off' => '0',
        'state_on'      => 'running', 'state_off' => 'idle',
        'device'        => ['identifiers' => ["openranch_{$cid}_irrigation"],
                            'name' => 'OpenRanch irrigation', 'manufacturer' => 'OpenRanch'],
      ]));
      $n++;
    }
  }
  vlog("discovery: published $n entity config(s)");
  return $n;
}

// ---- publish new readings --------------------------------------------------
function publish_new(PDO $db) {
  $last = (int)state_get($db, 'last_reading_id', '0');
  $cust = mqtt_customers($db);
  if (!$cust) return 0;

  $in = implode(',', array_map('intval', array_keys($cust)));
  $q = $db->prepare("SELECT r.id, r.device_id, r.variable, r.value, r.created,
                            d.slug, d.customer_id
                       FROM readings r JOIN devices d ON d.id = r.device_id
                      WHERE r.id > ? AND d.customer_id IN ($in)
                      ORDER BY r.id LIMIT 2000");
  $q->execute([$last]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  $n = 0; $maxId = $last;
  foreach ($rows as $r) {
    pub("openranch/{$r['customer_id']}/{$r['slug']}/{$r['variable']}", (string)(float)$r['value']);
    $maxId = max($maxId, (int)$r['id']);
    $n++;
  }
  // Zone state, so the HA switches reflect reality rather than last command.
  foreach ($cust as $cid => $_) {
    $zq = $db->prepare("SELECT z.id,
                          (SELECT COUNT(*) FROM irr_runs r
                            WHERE r.zone_id = z.id AND r.status = 'running') AS running
                         FROM irr_zones z WHERE z.customer_id = ? AND z.enabled = 1");
    $zq->execute([$cid]);
    foreach ($zq->fetchAll(PDO::FETCH_ASSOC) as $z) {
      pub("openranch/$cid/zone/{$z['id']}/state", $z['running'] ? 'running' : 'idle');
    }
  }
  if ($maxId > $last) state_set($db, 'last_reading_id', $maxId);
  if ($n) vlog("published $n reading(s), watermark $maxId");
  return $n;
}

// ---- commands in --------------------------------------------------------
// openranch/<customer>/<device-slug>/set   payload 0-5
// openranch/<customer>/zone/<id>/set       payload 1 = run default, 0 = stop
function handle_command(PDO $db, $topic, $payload) {
  $parts = explode('/', trim($topic, '/'));
  if (count($parts) < 4 || $parts[0] !== 'openranch') return;
  $cid = (int)$parts[1];

  $c = $db->prepare('SELECT id FROM customers WHERE id = ? AND mqtt_enabled = 1');
  $c->execute([$cid]);
  if (!$c->fetchColumn()) { vlog("cmd for customer $cid ignored: MQTT not enabled"); return; }

  if ($parts[2] === 'zone' && ($parts[4] ?? '') === 'set') {
    require_once __DIR__ . '/irrigation_lib.php';
    $z = irr_zone($db, $cid, (int)$parts[3]);
    if (!$z) { vlog("zone {$parts[3]} not on customer $cid"); return; }
    $on = trim($payload) === '1' || strtolower(trim($payload)) === 'on';
    if ($on) {
      $chk = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
      $chk->execute([$z['id']]);
      if ((int)$chk->fetchColumn() > 0) return;
      $id = irr_queue_run($db, $cid, $z['id'], (float)($z['default_minutes'] ?? 10), 'manual');
      irr_ensure_master($db, $cid, true);
      irr_start_run($db, $z, ['id' => $id, 'customer_id' => $cid,
                              'program_id' => null, 'planned_min' => (float)($z['default_minutes'] ?? 10)], $why);
      vlog("zone {$z['id']} started via MQTT");
    } else {
      $q = $db->prepare("SELECT * FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
      $q->execute([$z['id']]);
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $run) irr_stop_run($db, $z, $run, 'stopped');
      irr_ensure_master($db, $cid, irr_any_running($db, $cid));
      vlog("zone {$z['id']} stopped via MQTT");
    }
    return;
  }

  if (($parts[3] ?? '') !== 'set') return;
  $slug = $parts[2];
  $cmd  = (int)trim($payload);
  if ($cmd < 0 || $cmd > 5) { vlog("bad command payload for $slug"); return; }

  $col = claim_has_mirror_column($db) ? 'is_mirrored' : '0 AS is_mirrored';
  $q = $db->prepare("SELECT id, enabled, commandable, $col FROM devices
                      WHERE slug = ? AND customer_id = ?");
  $q->execute([$slug, $cid]);
  $d = $q->fetch(PDO::FETCH_ASSOC);
  if (!$d)                       { vlog("$slug not on customer $cid"); return; }
  if (!$d['enabled'])            { vlog("$slug is disabled"); return; }
  if (!$d['commandable'])        { vlog("$slug is not commandable"); return; }
  // A mirrored device's board polls the other system; a command written here
  // would be read by nobody, so refusing is the honest answer.
  if (!empty($d['is_mirrored']))  { vlog("$slug is mirrored -- refused"); return; }

  $db->prepare('INSERT INTO commands (device_id, cmd) VALUES (?, ?)')->execute([$d['id'], $cmd]);
  vlog("command $cmd -> $slug");
}

// ---- main ------------------------------------------------------------------
$db = db();
if ($discOnly) { ha_discover($db); exit(0); }
if ($once)     { publish_new($db); exit(0); }

ha_discover($db);
$lastDiscover = time();

// Subscribe in a child process; publish on a timer in this one.
$sub = sprintf('mosquitto_sub -h %s -p %d -u %s -P %s -t %s -F %%t%%%%%%p 2>/dev/null',
  HOST, PORT, escapeshellarg(BRIDGE_USER), escapeshellarg($pw),
  escapeshellarg('openranch/+/+/set'));
$sub2 = sprintf(' -t %s', escapeshellarg('openranch/+/zone/+/set'));
$h = popen($sub . $sub2, 'r');
if (!$h) { fwrite(STDERR, "could not start subscriber\n"); exit(1); }
stream_set_blocking($h, false);
vlog('bridge running');

while (true) {
  $line = fgets($h);
  if ($line !== false && trim($line) !== '') {
    [$t, $p] = array_pad(explode('%', trim($line), 2), 2, '');
    try { handle_command($db, $t, $p); }
    catch (Throwable $e) { vlog('command failed: ' . $e->getMessage()); }
    continue;                          // drain the queue before sleeping
  }
  publish_new($db);
  if (time() - $lastDiscover > 900) { ha_discover($db); $lastDiscover = time(); }
  usleep(2000000);
}
