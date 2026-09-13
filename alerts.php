<?php
// OpenRanch Dashboard v1 — stale-device email notifications
// Run from cron every 5 minutes on KVM 1:
//   */5 * * * * php /var/www/rcr-dash/alerts.php
//
// Emails ALERT_EMAIL when an ENABLED device goes silent past 3x its
// expected_interval. Sends once per outage (won't spam every 5 min),
// and sends a recovery note when it comes back.

require 'config.php';
require_once 'wpush.php';
require_once 'notify_lib.php';  // plain-English wording, with this file's text as the fallback   // web push helpers (no-op unless VAPID is configured)

// tiny state table, created on first run
db()->exec('CREATE TABLE IF NOT EXISTS alert_state (
  device_id INT PRIMARY KEY, alerted TINYINT DEFAULT 0)');

// v2: pull the assigned customer (if any) alongside the device so we can copy
// them on their own outages. Devices with customer_id NULL are the operator's own and
// behave exactly as before — ALERT_EMAIL only.
$devices = db()->query(
  'SELECT d.*, c.email AS customer_email, c.name AS customer_name
   FROM devices d LEFT JOIN customers c ON c.id = d.customer_id
   WHERE d.enabled = 1')->fetchAll(PDO::FETCH_ASSOC);

foreach ($devices as $d) {
  $stmt = db()->prepare('SELECT MAX(created) last FROM readings WHERE device_id = ?');
  $stmt->execute([$d['id']]);
  $last = $stmt->fetch(PDO::FETCH_ASSOC)['last'];

  $stale = false;
  if ($last === null) continue; // never reported yet — "waiting", not an outage
  $age = time() - strtotime($last);
  $stale = $age > 3 * $d['expected_interval'];

  $stmt = db()->prepare('SELECT alerted FROM alert_state WHERE device_id = ?');
  $stmt->execute([$d['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $alerted = $row ? (int)$row['alerted'] : 0;

  if ($stale && !$alerted) {
    mail(ALERT_EMAIL,
      '[OpenRanch] ' . $d['name'] . ' went silent',
      $d['name'] . ' (' . $d['slug'] . ") has not reported in " . round($age/60) .
      " minutes.\nLast seen: $last\nCheck power, WiFi, and the board.\n\n— OpenRanch Dashboard");
    // Copy the customer who owns this device, in their own words.
    if (!empty($d['customer_email'])) {
      mail($d['customer_email'],
        '[OpenRanch] ' . $d['name'] . ' has stopped reporting',
        "Your device \"" . $d['name'] . "\" has not reported in " . round($age/60) .
        " minutes.\nLast reading: $last\n\nPlease check the unit's power, WiFi signal," .
        " and that the board is running.\nIf it stays offline, contact OpenRanch." .
        "\n\n— OpenRanch");
    }
    // ...and push to their phones. Best effort: a push failure must never stop
    // the email notifications or the rest of the run.
    if (!empty($d['customer_id'])) {
      try {
        $body = notice_send(db(), (int)$d['customer_id'], 'offline',
          $d['name'] . ' has stopped reporting',
          ['device' => $d['name'], 'minutes_silent' => round($age / 60),
           'expected_interval_seconds' => (int)$d['expected_interval'],
           'last_reading_utc' => $last],
          'No data for ' . round($age / 60) . ' minutes. Tap to check the dashboard.');
        echo "  notice {$d['slug']}: " . $body . "\n";
      } catch (Throwable $e) {
        echo "  notice {$d['slug']} error: " . $e->getMessage() . "\n";
      }
    }
    db()->prepare('REPLACE INTO alert_state (device_id, alerted) VALUES (?, 1)')->execute([$d['id']]);
  } elseif (!$stale && $alerted) {
    mail(ALERT_EMAIL,
      '[OpenRanch] ' . $d['name'] . ' is back online',
      $d['name'] . ' (' . $d['slug'] . ") resumed reporting at $last.\n\n— OpenRanch Dashboard");
    if (!empty($d['customer_email'])) {
      mail($d['customer_email'],
        '[OpenRanch] ' . $d['name'] . ' is back online',
        "Your device \"" . $d['name'] . "\" resumed reporting at $last.\n\n— OpenRanch");
    }
    if (!empty($d['customer_id'])) {
      try {
        $body = notice_send(db(), (int)$d['customer_id'], 'back_online',
          $d['name'] . ' is back online',
          ['device' => $d['name'], 'resumed_utc' => $last],
          'Reporting resumed at ' . $last . '.');
        echo "  notice {$d['slug']}: " . $body . "\n";
      } catch (Throwable $e) {
        echo "  notice {$d['slug']} error: " . $e->getMessage() . "\n";
      }
    }
    db()->prepare('REPLACE INTO alert_state (device_id, alerted) VALUES (?, 0)')->execute([$d['id']]);
  }
}
echo "alerts check done\n";
