<?php
// OpenRanch — Integrations: MQTT access, the public status link, and CSV export.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/claim_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

const MQTT_SPOOL = '/var/lib/openranch-mqtt/pending';
$shownPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'mqtt_on' || $act === 'mqtt_regen') {
    $user = 'openranch_' . $cid;
    $pw   = bin2hex(random_bytes(18));
    // The web user cannot write /etc/mosquitto, so leave the request in a spool
    // the root-owned sync job drains once a minute. The password is shown once
    // here and never stored: only mosquitto's own hash is kept.
    @mkdir(MQTT_SPOOL, 0730, true);
    $ok = @file_put_contents(MQTT_SPOOL . "/$cid.json",
            json_encode(['customer_id' => $cid, 'username' => $user, 'password' => $pw]));
    if ($ok === false) irr_back('integrations.php', null,
      'Could not queue the MQTT account. Is the broker installed on this host?');
    @chmod(MQTT_SPOOL . "/$cid.json", 0640);
    $db->prepare('UPDATE customers SET mqtt_username = ?, mqtt_enabled = 1 WHERE id = ?')
       ->execute([$user, $cid]);
    $_SESSION['mqtt_once'] = $pw;      // survives exactly one redirect
    irr_back('integrations.php', 'MQTT account queued. It goes live within a minute.');
  }

  if ($act === 'mqtt_off') {
    $db->prepare('UPDATE customers SET mqtt_enabled = 0 WHERE id = ?')->execute([$cid]);
    irr_back('integrations.php', 'MQTT publishing switched off.');
  }

  if ($act === 'monthly') {
    $db->prepare('UPDATE customers SET monthly_email = ? WHERE id = ?')
       ->execute([!empty($_POST['monthly_email']) ? 1 : 0, $cid]);
    irr_back('integrations.php', 'Saved.');
  }

  if ($act === 'share_on') {
    $db->prepare('UPDATE customers SET share_token = ?, share_enabled = 1 WHERE id = ?')
       ->execute([bin2hex(random_bytes(16)), $cid]);
    irr_back('integrations.php', 'Share link created.');
  }
  if ($act === 'share_regen') {
    $db->prepare('UPDATE customers SET share_token = ? WHERE id = ?')
       ->execute([bin2hex(random_bytes(16)), $cid]);
    irr_back('integrations.php', 'New link generated. The old one stopped working.');
  }
  if ($act === 'share_off') {
    $db->prepare('UPDATE customers SET share_enabled = 0 WHERE id = ?')->execute([$cid]);
    irr_back('integrations.php', 'Share link switched off.');
  }
}

if (!empty($_SESSION['mqtt_once'])) {
  $shownPassword = $_SESSION['mqtt_once'];
  unset($_SESSION['mqtt_once']);
}

$q = $db->prepare('SELECT mqtt_username, mqtt_enabled, share_token, share_enabled, plan,
                          monthly_email FROM customers WHERE id = ?');
$q->execute([$cid]);
$s = $q->fetch(PDO::FETCH_ASSOC);

$devices = irr_device_options($db, $cid, false);
$base    = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$mqttHost = parse_url($base, PHP_URL_HOST) ?: 'your-dashboard-host';

irr_head('Integrations', '');
irr_msg();
?>
<div class="card">
  <h2>MQTT <small>&mdash; readings out, commands in, and Home Assistant discovery</small></h2>
  <?php if ($shownPassword): ?>
    <div class="msg good" style="margin-bottom:10px">
      Your MQTT password, shown once:
      <div class="mono" style="font-size:15px;margin-top:6px;word-break:break-all">
        <?= htmlspecialchars($shownPassword) ?></div>
      Copy it now &mdash; it is not stored anywhere we can read it back.
    </div>
  <?php endif; ?>

  <?php if ($s['mqtt_enabled']): ?>
    <table>
      <tr><td>Host</td><td class="mono"><?= htmlspecialchars($mqttHost) ?></td></tr>
      <tr><td>Port</td><td class="mono">8883 (TLS)</td></tr>
      <tr><td>Username</td><td class="mono"><?= htmlspecialchars($s['mqtt_username']) ?></td></tr>
      <tr><td>Readings</td><td class="mono">openranch/<?= $cid ?>/&lt;device&gt;/&lt;metric&gt;</td></tr>
      <tr><td>Commands</td><td class="mono">openranch/<?= $cid ?>/&lt;device&gt;/set &nbsp;(0&ndash;5)</td></tr>
      <tr><td>Zones</td><td class="mono">openranch/<?= $cid ?>/zone/&lt;id&gt;/set &nbsp;(1 run, 0 stop)</td></tr>
    </table>
    <p style="font-size:11px;color:var(--dim);margin-top:8px">
      Home Assistant picks your devices, zones and relays up automatically from the
      discovery topics &mdash; just point it at this broker with the details above.
      Mirrored devices appear as sensors only; they are controlled on the system
      they come from.</p>
    <div class="actions">
      <form method="post"><input type="hidden" name="act" value="mqtt_regen">
        <button type="submit" onclick="return confirm('Generate a new password? Anything using the old one stops working.')">New password</button></form>
      <form method="post"><input type="hidden" name="act" value="mqtt_off">
        <button class="danger" type="submit">Switch off</button></form>
    </div>
  <?php else: ?>
    <p style="font-size:13px;color:var(--dim)">
      Publish every reading to your own MQTT broker account and send commands back.
      Works with Home Assistant, Node-RED, or anything that speaks MQTT.</p>
    <div class="actions">
      <form method="post"><input type="hidden" name="act" value="mqtt_on">
        <button type="submit">Switch on MQTT</button></form>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Public status link <small>&mdash; read-only, no login</small></h2>
  <?php if ($s['share_enabled'] && $s['share_token']): ?>
    <div class="mono" style="font-size:12px;word-break:break-all;padding:10px;
         background:var(--bg);border:1px solid var(--line);border-radius:8px">
      <?= htmlspecialchars($base . '/s/' . $s['share_token']) ?></div>
    <p style="font-size:11px;color:var(--dim);margin-top:8px">
      Shows tank levels, zones, recent run times and a small history chart.
      No controls, no account details, and nothing that identifies you beyond the
      device names you chose. Anyone with the link can see it, so treat it as
      public.</p>
    <div class="actions">
      <a class="btn ghost" href="<?= htmlspecialchars('/s/' . $s['share_token']) ?>" target="_blank">Open it</a>
      <form method="post"><input type="hidden" name="act" value="share_regen">
        <button type="submit" onclick="return confirm('Generate a new link? The current one stops working.')">New link</button></form>
      <form method="post"><input type="hidden" name="act" value="share_off">
        <button class="danger" type="submit">Switch off</button></form>
    </div>
  <?php else: ?>
    <p style="font-size:13px;color:var(--dim)">
      Share a read-only page with a neighbour, a caretaker or a landlord &mdash;
      levels and watering only, no way to change anything.</p>
    <div class="actions">
      <form method="post"><input type="hidden" name="act" value="share_on">
        <button type="submit">Create a share link</button></form>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Export readings <small>&mdash; CSV, one device at a time</small></h2>
  <?php if (!$devices): ?><div class="empty">No devices yet.</div><?php else: ?>
  <form method="get" action="export.php">
    <div class="row three">
      <div><label>Device</label>
        <select name="device" required>
          <?php foreach ($devices as $d): ?>
            <option value="<?= htmlspecialchars($d['slug']) ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>From</label><input type="date" name="from" value="<?= date('Y-m-d', time() - 7*86400) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="actions"><button type="submit">Download CSV</button></div>
  </form>
  <p style="font-size:11px;color:var(--dim);margin-top:8px">
    Exports only what your plan still keeps
    (<?= $s['plan'] === 'pro' ? (int)PRO_RETENTION_DAYS : (int)FREE_RETENTION_DAYS ?> days).</p>
  <?php endif; ?>

  <form method="post" style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
    <input type="hidden" name="act" value="monthly">
    <div class="actions">
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="monthly_email" value="1" style="width:auto"
               <?= !empty($s['monthly_email']) ? 'checked' : '' ?>>
        email me a water-use summary on the 1st of each month</label>
    </div>
    <div class="actions"><button type="submit">Save</button></div>
  </form>
</div>
<?php irr_foot();
