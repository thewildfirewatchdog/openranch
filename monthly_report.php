<?php
// OpenRanch — monthly water-use email, sent on the 1st for the month just gone.
//
//   0 7 1 * *  www-data  php /var/www/openranch/monthly_report.php
//
//   --dry-run   print instead of sending
//   --month=YYYY-MM   a specific month rather than last
//
// Uses the same mail() path alerts.php already uses, so it inherits whatever
// MTA the host has. Customers opt in on the Integrations page.

require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$month = null;
foreach ($argv ?? [] as $a) if (preg_match('/^--month=(\d{4}-\d{2})$/', $a, $m)) $month = $m[1];
if ($month === null) $month = gmdate('Y-m', strtotime('first day of last month'));

$start = "$month-01 00:00:00";
$end   = gmdate('Y-m-d 00:00:00', strtotime("$month-01 +1 month"));
$label = gmdate('F Y', strtotime("$month-01"));
$db = db();

$cs = $db->query('SELECT id, email, name FROM customers WHERE monthly_email = 1')
         ->fetchAll(PDO::FETCH_ASSOC);
echo count($cs), " customer(s) opted in for $label\n";

foreach ($cs as $c) {
  $cid = (int)$c['id'];

  $q = $db->prepare("SELECT z.name, COUNT(*) runs,
                            COALESCE(SUM(r.gallons), 0) gallons,
                            COALESCE(SUM(TIMESTAMPDIFF(SECOND, r.started, r.ended)), 0) secs
                       FROM irr_runs r JOIN irr_zones z ON z.id = r.zone_id
                      WHERE r.customer_id = ? AND r.started >= ? AND r.started < ?
                        AND r.status IN ('done','stopped')
                   GROUP BY z.id, z.name ORDER BY gallons DESC, z.name");
  $q->execute([$cid, $start, $end]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $sq = $db->prepare("SELECT reason, COUNT(*) n FROM irr_skips
                       WHERE customer_id = ? AND created >= ? AND created < ?
                    GROUP BY reason ORDER BY n DESC");
  $sq->execute([$cid, $start, $end]);
  $skips = $sq->fetchAll(PDO::FETCH_ASSOC);

  $totGal  = array_sum(array_column($rows, 'gallons'));
  $totRuns = array_sum(array_column($rows, 'runs'));
  $totMin  = array_sum(array_column($rows, 'secs')) / 60;

  $who = $c['name'] !== '' ? $c['name'] : $c['email'];
  $body  = "Water use for $label\n";
  $body .= str_repeat('-', 30) . "\n\n";
  if (!$rows) {
    $body .= "No watering was recorded in $label.\n";
  } else {
    $body .= sprintf("%d watering run%s across %d zone%s, %s minutes in total%s.\n\n",
      $totRuns, $totRuns === 1 ? '' : 's', count($rows), count($rows) === 1 ? '' : 's',
      number_format($totMin, 0),
      $totGal > 0 ? ', ' . number_format($totGal, 1) . ' gallons measured' : '');
    foreach ($rows as $r) {
      $body .= sprintf("  %-22s %3d run%s  %6s min%s\n",
        substr($r['name'], 0, 22), $r['runs'], $r['runs'] == 1 ? ' ' : 's',
        number_format($r['secs'] / 60, 0),
        $r['gallons'] > 0 ? '  ' . number_format($r['gallons'], 1) . ' gal' : '');
    }
    $body .= "\nGallons are shown only for zones with a flow meter.\n";
  }
  if ($skips) {
    $body .= "\nWatering was skipped for these reasons:\n";
    foreach ($skips as $s) $body .= sprintf("  %-14s %d time%s\n", $s['reason'], $s['n'], $s['n'] == 1 ? '' : 's');
  }
  $body .= "\nTurn this email off any time on the Integrations page.\n\n— OpenRanch\n";

  $subject = '[OpenRanch] Water use for ' . $label;
  if ($dry) {
    echo "--- $who <{$c['email']}> ---\n$body\n";
    continue;
  }
  $ok = @mail($c['email'], $subject, $body, "From: " . (defined('ALERT_EMAIL') ? ALERT_EMAIL : 'openranch'));
  echo ($ok ? '  sent to ' : '  FAILED for ') . $c['email'] . "\n";
}
