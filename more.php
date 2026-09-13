<?php
// OpenRanch — "More": the pages that do not earn a slot in the bottom nav.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$db = db();
$cid = (int)$customer['id'];

$counts = [];
foreach (['irr_zones' => 'zones', 'irr_programs' => 'programs', 'irr_rules' => 'automations'] as $t => $k) {
  $q = $db->prepare("SELECT COUNT(*) FROM $t WHERE customer_id = ?");
  $q->execute([$cid]); $counts[$k] = (int)$q->fetchColumn();
}
$q = $db->prepare('SELECT COUNT(*) FROM devices WHERE customer_id = ?');
$q->execute([$cid]); $counts['devices'] = (int)$q->fetchColumn();

irr_head('More', 'more.php');
?>
<div class="card">
  <h2>Your account</h2>
  <table>
    <tr><td>Signed in as</td><td><b><?= htmlspecialchars($customer['email']) ?></b></td></tr>
    <tr><td>Devices</td><td><?= $counts['devices'] ?></td></tr>
    <tr><td>Zones</td><td><?= $counts['zones'] ?></td></tr>
    <tr><td>Programs</td><td><?= $counts['programs'] ?></td></tr>
    <tr><td>Automations</td><td><?= $counts['automations'] ?></td></tr>
  </table>
</div>

<div class="card">
  <h2>Go to</h2>
  <div class="actions">
    <a class="btn ghost" href="controls.php">Controls</a>
    <a class="btn ghost" href="index.php">Sensors dashboard</a>
    <a class="btn ghost" href="zones.php">Zones</a>
    <a class="btn ghost" href="programs.php">Programs &amp; weather</a>
    <a class="btn ghost" href="rules.php">Automations</a>
    <a class="btn ghost" href="claim.php">Add a device</a>
  </div>
</div>

<div class="card">
  <h2>Notifications</h2>
  <p style="font-size:13px;color:var(--dim)">
    Turn on notifications from the Sensors dashboard. On iPhone and iPad they
    work only once OpenRanch has been added to the Home Screen (iOS 16.4 or
    later); on Android they work in the browser and in the installed app.</p>
  <div class="actions"><a class="btn ghost" href="index.php">Open the dashboard</a></div>
</div>

<div class="card">
  <h2>Session</h2>
  <div class="actions"><a class="btn" href="logout.php">Sign out</a></div>
</div>
<?php
irr_foot();
