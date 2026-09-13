<?php
// OpenRanch — irrigation scheduler. Run once a minute:
//
//   * * * * * www-data /usr/bin/php /var/www/openranch/irrigation_cron.php
//
// One tick does five things, in this order, per customer:
//   1. fire any program whose start time is this minute
//   2. stop runs that have reached their end
//   3. start queued runs (one at a time for sequential programs)
//   4. watch for flow while nothing is scheduled
//   5. evaluate rules
//
// Hardware is only ever driven by writing `commands` rows, exactly as the
// dashboard buttons do, so no firmware change is needed.

require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/wpush.php';

$db  = db();
$utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$loc = $utc->setTimezone(irr_tz());
$verbose = in_array('-v', $argv ?? [], true);
function vlog($m) { global $verbose; if ($verbose) echo $m, "\n"; }

// Only customers that have set something up.
$cids = $db->query('SELECT DISTINCT customer_id FROM irr_zones')->fetchAll(PDO::FETCH_COLUMN);
vlog(sprintf('tick %s local (%s UTC), %d customer(s)',
     $loc->format('Y-m-d H:i'), $utc->format('H:i'), count($cids)));

foreach ($cids as $cid) {
  $cid = (int)$cid;
  $s   = irr_settings($db, $cid);
  $delayedUntil = $s['rain_delay_until'] ?? null;
  $delayed = $delayedUntil && (new DateTimeImmutable($delayedUntil)) > $utc;

  // ---- 1. fire programs ----------------------------------------------------
  $ps = $db->prepare('SELECT * FROM irr_programs WHERE customer_id = ? AND enabled = 1');
  $ps->execute([$cid]);
  foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) {
    if (!irr_day_matches($p, $loc))            continue;
    $hhmm = irr_time_matches($p, $loc);
    if ($hhmm === null)                        continue;

    // Claim this start slot. The UNIQUE key makes a second attempt a no-op, so
    // a restart inside the same minute cannot water twice.
    $ins = $db->prepare('INSERT IGNORE INTO irr_fires (program_id, fire_key) VALUES (?, ?)');
    $ins->execute([$p['id'], irr_fire_key($loc, $hhmm)]);
    if ($ins->rowCount() === 0) { vlog("  program {$p['id']} already fired $hhmm"); continue; }

    if ($delayed) {
      irr_log_skip($db, $cid, 'delay', 'rain delay until ' . $delayedUntil, $p['id']);
      vlog("  program {$p['id']} skipped: rain delay");
      continue;
    }

    // Weather is fetched at most once an hour per customer (irr_weather caches).
    $factor = 1.0;
    if (!empty($p['weather_adjust'])) {
      $w = irr_weather($db, $s, $utc);
      if ($w) {
        $d = irr_weather_decide($w, $s);
        if ($d['skip']) {
          irr_log_skip($db, $cid, $d['reason'], $d['detail'], $p['id']);
          vlog("  program {$p['id']} skipped: {$d['reason']} — {$d['detail']}");
          continue;
        }
        $factor = $d['factor'];
        vlog("  program {$p['id']} weather factor {$factor} ({$d['detail']})");
      }
    }

    $zs = $db->prepare('SELECT pz.*, z.* , pz.minutes AS pz_minutes, pz.sort_order AS pz_sort
                          FROM irr_program_zones pz
                          JOIN irr_zones z ON z.id = pz.zone_id
                         WHERE pz.program_id = ? AND z.enabled = 1 AND z.is_master = 0
                         ORDER BY pz.sort_order, pz.id');
    $zs->execute([$p['id']]);
    $queued = 0;
    foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $i => $z) {
      if ($z['soil_skip_above'] !== null) {
        $soil = irr_latest($db, $z['soil_device_id'], $z['soil_variable']);
        if (irr_soil_skip($soil, $z['soil_skip_above'])) {
          irr_log_skip($db, $cid, 'soil',
            sprintf('%s reads %.2f, at or above the %.2f limit', $z['soil_variable'],
                    $soil, (float)$z['soil_skip_above']), $p['id'], $z['id']);
          vlog("  zone {$z['id']} skipped: soil");
          continue;
        }
      }
      $min = irr_adjust_minutes($z['pz_minutes'], $p['seasonal_pct'], $factor);
      if ($min <= 0) {
        irr_log_skip($db, $cid, 'zero', 'adjusted duration came to zero minutes', $p['id'], $z['id']);
        continue;
      }
      irr_queue_run($db, $cid, $z['id'], $min, 'program', $p['id'], (int)$z['pz_sort'] * 100 + $i);
      $queued++;
    }
    vlog("  program {$p['id']} fired $hhmm, queued $queued zone(s)");
    if ($queued === 0) irr_log_skip($db, $cid, 'no_zones', 'nothing left to water after checks', $p['id']);
  }

  // ---- 2. stop runs that are due to end ------------------------------------
  $st = $db->prepare("SELECT * FROM irr_runs
                       WHERE customer_id = ? AND status = 'running'
                         AND ends_at IS NOT NULL AND ends_at <= NOW()");
  $st->execute([$cid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $run) {
    $z = irr_zone($db, $cid, $run['zone_id']);
    if ($z) { irr_stop_run($db, $z, $run, 'done'); vlog("  zone {$z['id']} finished"); }
    else    { $db->prepare("UPDATE irr_runs SET status='stopped', ended=NOW() WHERE id=?")->execute([$run['id']]); }
  }

  // ---- 3. start what is due ------------------------------------------------
  $st = $db->prepare("SELECT * FROM irr_runs WHERE customer_id = ? AND status = 'queued' ORDER BY seq, id");
  $st->execute([$cid]);
  $queuedRuns = $st->fetchAll(PDO::FETCH_ASSOC);

  // Group by program so each program's sequential flag is applied to its own
  // zones. Manual and rule-started runs (program_id NULL) each stand alone and
  // start immediately.
  $groups = [];
  foreach ($queuedRuns as $r) $groups[$r['program_id'] ?? ('solo:' . $r['id'])][] = $r;

  $toStart = [];
  foreach ($groups as $key => $rows) {
    if (strpos((string)$key, 'solo:') === 0) { $toStart[] = $rows[0]; continue; }
    $pr = $db->prepare('SELECT sequential FROM irr_programs WHERE id = ?');
    $pr->execute([$key]);
    $sequential = (int)$pr->fetchColumn() === 1;
    $runningOfProgram = $db->prepare("SELECT id FROM irr_runs WHERE program_id = ? AND status = 'running'");
    $runningOfProgram->execute([$key]);
    $running = $runningOfProgram->fetchAll(PDO::FETCH_ASSOC);
    foreach (irr_next_to_start($rows, $running, $sequential) as $r) $toStart[] = $r;
  }

  // The master valve opens before any zone does, and only closes once every
  // zone has: ensure it is open first, then open zones.
  if ($toStart) irr_ensure_master($db, $cid, true);
  foreach ($toStart as $r) {
    $z = irr_zone($db, $cid, $r['zone_id']);
    if (!$z) { $db->prepare("UPDATE irr_runs SET status='stopped', ended=NOW() WHERE id=?")->execute([$r['id']]); continue; }
    if (irr_start_run($db, $z, $r, $why)) vlog("  zone {$z['id']} started for {$r['planned_min']} min");
    else                                   vlog("  zone {$z['id']} could not start: $why");
  }
  irr_ensure_master($db, $cid, irr_any_running($db, $cid));

  // ---- 4. flow while nothing is scheduled ----------------------------------
  irr_check_unscheduled_flow($db, $cid, $s, $utc);

  // ---- 5. rules ------------------------------------------------------------
  irr_eval_rules($db, $cid, $utc);
}

vlog('done');
