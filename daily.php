<?php
// OpenRanch Dashboard — daily water usage endpoint
//
//   GET /daily.php?slug=<device_slug>
//
// Returns the last 7 calendar days (including today as a partial day) with the
// gallons used on each, for the flow meter card's bar chart.
//
//   {"slug":"...","timezone":"America/Los_Angeles","min_gpm":5,"days":[
//      {"date":"2026-08-24","label":"Mon 8/24","gallons":312.4,"today":false}, ... ]}
//
// Always exactly 7 entries, oldest first, ending today. A day with no readings
// at all is reported as 0 rather than omitted, so the chart never changes shape.
//
// How a day's usage is computed:
//   - total_gal is a running totaliser, so usage is the sum of the POSITIVE
//     increases between consecutive readings.
//   - A negative delta means the totaliser was reset (the RESET TOTAL button, or
//     a board reboot), so it contributes nothing rather than a negative number.
//   - An increase only counts if the flow_gpm reported alongside the LATER of the
//     two readings is >= MIN_GPM. Below that it is a drip, a leak or sensor
//     noise, not usage.
//   - Deltas are never carried across midnight: each day's chain starts at that
//     day's first reading, so water that ran through the boundary is not
//     attributed to whichever day happened to log the next reading.
//
// This endpoint only reads. It adds no contract to ingest.php / poll.php /
// register.php / cmd.php and does not touch them.

require 'config.php';
or_session_resume();
$customer = current_customer();

// Day boundaries are local midnights in FLOW_TZ, not UTC. The host clock may be UTC
// and MySQL writes `created` in UTC; this is a presentation-time conversion, so
// nothing about how readings are stored changes.
// Calendar days are computed in this timezone, not the server's. Set FLOW_TZ in
// config.php to your site's local zone; this is only the fallback.
if (!defined('FLOW_TZ')) define('FLOW_TZ', 'UTC');
define('DAILY_DAYS', 7);
define('MIN_GPM',  5.0);

$slug = $_GET['slug'] ?? '';

$stmt = db()->prepare('SELECT id, variables, enabled, customer_id FROM devices WHERE slug = ?');
$stmt->execute([$slug]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$d) json_out(['error' => 'unknown device'], 404);

// Same rule as the ?history= endpoint: a signed-in customer sees only their own
// devices; an anonymous visitor sees what the dashboard already shows them.
if ($customer && (int)$d['customer_id'] !== (int)$customer['id']) {
  json_out(['error' => 'not your device'], 403);
}

$vars = array_map('trim', explode(',', $d['variables']));
if (!in_array('total_gal', $vars, true)) {
  json_out(['error' => 'device does not report total_gal'], 404);
}

// ---- the 7 day buckets, oldest first -------------------------------------
$tz    = new DateTimeZone(FLOW_TZ);
$utc   = new DateTimeZone('UTC');
$today = (new DateTime('now', $tz))->setTime(0, 0, 0);
$start = (clone $today)->modify('-' . (DAILY_DAYS - 1) . ' days');

$days = [];          // 'Y-m-d' => running total
$order = [];
for ($i = 0; $i < DAILY_DAYS; $i++) {
  $day = (clone $start)->modify("+$i days");
  $key = $day->format('Y-m-d');
  $days[$key] = 0.0;
  $order[] = ['key' => $key, 'dt' => $day];
}

// ---- readings from the window --------------------------------------------
// `created` is UTC, so the local-midnight start is converted before comparing.
$stmt = db()->prepare(
  'SELECT variable, value, created FROM readings
   WHERE device_id = ? AND variable IN (?, ?) AND created >= ?
   ORDER BY created, id');
$stmt->execute([
  $d['id'], 'total_gal', 'flow_gpm',
  (clone $start)->setTimezone($utc)->format('Y-m-d H:i:s'),
]);

// One board report is several rows written back to back in the same second, so
// rows are grouped into reports to pair a total_gal with the flow_gpm from the
// SAME report.
//
// Grouping on the timestamp alone is not enough: two POSTs can land inside one
// second (a board retrying, or a burst), and the later one would then overwrite
// the earlier one's values and silently erase a real increase. `created` is only
// second-resolution, so the tie-break is the repeated variable — seeing a
// variable twice means a new report has started. Rows arrive in insertion order,
// and one POST writes its rows contiguously, so this splits exactly on the
// POST boundary regardless of the order the board lists its variables in.
$reports = [];       // list of ['ts'=>string, 'total'=>?float, 'gpm'=>?float]
$cur = null;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $key = $row['variable'] === 'total_gal' ? 'total' : 'gpm';
  if ($cur === null || $cur['ts'] !== $row['created'] || $cur[$key] !== null) {
    if ($cur !== null) $reports[] = $cur;
    $cur = ['ts' => $row['created'], 'total' => null, 'gpm' => null];
  }
  $cur[$key] = (float)$row['value'];
}
if ($cur !== null) $reports[] = $cur;

// ---- walk the reports, accumulating positive deltas per day ---------------
$prevTotal = null;   // previous total_gal within the CURRENT day
$prevDay   = null;
$lastGpm   = null;   // most recent flow_gpm seen, for reports that lack one

foreach ($reports as $r) {
  if ($r['gpm'] !== null) $lastGpm = $r['gpm'];
  if ($r['total'] === null) continue;

  $dayKey = (new DateTime($r['ts'], $utc))->setTimezone($tz)->format('Y-m-d');
  if (!isset($days[$dayKey])) continue;            // outside the 7-day window

  if ($dayKey !== $prevDay) {                      // new day: start a fresh chain
    $prevDay = $dayKey;
    $prevTotal = $r['total'];
    continue;
  }

  $gpm = $r['gpm'] !== null ? $r['gpm'] : $lastGpm;
  $delta = $r['total'] - $prevTotal;
  if ($delta > 0 && $gpm !== null && $gpm >= MIN_GPM) {
    $days[$dayKey] += $delta;
  }
  $prevTotal = $r['total'];                        // resets advance the baseline too
}

$todayKey = $today->format('Y-m-d');
json_out([
  'slug'     => $slug,
  'timezone' => FLOW_TZ,
  'min_gpm'  => MIN_GPM,
  'days'     => array_map(fn($o) => [
    'date'    => $o['key'],
    'label'   => $o['dt']->format('D n/j'),        // "Sat 8/29"
    'gallons' => round($days[$o['key']], 1),
    'today'   => $o['key'] === $todayKey,
  ], $order),
]);
