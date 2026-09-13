<?php
// OpenRanch — manual irrigation actions from the dashboard panel.
//
//   POST act=run     zone_id, minutes    start one zone now
//   POST act=stop    zone_id             close one zone now
//   POST act=stopall                     close everything now
//   POST act=runall                      run every zone for its default duration
//   POST act=hold    zone_id             open a zone with no end time
//   POST act=once    program_id          run a whole program once, now
//   POST act=delay   hours               hold every program for N hours
//   POST act=undelay                     clear the hold
//
// Starts happen here rather than being left to the next cron tick, so a button
// press opens a valve immediately instead of up to a minute later.

require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/wpush.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();
$back = $_POST['back'] ?? 'index.php';

function done($back, $msg, $err = false) {
  header('Location: ' . $back . ($err ? '?err=' : '?ok=') . rawurlencode($msg));
  exit;
}

$act = $_POST['act'] ?? '';

if ($act === 'run') {
  $z = irr_zone($db, $cid, (int)($_POST['zone_id'] ?? 0));
  if (!$z)               done($back, 'No such zone.', true);
  if (!$z['enabled'])    done($back, 'That zone is switched off.', true);
  $min = max(0.5, min(720, (float)($_POST['minutes'] ?? 10)));

  $st = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
  $st->execute([$z['id']]);
  if ((int)$st->fetchColumn() > 0) done($back, $z['name'] . ' is already running.', true);

  $id  = irr_queue_run($db, $cid, $z['id'], $min, 'manual');
  $run = ['id' => $id, 'customer_id' => $cid, 'program_id' => null, 'planned_min' => $min];
  irr_ensure_master($db, $cid, true);              // master opens first
  if (!irr_start_run($db, $z, $run, $why)) {
    irr_ensure_master($db, $cid, irr_any_running($db, $cid));
    done($back, 'Could not start ' . $z['name'] . ': ' . $why, true);
  }
  done($back, $z['name'] . ' running for ' . $min . ' min.');
}

if ($act === 'stop') {
  $z = irr_zone($db, $cid, (int)($_POST['zone_id'] ?? 0));
  if (!$z) done($back, 'No such zone.', true);
  $st = $db->prepare("SELECT * FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
  $st->execute([$z['id']]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $run) irr_stop_run($db, $z, $run, 'stopped');
  irr_ensure_master($db, $cid, irr_any_running($db, $cid));   // master closes after
  done($back, $z['name'] . ' stopped.');
}

if ($act === 'runall') {
  // Every enabled zone for its own default duration. Queued rather than opened
  // here: the scheduler starts them in order, and serves the master lead first.
  $n = 0;
  foreach (irr_zones($db, $cid, true) as $z) {
    if ($z['is_master']) continue;
    $st = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
    $st->execute([$z['id']]);
    if ((int)$st->fetchColumn() > 0) continue;
    irr_queue_run($db, $cid, $z['id'], (float)($z['default_minutes'] ?? 10), 'manual', null, (int)$z['sort_order']);
    $n++;
  }
  done($back, $n ? "Queued $n zone(s)." : 'Every zone is already running.', $n === 0);
}

if ($act === 'hold') {
  // Long-press: open the zone and leave it open. A run with no end time is
  // never stopped by the scheduler, so it waits for an explicit stop -- which
  // is the point, but it is also why the tile keeps saying "running".
  $z = irr_zone($db, $cid, (int)($_POST['zone_id'] ?? 0));
  if (!$z)            done($back, 'No such zone.', true);
  if (!$z['enabled']) done($back, 'That zone is switched off.', true);
  $st = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
  $st->execute([$z['id']]);
  if ((int)$st->fetchColumn() > 0) done($back, $z['name'] . ' is already running.', true);

  $id  = irr_queue_run($db, $cid, $z['id'], 0, 'manual');
  $run = ['id' => $id, 'customer_id' => $cid, 'program_id' => null, 'planned_min' => 0];
  irr_ensure_master($db, $cid, true);
  if (!irr_start_run($db, $z, $run, $why)) {
    irr_ensure_master($db, $cid, irr_any_running($db, $cid));
    done($back, 'Could not open ' . $z['name'] . ': ' . $why, true);
  }
  // planned_min 0 would give ends_at = now, so clear it: this run has no end.
  $db->prepare('UPDATE irr_runs SET ends_at = NULL WHERE id = ?')->execute([$id]);
  done($back, $z['name'] . ' held open until you stop it.');
}

if ($act === 'stopall') {
  $st = $db->prepare("SELECT r.* FROM irr_runs r WHERE r.customer_id = ? AND r.status IN ('queued','running')");
  $st->execute([$cid]);
  $n = 0;
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $run) {
    $z = irr_zone($db, $cid, $run['zone_id']);
    if ($z) { irr_stop_run($db, $z, $run, 'stopped'); $n++; }
  }
  irr_ensure_master($db, $cid, false);
  done($back, $n ? "Stopped $n zone(s)." : 'Nothing was running.');
}

if ($act === 'once') {
  $q = $db->prepare('SELECT * FROM irr_programs WHERE id = ? AND customer_id = ?');
  $q->execute([(int)($_POST['program_id'] ?? 0), $cid]);
  $p = $q->fetch(PDO::FETCH_ASSOC);
  if (!$p) done($back, 'No such program.', true);

  // Run-once deliberately ignores the weather and soil checks: someone pressed
  // the button, so the intent is explicit. Seasonal % still applies, because
  // that is the program's own idea of how long its zones should water.
  $zs = $db->prepare('SELECT pz.*, z.id zid, z.enabled FROM irr_program_zones pz
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
  done($back, $n ? $p['name'] . ': queued ' . $n . ' zone(s), starting now.' : 'That program has no zones to run.', $n === 0);
}

if ($act === 'delay') {
  $h = max(1, min(240, (int)($_POST['hours'] ?? 24)));
  $db->prepare('UPDATE irr_settings SET rain_delay_until = (NOW() + INTERVAL ? HOUR) WHERE customer_id = ?')
     ->execute([$h, $cid]);
  irr_settings($db, $cid);
  done($back, "Programs held for $h hours.");
}

if ($act === 'undelay') {
  $db->prepare('UPDATE irr_settings SET rain_delay_until = NULL WHERE customer_id = ?')->execute([$cid]);
  done($back, 'Hold cleared.');
}

done($back, 'Unknown action.', true);
