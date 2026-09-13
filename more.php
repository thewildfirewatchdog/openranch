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
$tg = $db->prepare('SELECT telegram_chat_id, link_code, link_expires FROM customers WHERE id = ?');
$tg->execute([$cid]);
$tgRow = $tg->fetch(PDO::FETCH_ASSOC) ?: [];
$tgLinked = !empty($tgRow['telegram_chat_id']);
$tgCodeLive = !empty($tgRow['link_code'])
  && (empty($tgRow['link_expires']) || strtotime($tgRow['link_expires']) > time());

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
  <h2>Telegram</h2>
  <?php if ($tgLinked): ?>
    <p style="font-size:13px">This account is linked to a Telegram chat.
      <span class="pill on">linked</span></p>
    <p style="font-size:12px;color:var(--dim)">Ask the bot about the ranch in plain
      English, by text or voice. Say <b>/voice on</b> or <b>/voice off</b> to change
      how it replies.</p>
  <?php elseif ($tgCodeLive): ?>
    <p style="font-size:13px">Your link code is ready and waiting.</p>
    <div class="mono" style="font-size:26px;letter-spacing:.28em;text-align:center;
         padding:12px;background:var(--bg);border:1px solid var(--line);border-radius:10px;margin:8px 0">
      <?= htmlspecialchars($tgRow['link_code']) ?></div>
    <p style="font-size:12px;color:var(--dim)">Send the bot <b>/link
      <?= htmlspecialchars($tgRow['link_code']) ?></b>. It expires
      <?= htmlspecialchars($tgRow['link_expires']) ?> UTC.</p>
  <?php else: ?>
    <p style="font-size:13px;color:var(--dim)">Link a Telegram chat and you can ask
      about the ranch in plain English, get a morning briefing, and see camera
      pictures &mdash; by text or by voice.</p>
  <?php endif; ?>
  <div class="actions">
    <a class="btn" href="assistant.php"><?= $tgLinked ? 'Telegram settings' : 'Get a link code' ?></a>
  </div>
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
    <a class="btn ghost" href="assistant.php">Telegram assistant</a>
    <a class="btn ghost" href="integrations.php">Integrations &amp; export</a>
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
