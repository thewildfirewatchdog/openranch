<?php
// OpenRanch — irrigation scheduling and automations, shared logic.
//
// Deliberately does NOT require config.php. Everything above the "database"
// divider is pure: it takes values and returns values, touches no connection
// and no clock of its own, so tests/irrigation_test.php can exercise the
// scheduling rules directly. Callers (irrigation_cron.php and the UI pages)
// require config.php themselves and pass db() in.

require_once __DIR__ . '/session_compat.php';
require_once __DIR__ . '/notify_lib.php';

// ===========================================================================
// Pure scheduling logic
// ===========================================================================

// Local wall-clock zone for schedules. Start times are what the customer typed
// on a clock on a wall, so they must not drift with the host's UTC.
function irr_tz() {
  if (defined('IRRIGATION_TZ') && IRRIGATION_TZ !== '') return new DateTimeZone(IRRIGATION_TZ);
  if (defined('FLOW_TZ') && FLOW_TZ !== '')             return new DateTimeZone(FLOW_TZ);
  return new DateTimeZone('UTC');
}

// "06:00, 18:30" -> ['06:00','18:30']. Anything unparseable is dropped rather
// than defaulted, so a typo cannot silently water at midnight.
function irr_parse_times($s) {
  $out = [];
  foreach (explode(',', (string)$s) as $bit) {
    $bit = trim($bit);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $bit, $m)) {
      $h = (int)$m[1]; $i = (int)$m[2];
      if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
        $out[] = sprintf('%02d:%02d', $h, $i);
      }
    }
  }
  return array_values(array_unique($out));
}

function irr_parse_dow($s) {
  $out = [];
  foreach (explode(',', (string)$s) as $bit) {
    $bit = trim($bit);
    if ($bit !== '' && ctype_digit($bit) && (int)$bit >= 0 && (int)$bit <= 6) $out[] = (int)$bit;
  }
  return array_values(array_unique($out));
}

// Does this program water on the given local day?
//   dow      -- day-of-week list, 0 = Sunday
//   interval -- every N days counted from interval_anchor (inclusive of it)
function irr_day_matches(array $p, DateTimeInterface $local) {
  if (($p['days_mode'] ?? 'dow') === 'interval') {
    $n = max(1, (int)($p['interval_days'] ?? 1));
    $anchor = $p['interval_anchor'] ?? null;
    if (!$anchor) return true;              // no anchor yet: every day
    $a = new DateTimeImmutable($anchor . ' 00:00:00', $local->getTimezone());
    $d = new DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', $local->getTimezone());
    $days = (int)$a->diff($d)->format('%r%a');
    if ($days < 0) return false;            // cycle has not started
    return ($days % $n) === 0;
  }
  $dow = irr_parse_dow($p['days_of_week'] ?? '');
  if (!$dow) return false;
  return in_array((int)$local->format('w'), $dow, true);
}

// The start time firing this minute, or null. Compared at minute resolution:
// the scheduler runs once a minute, so a start either matches this tick or is
// missed rather than fired late.
function irr_time_matches(array $p, DateTimeInterface $local) {
  $now = $local->format('H:i');
  foreach (irr_parse_times($p['start_times'] ?? '') as $t) if ($t === $now) return $t;
  return null;
}

// Identifies one start slot so it cannot fire twice (restart, clock jitter).
function irr_fire_key(DateTimeInterface $local, $hhmm) {
  return $local->format('Y-m-d') . ' ' . $hhmm;
}

// Seasonal % and the weather factor both scale a duration. Rounded to whole
// seconds' worth and floored at zero; a zone scaled to nothing is a skip, which
// the caller logs rather than opening a valve for 0 minutes.
function irr_adjust_minutes($baseMinutes, $seasonalPct, $weatherFactor = 1.0) {
  $m = (float)$baseMinutes * ((float)$seasonalPct / 100.0) * (float)$weatherFactor;
  if (!is_finite($m) || $m < 0) $m = 0.0;
  return round($m, 2);
}

// Open-Meteo daily numbers -> skip / scale decision.
//
//   $daily = ['past_mm' => float, 'today_mm' => float, 'today_tmax_c' => float|null]
//
// Default rule: skip when either yesterday's total or today's forecast exceeds
// rain_skip_mm; otherwise scale the run by temperature around a baseline, so a
// hot day waters longer and a cool one shorter. Both thresholds live in
// irr_settings, so the rule is adjustable without touching this function.
function irr_weather_decide(array $daily, array $s) {
  $limit = (float)($s['rain_skip_mm'] ?? 6);
  $past  = (float)($daily['past_mm'] ?? 0);
  $today = (float)($daily['today_mm'] ?? 0);

  if ($past > $limit) {
    return ['skip' => true, 'reason' => 'rain', 'factor' => 0.0,
            'detail' => sprintf('%.1f mm in the last 24h, limit %.1f mm', $past, $limit)];
  }
  if ($today > $limit) {
    return ['skip' => true, 'reason' => 'forecast', 'factor' => 0.0,
            'detail' => sprintf('%.1f mm forecast today, limit %.1f mm', $today, $limit)];
  }

  $tmax = $daily['today_tmax_c'] ?? null;
  if ($tmax === null) {
    return ['skip' => false, 'reason' => '', 'factor' => 1.0, 'detail' => 'no temperature, no scaling'];
  }
  $base = (float)($s['temp_baseline_c'] ?? 21);
  $per  = (float)($s['temp_pct_per_c'] ?? 3) / 100.0;
  $f    = 1.0 + ((float)$tmax - $base) * $per;
  $f    = max(0.5, min(1.5, $f));           // never less than half, never 1.5x
  return ['skip' => false, 'reason' => '', 'factor' => round($f, 3),
          'detail' => sprintf('max %.1fC vs baseline %.1fC -> x%.2f', $tmax, $base, $f)];
}

// Pull the two days we care about out of an Open-Meteo forecast response
// fetched with past_days=1&forecast_days=1: index 0 is yesterday, 1 is today.
function irr_weather_extract($json) {
  $d = is_array($json) ? ($json['daily'] ?? null) : null;
  if (!$d || empty($d['time'])) return null;
  $n = count($d['time']);
  $past  = $n >= 2 ? ($d['precipitation_sum'][0]   ?? 0) : 0;
  $today = $n >= 2 ? ($d['precipitation_sum'][1]   ?? 0) : ($d['precipitation_sum'][0] ?? 0);
  $tmax  = $n >= 2 ? ($d['temperature_2m_max'][1]  ?? null) : ($d['temperature_2m_max'][0] ?? null);
  return ['past_mm' => (float)$past, 'today_mm' => (float)$today,
          'today_tmax_c' => $tmax === null ? null : (float)$tmax];
}

// Soil probe: a reading at or above the zone's threshold means wet enough.
// A missing reading is NOT a skip -- a dead probe should not silently stop
// watering, it should water and let the notification path complain.
function irr_soil_skip($latest, $threshold) {
  if ($threshold === null || $threshold === '') return false;
  if ($latest === null)                         return false;
  return (float)$latest >= (float)$threshold;
}

// Has a deadline arrived? Both halves of the master lead are stored as absolute
// times -- irr_runs.start_after and irr_settings.master_close_after -- because a
// deadline survives a restart and cannot drift the way "stamp plus duration"
// recomputed each pass would. No deadline set means nothing to wait for.
function irr_due($deadline, DateTimeInterface $now) {
  if ($deadline === null || $deadline === '') return true;
  $t = $deadline instanceof DateTimeInterface ? $deadline : new DateTimeImmutable($deadline);
  return $t <= $now;
}

function irr_compare($a, $op, $b) {
  $a = (float)$a; $b = (float)$b;
  switch ($op) {
    case '>':  return $a >  $b;
    case '>=': return $a >= $b;
    case '<':  return $a <  $b;
    case '<=': return $a <= $b;
    case '==': return abs($a - $b) < 1e-9;
    case '!=': return abs($a - $b) >= 1e-9;
  }
  return false;
}

const IRR_OPS = ['>', '>=', '<', '<=', '==', '!='];

// Sequential programs run one zone at a time: the next queued run starts only
// when nothing from that program is still running. Parallel programs start
// everything at once. Returns the runs to start on this tick.
function irr_next_to_start(array $queued, array $running, $sequential) {
  if (!$sequential) return $queued;
  if ($running)     return [];
  usort($queued, fn($a, $b) => [$a['seq'], $a['id']] <=> [$b['seq'], $b['id']]);
  return $queued ? [$queued[0]] : [];
}

// The next local datetime this program would start after $from, or null if it
// would not start within the horizon. Used for the dashboard's summary card;
// the scheduler itself never looks ahead, it only answers "is it now".
function irr_next_occurrence(array $p, DateTimeInterface $from, $horizonDays = 14) {
  $tz    = $from->getTimezone();
  $times = irr_parse_times($p['start_times'] ?? '');
  if (!$times) return null;
  sort($times);

  for ($d = 0; $d <= $horizonDays; $d++) {
    $day = (new DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $tz))
             ->modify("+$d day");
    if (!irr_day_matches($p, $day)) continue;
    foreach ($times as $t) {
      $cand = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $t . ':00', $tz);
      if ($cand > $from) return $cand;
    }
  }
  return null;
}

// Gallons for a finished run, from the flow meter's cumulative total. A meter
// that was zeroed mid-run (or rolled over) reads lower at the end than the
// start; that is not negative water, so it is reported as unknown.
function irr_gallons($startTotal, $endTotal) {
  if ($startTotal === null || $endTotal === null) return null;
  $d = (float)$endTotal - (float)$startTotal;
  return $d < 0 ? null : round($d, 3);
}

// ===========================================================================
// Database layer. Everything below needs a live PDO; nothing above does.
// ===========================================================================

function irr_settings(PDO $db, $cid) {
  $st = $db->prepare('SELECT * FROM irr_settings WHERE customer_id = ?');
  $st->execute([$cid]);
  $s = $st->fetch(PDO::FETCH_ASSOC);
  if (!$s) {
    $db->prepare('INSERT INTO irr_settings (customer_id) VALUES (?)')->execute([$cid]);
    $st->execute([$cid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
  }
  return $s;
}

function irr_zones(PDO $db, $cid, $onlyEnabled = false) {
  $sql = 'SELECT * FROM irr_zones WHERE customer_id = ?'
       . ($onlyEnabled ? ' AND enabled = 1' : '')
       . ' ORDER BY is_master DESC, sort_order, id';
  $st = $db->prepare($sql); $st->execute([$cid]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function irr_zone(PDO $db, $cid, $zoneId) {
  $st = $db->prepare('SELECT * FROM irr_zones WHERE id = ? AND customer_id = ?');
  $st->execute([$zoneId, $cid]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function irr_master_zone(PDO $db, $cid) {
  $st = $db->prepare('SELECT * FROM irr_zones WHERE customer_id = ? AND is_master = 1 AND enabled = 1 LIMIT 1');
  $st->execute([$cid]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Latest value of one variable for one device, or null.
function irr_latest(PDO $db, $deviceId, $variable, $maxAgeMin = null) {
  if (!$deviceId) return null;
  $sql = 'SELECT value FROM readings WHERE device_id = ? AND variable = ?';
  $args = [$deviceId, $variable];
  if ($maxAgeMin !== null) { $sql .= ' AND created >= (NOW() - INTERVAL ? MINUTE)'; $args[] = (int)$maxAgeMin; }
  $sql .= ' ORDER BY created DESC, id DESC LIMIT 1';
  $st = $db->prepare($sql); $st->execute($args);
  $v = $st->fetchColumn();
  return $v === false ? null : (float)$v;
}

// The one place hardware is driven. Writes the same commands row the dashboard
// buttons write, so poll.php hands it to the board unchanged. Refuses anything
// the dashboard would also refuse, because a scheduler that quietly "commands"
// a mirrored or disabled device would report watering that never happened.
function irr_send_cmd(PDO $db, $deviceId, $cmd, &$why = null) {
  $st = $db->prepare('SELECT id, enabled, commandable, is_mirrored FROM devices WHERE id = ?');
  $st->execute([$deviceId]);
  $d = $st->fetch(PDO::FETCH_ASSOC);
  if (!$d)                      { $why = 'unknown device';   return false; }
  if (!$d['enabled'])           { $why = 'device disabled';  return false; }
  if (!$d['commandable'])       { $why = 'not commandable';  return false; }
  if (!empty($d['is_mirrored'])){ $why = 'device is mirrored'; return false; }
  $db->prepare('INSERT INTO commands (device_id, cmd) VALUES (?, ?)')
     ->execute([$deviceId, (int)$cmd]);
  return true;
}

function irr_log_skip(PDO $db, $cid, $reason, $detail, $programId = null, $zoneId = null) {
  $db->prepare('INSERT INTO irr_skips (customer_id, program_id, zone_id, reason, detail)
                VALUES (?, ?, ?, ?, ?)')
     ->execute([$cid, $programId, $zoneId, $reason, mb_strimwidth_safe($detail, 255)]);
}

// This PHP build has no mbstring; cut on character boundaries with a regex so a
// multibyte detail string cannot be split mid-character.
function mb_strimwidth_safe($s, $max) {
  return preg_replace('/^(.{0,' . (int)$max . '}).*$/us', '$1', (string)$s);
}

// ---- weather ---------------------------------------------------------------
// Cached per customer per hour, as required: one outbound request an hour per
// customer no matter how many programs or ticks ask for it.
function irr_weather(PDO $db, array $s, $now = null) {
  $now = $now ?: new DateTimeImmutable('now');
  if (empty($s['weather_enabled'])) return null;

  if (!empty($s['weather_at']) && !empty($s['weather_json'])) {
    $age = $now->getTimestamp() - (new DateTimeImmutable($s['weather_at']))->getTimestamp();
    if ($age < 3600) {
      $j = json_decode($s['weather_json'], true);
      return irr_weather_extract($j);
    }
  }

  [$lat, $lon] = irr_resolve_latlon($db, $s);
  if ($lat === null || $lon === null) return null;

  $url = 'https://api.open-meteo.com/v1/forecast?'
       . http_build_query([
           'latitude' => $lat, 'longitude' => $lon,
           'daily' => 'precipitation_sum,temperature_2m_max',
           'past_days' => 1, 'forecast_days' => 1, 'timezone' => 'auto',
         ]);
  $raw = irr_http_get($url);
  if ($raw === null) return null;                    // keep the old cache
  $j = json_decode($raw, true);
  if (!is_array($j) || empty($j['daily'])) return null;

  $db->prepare('UPDATE irr_settings SET weather_json = ?, weather_at = NOW() WHERE customer_id = ?')
     ->execute([$raw, $s['customer_id']]);
  return irr_weather_extract($j);
}

// A zip alone is enough to start with: geocode it once and keep the lat/lon.
function irr_resolve_latlon(PDO $db, array $s) {
  if ($s['lat'] !== null && $s['lon'] !== null) return [(float)$s['lat'], (float)$s['lon']];
  $zip = trim((string)($s['zip'] ?? ''));
  if ($zip === '') return [null, null];

  $raw = irr_http_get('https://geocoding-api.open-meteo.com/v1/search?'
                    . http_build_query(['name' => $zip, 'count' => 1]));
  if ($raw === null) return [null, null];
  $j = json_decode($raw, true);
  $hit = $j['results'][0] ?? null;
  if (!$hit || !isset($hit['latitude'], $hit['longitude'])) return [null, null];

  $db->prepare('UPDATE irr_settings SET lat = ?, lon = ? WHERE customer_id = ?')
     ->execute([$hit['latitude'], $hit['longitude'], $s['customer_id']]);
  return [(float)$hit['latitude'], (float)$hit['longitude']];
}

// No curl extension on this build, so the stream wrapper does the work. A
// failed fetch returns null and the caller keeps whatever it had cached rather
// than treating "no answer" as "no rain".
function irr_http_get($url, $timeout = 8) {
  $ctx = stream_context_create(['http' => [
    'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
    'header' => "User-Agent: OpenRanch-irrigation/1.0\r\n",
  ]]);
  $body = @file_get_contents($url, false, $ctx);
  if ($body === false) return null;
  foreach (($http_response_header ?? []) as $h) {
    if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m) && (int)$m[1] !== 200) return null;
  }
  return $body;
}

// ---- runs ------------------------------------------------------------------

function irr_running(PDO $db, $cid) {
  $st = $db->prepare("SELECT r.*, z.is_master FROM irr_runs r
                        JOIN irr_zones z ON z.id = r.zone_id
                       WHERE r.customer_id = ? AND r.status = 'running'");
  $st->execute([$cid]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function irr_queue_run(PDO $db, $cid, $zoneId, $minutes, $source = 'manual', $programId = null, $seq = 0) {
  $db->prepare("INSERT INTO irr_runs (customer_id, zone_id, program_id, source, status, seq, planned_min)
                VALUES (?, ?, ?, ?, 'queued', ?, ?)")
     ->execute([$cid, $zoneId, $programId, $source, $seq, $minutes]);
  return (int)$db->lastInsertId();
}

// Opens a zone. Records the flow meter's cumulative total at the moment it
// opens so the gallons for this run can be differenced out when it closes.
function irr_start_run(PDO $db, array $zone, array $run, &$why = null) {
  if (!irr_send_cmd($db, $zone['device_id'], $zone['cmd_on'], $why)) {
    $db->prepare("UPDATE irr_runs SET status='stopped', ended=NOW() WHERE id=?")->execute([$run['id']]);
    irr_log_skip($db, $run['customer_id'], 'device', 'could not open zone: ' . $why,
                 $run['program_id'], $zone['id']);
    return false;
  }
  $total = $zone['flow_device_id']
         ? irr_latest($db, $zone['flow_device_id'], $zone['total_variable']) : null;
  $db->prepare("UPDATE irr_runs
                   SET status='running', started=NOW(),
                       ends_at = (NOW() + INTERVAL ? SECOND), start_total = ?
                 WHERE id = ?")
     ->execute([(int)round($run['planned_min'] * 60), $total, $run['id']]);
  return true;
}

function irr_stop_run(PDO $db, array $zone, array $run, $status = 'done') {
  irr_send_cmd($db, $zone['device_id'], $zone['cmd_off']);
  $end   = $zone['flow_device_id']
         ? irr_latest($db, $zone['flow_device_id'], $zone['total_variable']) : null;
  $gal   = irr_gallons($run['start_total'] ?? null, $end);
  $db->prepare("UPDATE irr_runs SET status=?, ended=NOW(), gallons=? WHERE id=?")
     ->execute([$status, $gal, $run['id']]);
}

// The master valve must be open whenever any other zone is, and shut when none
// is. Only writes a command when the state actually needs to change, so a
// per-minute cron does not fill the commands table with duplicates.
// True when the last command written to the master's device was its open code.
// The commands table is the only record of what we told the hardware, so it is
// also how the scheduler knows whether a lead has already been served.
function irr_master_is_open(PDO $db, array $master) {
  $st = $db->prepare('SELECT cmd FROM commands WHERE device_id = ? ORDER BY id DESC LIMIT 1');
  $st->execute([$master['device_id']]);
  $last = $st->fetchColumn();
  return $last !== false && (int)$last === (int)$master['cmd_on'];
}

function irr_ensure_master(PDO $db, $cid, $wantOpen) {
  $m = irr_master_zone($db, $cid);
  if (!$m) return;
  $st = $db->prepare('SELECT cmd FROM commands WHERE device_id = ? ORDER BY id DESC LIMIT 1');
  $st->execute([$m['device_id']]);
  $last = $st->fetchColumn();
  $want = $wantOpen ? (int)$m['cmd_on'] : (int)$m['cmd_off'];
  if ($last === false || (int)$last !== $want) irr_send_cmd($db, $m['device_id'], $want);
}

function irr_any_queued(PDO $db, $cid) {
  $st = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE customer_id = ? AND status = 'queued'");
  $st->execute([$cid]);
  return (int)$st->fetchColumn() > 0;
}

function irr_any_running(PDO $db, $cid) {
  $st = $db->prepare("SELECT COUNT(*) FROM irr_runs r JOIN irr_zones z ON z.id = r.zone_id
                       WHERE r.customer_id = ? AND r.status = 'running' AND z.is_master = 0");
  $st->execute([$cid]);
  return (int)$st->fetchColumn() > 0;
}

// ---- notifications ---------------------------------------------------------
// Wording note: these are notifications, not alerts -- the UI and the payloads
// both say "notification" throughout.
function irr_notify(PDO $db, $cid, $title, $body, $kind = 'irrigation', array $context = []) {
  // notify_lib rewrites the wording through Claude when that is available and
  // the customer has budget left, and falls back to exactly this text when it
  // is not. Delivery (push + Telegram) is its job too.
  if (function_exists('notice_send')) {
    try { notice_send($db, $cid, $kind, $title, $context ?: ['message' => $body], $body); return; }
    catch (Throwable $e) { error_log('notice_send failed: ' . $e->getMessage()); }
  }
  if (function_exists('wp_send_to_customer')) {
    try { wp_send_to_customer($cid, ['title' => $title, 'body' => $body, 'tag' => 'openranch-irrigation']); }
    catch (Throwable $e) { /* push is best-effort; the log is the record */ }
  }
}

// ---- unscheduled flow ------------------------------------------------------
// A zone's meter reporting flow while that zone is closed means water is moving
// that nobody asked for. One notification per episode, after the configured
// number of minutes, so a brief pressure blip does not send anything.
function irr_check_unscheduled_flow(PDO $db, $cid, array $s, DateTimeInterface $utc) {
  $minutes = max(1, (int)($s['leak_minutes'] ?? 10));
  $minGpm  = (float)($s['leak_min_gpm'] ?? 0.2);

  foreach (irr_zones($db, $cid, true) as $z) {
    if (!$z['flow_device_id']) continue;

    $st = $db->prepare("SELECT COUNT(*) FROM irr_runs
                         WHERE zone_id = ? AND status = 'running'");
    $st->execute([$z['id']]);
    $zoneRunning = (int)$st->fetchColumn() > 0;

    // Only readings recent enough to describe now; a stale meter is not flow.
    $gpm = irr_latest($db, $z['flow_device_id'], $z['flow_variable'], 15);
    $flowing = $gpm !== null && $gpm > $minGpm;

    $ls = $db->prepare('SELECT * FROM irr_leak_state WHERE zone_id = ?');
    $ls->execute([$z['id']]);
    $state = $ls->fetch(PDO::FETCH_ASSOC);

    if ($zoneRunning || !$flowing) {
      if ($state && $state['since'] !== null) {
        $db->prepare('UPDATE irr_leak_state SET since = NULL, notified = NULL WHERE zone_id = ?')
           ->execute([$z['id']]);
      }
      continue;
    }

    if (!$state) {
      $db->prepare('INSERT INTO irr_leak_state (zone_id, customer_id, since) VALUES (?, ?, NOW())')
         ->execute([$z['id'], $cid]);
      continue;
    }
    if ($state['since'] === null) {
      $db->prepare('UPDATE irr_leak_state SET since = NOW(), notified = NULL WHERE zone_id = ?')
         ->execute([$z['id']]);
      continue;
    }
    if ($state['notified'] !== null) continue;          // already told them

    $mins = ($utc->getTimestamp() - (new DateTimeImmutable($state['since']))->getTimestamp()) / 60;
    if ($mins < $minutes) continue;

    $detail = sprintf('%s reads %.2f with the zone closed, for %d min',
                      $z['flow_variable'], $gpm, (int)$mins);
    irr_notify($db, $cid, 'Unscheduled flow on ' . $z['name'], $detail, 'flow_watch', [
      'zone' => $z['name'], 'metric' => $z['flow_variable'], 'gallons_per_minute' => $gpm,
      'minutes_flowing' => (int)$mins, 'zone_is_closed' => true,
      'limit_gpm' => $minGpm, 'notify_after_minutes' => $minutes,
    ]);
    irr_log_skip($db, $cid, 'flow_watch', $z['name'] . ': ' . $detail, null, $z['id']);
    $db->prepare('UPDATE irr_leak_state SET notified = NOW() WHERE zone_id = ?')->execute([$z['id']]);
  }
}

// ---- rules -----------------------------------------------------------------
// IF <device.metric> <op> <value> [held for N minutes] THEN <action>.
// met_since records when the condition first became true, which is what makes
// "for N minutes" work across ticks without keeping anything in memory.
function irr_eval_rules(PDO $db, $cid, DateTimeInterface $utc) {
  $rs = $db->prepare('SELECT * FROM irr_rules WHERE customer_id = ? AND enabled = 1');
  $rs->execute([$cid]);

  foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $v   = irr_latest($db, $r['device_id'], $r['metric'], 60);
    $met = $v !== null && irr_compare($v, $r['op'], $r['value']);

    if (!$met) {
      if ($r['met_since'] !== null) {
        $db->prepare('UPDATE irr_rules SET met_since = NULL WHERE id = ?')->execute([$r['id']]);
      }
      continue;
    }
    if ($r['met_since'] === null) {
      $db->prepare('UPDATE irr_rules SET met_since = NOW() WHERE id = ?')->execute([$r['id']]);
      if ((int)$r['for_minutes'] > 0) continue;         // needs to be held first
      $heldMin = 0;
    } else {
      $heldMin = ($utc->getTimestamp() - (new DateTimeImmutable($r['met_since']))->getTimestamp()) / 60;
      if ($heldMin < (int)$r['for_minutes']) continue;
    }

    // Cooldown keeps a rule that stays true from firing every single minute.
    if ($r['last_fired'] !== null) {
      $since = ($utc->getTimestamp() - (new DateTimeImmutable($r['last_fired']))->getTimestamp()) / 60;
      if ($since < (int)$r['cooldown_min']) continue;
    }

    irr_fire_rule($db, $cid, $r, $v);
    $db->prepare('UPDATE irr_rules SET last_fired = NOW() WHERE id = ?')->execute([$r['id']]);
  }
}

function irr_fire_rule(PDO $db, $cid, array $r, $value) {
  $msg = $r['message'] !== '' ? $r['message']
       : sprintf('%s %s %s (now %s)', $r['metric'], $r['op'], $r['value'], $value);

  switch ($r['action']) {
    case 'command':
      if ($r['action_device_id'] !== null && $r['action_cmd'] !== null) {
        irr_send_cmd($db, $r['action_device_id'], (int)$r['action_cmd'], $why);
      }
      break;

    case 'zone':
      if ($r['action_zone_id'] !== null) {
        $z = irr_zone($db, $cid, $r['action_zone_id']);
        // Don't stack a second run on a zone that is already open.
        $st = $db->prepare("SELECT COUNT(*) FROM irr_runs WHERE zone_id = ? AND status IN ('queued','running')");
        $st->execute([$r['action_zone_id']]);
        if ($z && (int)$st->fetchColumn() === 0) {
          irr_queue_run($db, $cid, $z['id'], (float)$r['action_minutes'], 'rule', null, 0);
        }
      }
      break;
  }

  // Every action notifies, so a rule that moved hardware is never silent.
  irr_notify($db, $cid, 'Automation: ' . $r['name'], $msg, 'automation', [
    'automation' => $r['name'], 'metric' => $r['metric'], 'operator' => $r['op'],
    'limit' => (float)$r['value'], 'reading_now' => $value,
    'held_for_minutes' => (int)$r['for_minutes'], 'action' => $r['action'],
  ]);
  $db->prepare('INSERT INTO irr_skips (customer_id, reason, detail) VALUES (?, ?, ?)')
     ->execute([$cid, 'rule', mb_strimwidth_safe($r['name'] . ': ' . $msg, 255)]);
}
