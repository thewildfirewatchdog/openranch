<?php
// OpenRanch — server-side alarm threshold evaluation.
//
// Definitions only. Nothing here runs on include and nothing here starts a
// session, so ingest.php and poll.php keep sending byte-identical responses
// with no Set-Cookie — same rule as the session helpers in config.php.
//
// Who calls what:
//   ingest.php   or_threshold_ingest()  — evaluate + store on every reading
//   index.php    or_threshold_rollup()  — read the stored state back
//   thresholds.php                      — read/write the limit rows
//
// State model
// -----------
// A device is ok / warn / alarm. The state is computed ONCE, when a reading
// lands, and stored per (device, variable) in threshold_state. The dashboard
// reads it; it never re-derives it from the limits.
//
// A device with more than one gauged variable gets one stored row per variable
// and the dashboard shows the worst of them. That rollup is a max over one or
// two already-decided states, not a second evaluation of the limits.
//
// Hysteresis
// ----------
// A gauge sitting exactly on a limit would otherwise flip state on every
// reading — every 5 seconds on a fast board, which is worse than useless in an
// alarm banner. So a limit trips at its exact value on the way IN and only
// clears once the reading is 5% past it on the way OUT:
//
//   low_alarm 20   trips at <= 20,  clears at > 21     (20 + 5%)
//   high_alarm 450 trips at >= 450, clears at < 427.5  (450 - 5%)
//
// The 5% is of the limit's own magnitude, so the deadband scales with the
// number instead of being a fixed unit that is huge on a 20 and invisible on a
// 450. A limit of exactly 0 gets no deadband (0 * 5% = 0) — there is no
// meaningful percentage of zero, and a limit of 0 is a degenerate case anyway.
//
// The relaxed limit is used only while the device is ALREADY at that severity
// or worse, which is what makes the band one-directional. Coming down from
// alarm the device therefore steps alarm -> warn -> ok through two separate
// deadbands rather than jumping straight to ok.

if (!defined('OR_HYSTERESIS')) {

// Fraction of a limit's magnitude a reading must clear it by before the state
// relaxes. 0.05 = 5%.
define('OR_HYSTERESIS', 0.05);

// A device that has thresholds and has not reported for this many seconds is
// an alarm in its own right: a pressure gauge that stops talking is not a
// gauge reading 0, it is a gauge you can no longer trust.
//
// This is a flat number, deliberately, because that is what the alarm means.
// It is NOT derived from devices.expected_interval, so a gauge that reports
// less often than once a minute would sit permanently dead — check
// expected_interval before putting thresholds on a slow-reporting board.
define('OR_STALE_SECS', 60);

// Severity ranking. Anything unrecognised reads as ok.
function or_sev($state) {
  return $state === 'alarm' ? 2 : ($state === 'warn' ? 1 : 0);
}

function or_worse($a, $b) { return or_sev($a) >= or_sev($b) ? $a : $b; }

// The four limits, worst first — the first one a reading trips wins. `dir` is
// -1 for a limit a reading falls BELOW, +1 for one it rises ABOVE.
function or_threshold_limits() {
  return [
    ['low_alarm',  'alarm', -1],
    ['high_alarm', 'alarm',  1],
    ['low_warn',   'warn',  -1],
    ['high_warn',  'warn',   1],
  ];
}

// Evaluate one reading against one threshold row.
// $prev is the device's stored state for this variable ('ok' when there isn't
// one yet) and is what makes the hysteresis directional.
// Returns ['state'=>ok|warn|alarm, 'reason'=>column|'', 'limit'=>float|null].
function or_threshold_eval($value, $t, $prev = 'ok') {
  $value   = (float)$value;
  $prevSev = or_sev($prev);

  foreach (or_threshold_limits() as [$col, $state, $dir]) {
    if (!isset($t[$col]) || $t[$col] === null || $t[$col] === '') continue;
    $limit = (float)$t[$col];

    // Already at this severity (or worse)? Push the limit 5% further away, so
    // clearing it takes a real move and not a rounding wobble.
    $eff = ($prevSev >= or_sev($state))
         ? $limit - $dir * abs($limit) * OR_HYSTERESIS
         : $limit;

    $hit = $dir < 0 ? ($value <= $eff) : ($value >= $eff);
    if ($hit) return ['state' => $state, 'reason' => $col, 'limit' => $limit];
  }
  return ['state' => 'ok', 'reason' => '', 'limit' => null];
}

// Enabled threshold rows for one slug, keyed by variable.
function or_thresholds_for_slug($slug) {
  static $cache = [];
  if (isset($cache[$slug])) return $cache[$slug];
  $stmt = db()->prepare('SELECT * FROM thresholds WHERE slug = ? AND enabled = 1');
  $stmt->execute([$slug]);
  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['variable']] = $r;
  return $cache[$slug] = $out;
}

// EVERY threshold row, enabled or not, keyed slug => variable => row. One query,
// for the dashboard, which needs them for every card at once. Disabled rows are
// included so the edit form can still show — and re-enable — a row a customer
// has switched off; or_threshold_rollup() is what ignores them.
function or_thresholds_all() {
  $out = [];
  foreach (db()->query('SELECT * FROM thresholds')
                ->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[$r['slug']][$r['variable']] = $r;
  }
  return $out;
}

// Every stored state, keyed device_id => variable => row. One query, same reason.
function or_threshold_states_all() {
  $out = [];
  foreach (db()->query('SELECT * FROM threshold_state')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[(int)$r['device_id']][$r['variable']] = $r;
  }
  return $out;
}

// ---------------------------------------------------------------------------
// Called from ingest.php once a batch of readings is stored.
//
// $values is variable => value for the readings that were actually saved.
// Evaluates only the variables that have a threshold row, and writes the new
// state back. Returns the device's worst state, or null when it has no
// thresholds at all.
// ---------------------------------------------------------------------------
function or_threshold_ingest($deviceId, $slug, array $values) {
  $rows = or_thresholds_for_slug($slug);
  if (!$rows) return null;

  $prevStmt = db()->prepare(
    'SELECT state FROM threshold_state WHERE device_id = ? AND variable = ?');
  $upStmt = db()->prepare(
    'INSERT INTO threshold_state (device_id, variable, state, value, reason, updated)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE state = VALUES(state), value = VALUES(value),
                             reason = VALUES(reason), updated = VALUES(updated)');

  $worst = 'ok';
  foreach ($values as $var => $val) {
    if (!isset($rows[$var])) continue;
    $prevStmt->execute([$deviceId, $var]);
    $prev = $prevStmt->fetchColumn();
    $r = or_threshold_eval($val, $rows[$var], $prev === false ? 'ok' : $prev);
    $upStmt->execute([$deviceId, $var, $r['state'], (float)$val, $r['reason']]);
    $worst = or_worse($worst, $r['state']);
  }
  return $worst;
}

// A threshold row was edited: drop the stored state for it so the card is not
// left showing an alarm raised against limits that no longer exist. The next
// reading re-evaluates from a clean 'ok', which also restarts the hysteresis.
function or_threshold_reset_state($deviceId, $variable) {
  db()->prepare('DELETE FROM threshold_state WHERE device_id = ? AND variable = ?')
      ->execute([$deviceId, $variable]);
}

// ---------------------------------------------------------------------------
// Read side. Rolls one device's stored states up into the single ok/warn/alarm
// the dashboard paints, and applies the dead-sensor rule.
//
// The staleness check has to live here rather than in ingest.php: a device that
// has stopped reporting never runs ingest.php again, so nothing on the write
// side would ever notice. It is a timestamp comparison, not a re-evaluation of
// any limit, and it is not written back to threshold_state — the stored state
// stays the last thing the sensor actually said.
//
// Returns null for a device with no thresholds (no alarm concept at all), else
// ['state'=>..,'variable'=>..,'value'=>..,'limit'=>..,'reason'=>..].
// ---------------------------------------------------------------------------
function or_threshold_rollup(array $device, array $thresholds, array $states, $lastSeen) {
  if (!$device['enabled']) return null;

  $vars = array_map('trim', explode(',', $device['variables']));
  $mine = array_filter(array_intersect_key($thresholds, array_flip($vars)),
                       fn($t) => (int)$t['enabled'] === 1);
  if (!$mine) return null;

  // Dead sensor outranks whatever the last reading happened to say.
  $age = $lastSeen === null ? null : time() - strtotime($lastSeen . ' UTC');
  if ($age === null || $age > OR_STALE_SECS) {
    return ['state' => 'alarm', 'variable' => array_key_first($mine),
            'value' => null, 'limit' => null, 'reason' => 'stale', 'age' => $age];
  }

  $best = ['state' => 'ok', 'variable' => array_key_first($mine),
           'value' => null, 'limit' => null, 'reason' => '', 'age' => $age];
  foreach ($mine as $var => $t) {
    $s = $states[$var] ?? null;
    if (!$s) continue;
    if (or_sev($s['state']) > or_sev($best['state'])) {
      $best = ['state' => $s['state'], 'variable' => $var,
               'value' => $s['value'] === null ? null : (float)$s['value'],
               'limit' => $s['reason'] !== '' && isset($t[$s['reason']])
                          ? (float)$t[$s['reason']] : null,
               'reason' => $s['reason'], 'age' => $age];
    }
  }
  return $best;
}

}  // !defined(OR_HYSTERESIS)
