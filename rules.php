<?php
// OpenRanch — automations: IF a device reading crosses a limit, THEN do a thing.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
ww_session_start();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

$devices = irr_device_options($db, $cid, false);
$devOpts = []; foreach ($devices as $d) $devOpts[$d['id']] = $d['name'];
$cmdOpts = []; foreach ($devices as $d) if ($d['commandable']) $cmdOpts[$d['id']] = $d['name'];
$zoneOpts = []; foreach (irr_zones($db, $cid, true) as $z) if (!$z['is_master']) $zoneOpts[$z['id']] = $z['name'];

// Variables each device actually reports, so the metric box is a real list.
$vars = [];
foreach ($devices as $d) {
  $q = $db->prepare('SELECT variables FROM devices WHERE id = ?');
  $q->execute([$d['id']]);
  $vars[$d['id']] = array_values(array_filter(array_map('trim', explode(',', (string)$q->fetchColumn()))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'save') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $dev  = (int)($_POST['device_id'] ?? 0);
    $op   = in_array($_POST['op'] ?? '', IRR_OPS, true) ? $_POST['op'] : '>';
    $action = in_array($_POST['action'] ?? '', ['notify','command','zone'], true) ? $_POST['action'] : 'notify';

    if ($name === '' || !isset($devOpts[$dev])) irr_back('rules.php', null, 'Pick a name and one of your devices.');
    if (trim($_POST['metric'] ?? '') === '')    irr_back('rules.php', null, 'Pick a reading to watch.');
    if ($action === 'command' && !isset($cmdOpts[(int)$_POST['action_device_id']]))
      irr_back('rules.php', null, 'That action device is not yours, or cannot take commands.');
    if ($action === 'zone' && !isset($zoneOpts[(int)$_POST['action_zone_id']]))
      irr_back('rules.php', null, 'That zone is not yours.');

    $args = [$name, !empty($_POST['enabled']) ? 1 : 0, $dev, trim($_POST['metric']), $op,
             (float)($_POST['value'] ?? 0), max(0, (int)($_POST['for_minutes'] ?? 0)), $action,
             $action === 'command' ? (int)$_POST['action_device_id'] : null,
             $action === 'command' ? max(0, min(5, (int)$_POST['action_cmd'])) : null,
             $action === 'zone'    ? (int)$_POST['action_zone_id'] : null,
             max(0.5, (float)($_POST['action_minutes'] ?? 10)),
             trim((string)($_POST['message'] ?? '')),
             max(0, (int)($_POST['cooldown_min'] ?? 30))];

    if ($id) {
      $args[] = $id; $args[] = $cid;
      $db->prepare('UPDATE irr_rules SET name=?, enabled=?, device_id=?, metric=?, op=?, value=?,
                      for_minutes=?, action=?, action_device_id=?, action_cmd=?, action_zone_id=?,
                      action_minutes=?, message=?, cooldown_min=?
                    WHERE id=? AND customer_id=?')->execute($args);
      irr_back('rules.php', 'Automation saved.');
    }
    array_unshift($args, $cid);
    $db->prepare('INSERT INTO irr_rules (customer_id, name, enabled, device_id, metric, op, value,
                    for_minutes, action, action_device_id, action_cmd, action_zone_id,
                    action_minutes, message, cooldown_min) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute($args);
    irr_back('rules.php', 'Automation added.');
  }

  if ($act === 'delete') {
    $db->prepare('DELETE FROM irr_rules WHERE id = ? AND customer_id = ?')
       ->execute([(int)$_POST['id'], $cid]);
    irr_back('rules.php', 'Automation removed.');
  }
}

$rs = $db->prepare('SELECT * FROM irr_rules WHERE customer_id = ? ORDER BY name');
$rs->execute([$cid]);
$rules = $rs->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if (!empty($_GET['edit'])) {
  $q = $db->prepare('SELECT * FROM irr_rules WHERE id = ? AND customer_id = ?');
  $q->execute([(int)$_GET['edit'], $cid]);
  $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
$e = $edit ?: [];

$log = $db->prepare("SELECT * FROM irr_skips WHERE customer_id = ? AND reason = 'rule'
                      ORDER BY created DESC LIMIT 15");
$log->execute([$cid]);
$log = $log->fetchAll(PDO::FETCH_ASSOC);

irr_head('Automations', 'rules.php');
irr_msg();
?>
<div class="card">
  <h2><?= $edit ? 'Edit automation' : 'Add an automation' ?>
      <small>&mdash; IF a reading crosses a limit, THEN do something</small></h2>
  <?php if (!$devOpts): ?><div class="empty">You have no devices yet.</div><?php else: ?>
  <form method="post" id="rf">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <div><label>Name</label><input name="name" required maxlength="80" value="<?= htmlspecialchars($e['name'] ?? '') ?>"></div>

    <label style="margin-top:16px">If</label>
    <div class="row three">
      <div><?= irr_select('device_id', $devOpts, $e['device_id'] ?? '', '— device —', 'id="rdev" required') ?></div>
      <div><select name="metric" id="rmetric" required></select></div>
      <div style="display:grid;grid-template-columns:90px 1fr;gap:8px">
        <?= irr_select('op', array_combine(IRR_OPS, IRR_OPS), $e['op'] ?? '>') ?>
        <input name="value" type="number" step="any" required value="<?= $e['value'] ?? 0 ?>">
      </div>
    </div>
    <div class="row two">
      <div><label>Held for (minutes, 0 = act at once)</label>
        <input name="for_minutes" type="number" min="0" value="<?= (int)($e['for_minutes'] ?? 0) ?>"></div>
      <div><label>Wait between firings (minutes)</label>
        <input name="cooldown_min" type="number" min="0" value="<?= (int)($e['cooldown_min'] ?? 30) ?>"></div>
    </div>

    <label style="margin-top:16px">Then</label>
    <div class="row two">
      <div><?= irr_select('action',
             ['notify'=>'Send a notification','command'=>'Command a device','zone'=>'Start a zone'],
             $e['action'] ?? 'notify', null, 'id="ract"') ?></div>
      <div id="abox"></div>
    </div>
    <div><label>Notification text (optional)</label>
      <input name="message" maxlength="255" value="<?= htmlspecialchars($e['message'] ?? '') ?>"
             placeholder="left blank, the reading and limit are described for you"></div>
    <div class="actions">
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="enabled" value="1" style="width:auto" <?= !isset($e['enabled']) || $e['enabled'] ? 'checked' : '' ?>> enabled</label>
    </div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Save automation' : 'Add automation' ?></button>
      <?php if ($edit): ?><a class="btn ghost" href="rules.php">Cancel</a><?php endif; ?>
    </div>
  </form>

  <script>
  // Metric list follows the chosen device; action controls follow the chosen action.
  const VARS = <?= json_encode($vars) ?>;
  const CMDDEV = <?= json_encode($cmdOpts) ?>;
  const ZONES  = <?= json_encode($zoneOpts) ?>;
  const CUR = <?= json_encode(['metric'=>$e['metric'] ?? '', 'adev'=>$e['action_device_id'] ?? '',
                               'acmd'=>$e['action_cmd'] ?? 1, 'azone'=>$e['action_zone_id'] ?? '',
                               'amin'=>$e['action_minutes'] ?? 10]) ?>;
  function fillMetrics() {
    const sel = document.getElementById('rmetric'), dev = document.getElementById('rdev').value;
    const list = VARS[dev] || [];
    sel.innerHTML = list.length
      ? list.map(v => `<option value="${v}"${v === CUR.metric ? ' selected' : ''}>${v}</option>`).join('')
      : '<option value="">(device reports nothing yet)</option>';
  }
  function fillAction() {
    const a = document.getElementById('ract').value, box = document.getElementById('abox');
    if (a === 'command') {
      box.innerHTML = '<label>Device and code</label><div style="display:grid;grid-template-columns:1fr 90px;gap:8px">'
        + '<select name="action_device_id">' + Object.entries(CMDDEV).map(([k,v]) =>
            `<option value="${k}"${k == CUR.adev ? ' selected' : ''}>${v}</option>`).join('')
        + '</select><input name="action_cmd" type="number" min="0" max="5" value="' + CUR.acmd + '"></div>';
    } else if (a === 'zone') {
      box.innerHTML = '<label>Zone and minutes</label><div style="display:grid;grid-template-columns:1fr 90px;gap:8px">'
        + '<select name="action_zone_id">' + Object.entries(ZONES).map(([k,v]) =>
            `<option value="${k}"${k == CUR.azone ? ' selected' : ''}>${v}</option>`).join('')
        + '</select><input name="action_minutes" type="number" step="any" min="0.5" value="' + CUR.amin + '"></div>';
    } else { box.innerHTML = '<label>&nbsp;</label><div style="font-size:12px;color:var(--dim);padding:9px 0">A notification is sent either way.</div>'; }
  }
  document.getElementById('rdev').addEventListener('change', fillMetrics);
  document.getElementById('ract').addEventListener('change', fillAction);
  fillMetrics(); fillAction();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Your automations</h2>
  <?php if (!$rules): ?><div class="empty">Nothing automated yet.</div><?php else: ?>
  <table>
    <tr><th>Name</th><th>If</th><th>Then</th><th>Last fired</th><th></th></tr>
    <?php foreach ($rules as $r):
      $then = $r['action'] === 'command'
        ? 'command ' . ($devOpts[$r['action_device_id']] ?? '#' . $r['action_device_id']) . ' &rarr; ' . (int)$r['action_cmd']
        : ($r['action'] === 'zone'
           ? 'start ' . ($zoneOpts[$r['action_zone_id']] ?? '#' . $r['action_zone_id']) . ' for ' . (float)$r['action_minutes'] . 'm'
           : 'notify'); ?>
    <tr>
      <td><b><?= htmlspecialchars($r['name']) ?></b><br>
        <?= $r['enabled'] ? '<span class="pill on">on</span>' : '<span class="pill">off</span>' ?></td>
      <td class="mono" style="font-size:11px"><?= htmlspecialchars(($devOpts[$r['device_id']] ?? '#'.$r['device_id'])) ?><br>
        <?= htmlspecialchars($r['metric'] . ' ' . $r['op'] . ' ' . (float)$r['value']) ?>
        <?= (int)$r['for_minutes'] ? ' for ' . (int)$r['for_minutes'] . 'm' : '' ?></td>
      <td style="font-size:12px"><?= $then ?></td>
      <td class="mono" style="font-size:11px;color:var(--dim)"><?= htmlspecialchars($r['last_fired'] ?? '—') ?></td>
      <td style="white-space:nowrap">
        <a class="btn ghost" href="rules.php?edit=<?= (int)$r['id'] ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this automation?')">
          <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="danger" type="submit">Remove</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<?php if ($log): ?>
<div class="card">
  <h2>Recently fired</h2>
  <table>
    <?php foreach ($log as $l): ?>
    <tr><td class="mono" style="font-size:11px;width:150px"><?= htmlspecialchars($l['created']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($l['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php irr_foot();
