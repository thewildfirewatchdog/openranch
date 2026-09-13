<?php
// OpenRanch — nightly retention prune, per customer plan.
//
//   0 3 * * *  www-data  php /var/www/openranch/prune_readings.php
//
// Deletes readings older than the owning customer's allowance:
// FREE_RETENTION_DAYS for a free account, PRO_RETENTION_DAYS for 'pro'.
//
// Deliberately NOT touched here:
//   * devices with customer_id NULL  -- operator-owned, covered by ingest.php's
//     own RETENTION_DAYS self-prune
//   * devices with is_mirrored = 1   -- copies of another system's hardware,
//     aged out by rcr-prune.php on MIRROR_RETENTION_DAYS. This install is the
//     longer-term record for those rows, so shortening them here would destroy
//     the only copy.
//
//   --dry-run   count what would go, delete nothing
//   -v          per-customer detail

require __DIR__ . '/config.php';
require_once __DIR__ . '/claim_lib.php';
require_once __DIR__ . '/camera_lib.php';

$dry     = in_array('--dry-run', $argv ?? [], true);
$verbose = $dry || in_array('-v', $argv ?? [], true);
$db = db();

$free = defined('FREE_RETENTION_DAYS') ? (int)FREE_RETENTION_DAYS : 7;
$pro  = defined('PRO_RETENTION_DAYS')  ? (int)PRO_RETENTION_DAYS  : 90;

function vlog($m) { global $verbose; if ($verbose) echo $m, "\n"; }
vlog(sprintf('prune start %s UTC  free=%dd pro=%dd%s',
     gmdate('Y-m-d H:i:s'), $free, $pro, $dry ? '  [dry run]' : ''));

$mirrorSql = claim_has_mirror_column($db) ? ' AND d.is_mirrored = 0' : '';

$cs = $db->query('SELECT id, email, plan FROM customers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$totalDeleted = 0;

foreach ($cs as $c) {
  $days = ($c['plan'] ?? 'free') === 'pro' ? $pro : $free;

  $q = $db->prepare("SELECT d.id, d.slug FROM devices d
                      WHERE d.customer_id = ?" . $mirrorSql);
  $q->execute([$c['id']]);
  $devices = $q->fetchAll(PDO::FETCH_ASSOC);
  if (!$devices) continue;

  $forCustomer = 0;
  foreach ($devices as $d) {
    if ($dry) {
      $s = $db->prepare('SELECT COUNT(*) FROM readings
                          WHERE device_id = ? AND created < (NOW() - INTERVAL ? DAY)');
      $s->execute([$d['id'], $days]);
      $n = (int)$s->fetchColumn();
    } else {
      // Delete in chunks: one unbounded DELETE over a few hundred thousand rows
      // locks the table long enough for ingest.php to start timing out.
      $n = 0;
      $s = $db->prepare('DELETE FROM readings
                          WHERE device_id = ? AND created < (NOW() - INTERVAL ? DAY)
                          LIMIT 5000');
      do {
        $s->execute([$d['id'], $days]);
        $chunk = $s->rowCount();
        $n += $chunk;
        if ($chunk === 5000) usleep(200000);   // breathe between chunks
      } while ($chunk === 5000);
    }
    $forCustomer += $n;
  }
  // Snapshots follow the same allowance. Files and rows go together, so a
  // pruned frame cannot linger on disk with no index entry pointing at it.
  $snapGone = 0;
  foreach ($devices as $d) {
    $isCam = $db->prepare('SELECT is_camera FROM devices WHERE id = ?');
    $isCam->execute([$d['id']]);
    if (!$isCam->fetchColumn()) continue;
    $snapGone += cam_prune($db, $d['id'], $d['slug'], $days, $dry);
  }
  if ($snapGone) {
    vlog(sprintf('  customer %d: %s %d snapshot(s)', $c['id'],
         $dry ? 'would remove' : 'removed', $snapGone));
  }

  $totalDeleted += $forCustomer;
  if ($forCustomer) {
    vlog(sprintf('  customer %d (%s, %s, %dd): %s %d reading(s) across %d device(s)',
         $c['id'], $c['email'], $c['plan'] ?? 'free', $days,
         $dry ? 'would remove' : 'removed', $forCustomer, count($devices)));
  }
}

vlog(sprintf('prune done: %s %d reading(s) for %d customer(s)',
     $dry ? 'would remove' : 'removed', $totalDeleted, count($cs)));
if (!$verbose && $totalDeleted) {
  echo sprintf("%s prune removed %d readings\n", gmdate('Y-m-d H:i'), $totalDeleted);
}
