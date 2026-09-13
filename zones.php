<?php
// OpenRanch — zones: an output device, optionally a flow meter and a soil probe.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

$outputs = irr_device_options($db, $cid, true);
$sensors = irr_device_options($db, $cid, false);
$outOpts = []; foreach ($outputs as $d) $outOpts[$d['id']] = $d['name'];
$senOpts = []; foreach ($sensors as $d) $senOpts[$d['id']] = $d['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'save') {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim((string)($_POST['name'] ?? ''));
    $devId  = (int)($_POST['device_id'] ?? 0);
    if ($name === '' || !$devId) irr_back('zones.php', null, 'A zone needs a name and an output device.');
    if (!isset($outOpts[$devId]))  irr_back('zones.php', null, 'That output device is not yours, or is not commandable.');

    $soilAbove = $_POST['soil_skip_above'] === '' ? null : (float)$_POST['soil_skip_above'];
    $master    = !empty($_POST['is_master']) ? 1 : 0;

    // Only one master valve per customer -- it is the thing that gates every
    // other zone, so two of them would be ambiguous.
    if ($master) {
      $q = $db->prepare('UPDATE irr_zones SET is_master = 0 WHERE customer_id = ?' . ($id ? ' AND id <> ?' : ''));
      $q->execute($id ? [$cid, $id] : [$cid]);
    }
    $args = [$name, $devId, (int)$_POST['cmd_on'], (int)$_POST['cmd_off'],
             max(0.5, min(720, (float)($_POST['default_minutes'] ?? 10))),
             ($_POST['flow_device_id'] ?: null), trim($_POST['flow_variable'] ?: 'flow_gpm'),
             trim($_POST['total_variable'] ?: 'total_gal'),
             ($_POST['soil_device_id'] ?: null), trim($_POST['soil_variable'] ?: 'moisture'),
             $soilAbove, $master, (int)($_POST['sort_order'] ?? 0),
             !empty($_POST['enabled']) ? 1 : 0];

    if ($id) {
      $args[] = $id; $args[] = $cid;
      $db->prepare('UPDATE irr_zones SET name=?, device_id=?, cmd_on=?, cmd_off=?, default_minutes=?,
                      flow_device_id=?, flow_variable=?, total_variable=?, soil_device_id=?,
                      soil_variable=?, soil_skip_above=?, is_master=?, sort_order=?, enabled=?
                    WHERE id=? AND customer_id=?')->execute($args);
      irr_back('zones.php', 'Zone saved.');
    }
    array_unshift($args, $cid);
    $db->prepare('INSERT INTO irr_zones (customer_id, name, device_id, cmd_on, cmd_off, default_minutes,
                    flow_device_id, flow_variable, total_variable, soil_device_id, soil_variable,
                    soil_skip_above, is_master, sort_order, enabled)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
    irr_back('zones.php', 'Zone added.');
  }

  if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM irr_zones WHERE id = ? AND customer_id = ?')->execute([$id, $cid]);
    $db->prepare('DELETE FROM irr_program_zones WHERE zone_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM irr_leak_state WHERE zone_id = ?')->execute([$id]);
    irr_back('zones.php', 'Zone removed.');
  }
}

$zones = irr_zones($db, $cid);
$edit  = null;
if (!empty($_GET['edit'])) $edit = irr_zone($db, $cid, (int)$_GET['edit']);

// Water use, last 14 days, all zones together and per zone.
$hist = $db->prepare("SELECT zone_id, DATE(started) d, SUM(gallons) gal, COUNT(*) runs,
                             SUM(TIMESTAMPDIFF(SECOND, started, ended)) secs
                        FROM irr_runs
                       WHERE customer_id = ? AND started >= (NOW() - INTERVAL 14 DAY)
                         AND status IN ('done','stopped')
                    GROUP BY zone_id, DATE(started) ORDER BY d");
$hist->execute([$cid]);
$byZone = [];
foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $r) $byZone[$r['zone_id']][$r['d']] = $r;

$skips = $db->prepare('SELECT s.*, z.name zname FROM irr_skips s
                        LEFT JOIN irr_zones z ON z.id = s.zone_id
                       WHERE s.customer_id = ? ORDER BY s.created DESC LIMIT 25');
$skips->execute([$cid]);
$skips = $skips->fetchAll(PDO::FETCH_ASSOC);

irr_head('Zones', 'zones.php');
irr_msg();
$e = $edit ?: [];
?>
<div class="card">
  <h2><?= $edit ? 'Edit zone' : 'Add a zone' ?>
      <small>&mdash; an output device, plus optional flow and soil sensors</small></h2>
  <?php if (!$outOpts): ?>
    <div class="empty">You have no commandable devices yet. A zone needs one to open and close.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <div class="row two">
      <div><label>Zone name</label>
        <input name="name" required maxlength="80" value="<?= htmlspecialchars($e['name'] ?? '') ?>"></div>
      <div><label>Output device</label>
        <?= irr_select('device_id', $outOpts, $e['device_id'] ?? '', '— choose —', 'required') ?></div>
    </div>
    <div class="row three">
      <div><label>Open code</label><input name="cmd_on" type="number" min="0" max="5" value="<?= (int)($e['cmd_on'] ?? 1) ?>"></div>
      <div><label>Close code</label><input name="cmd_off" type="number" min="0" max="5" value="<?= (int)($e['cmd_off'] ?? 0) ?>"></div>
      <div><label>Tap runs for (min)</label>
        <input name="default_minutes" type="number" step="any" min="0.5"
               value="<?= (float)($e['default_minutes'] ?? 10) ?>"></div>
    </div>
    <div class="row three">
      <div><label>Order</label><input name="sort_order" type="number" value="<?= (int)($e['sort_order'] ?? 0) ?>"></div>
    </div>
    <div class="row three">
      <div><label>Flow meter (optional)</label>
        <?= irr_select('flow_device_id', $senOpts, $e['flow_device_id'] ?? '', '— none —') ?></div>
      <div><label>Flow variable</label><input name="flow_variable" value="<?= htmlspecialchars($e['flow_variable'] ?? 'flow_gpm') ?>"></div>
      <div><label>Total variable</label><input name="total_variable" value="<?= htmlspecialchars($e['total_variable'] ?? 'total_gal') ?>"></div>
    </div>
    <div class="row three">
      <div><label>Soil sensor (optional)</label>
        <?= irr_select('soil_device_id', $senOpts, $e['soil_device_id'] ?? '', '— none —') ?></div>
      <div><label>Soil variable</label><input name="soil_variable" value="<?= htmlspecialchars($e['soil_variable'] ?? 'moisture') ?>"></div>
      <div><label>Skip at or above</label>
        <input name="soil_skip_above" type="number" step="any" placeholder="no limit"
               value="<?= $e['soil_skip_above'] ?? '' ?>"></div>
    </div>
    <div class="actions">
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="enabled" value="1" style="width:auto" <?= !isset($e['enabled']) || $e['enabled'] ? 'checked' : '' ?>> enabled</label>
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="is_master" value="1" style="width:auto" <?= !empty($e['is_master']) ? 'checked' : '' ?>> master valve (opens before, closes after every other zone)</label>
    </div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Save zone' : 'Add zone' ?></button>
      <?php if ($edit): ?><a class="btn ghost" href="zones.php">Cancel</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Your zones</h2>
  <?php if (!$zones): ?><div class="empty">No zones yet.</div><?php else: ?>
  <table>
    <tr><th>Zone</th><th>Device</th><th>Sensors</th><th>Last 14 days</th><th></th></tr>
    <?php foreach ($zones as $z):
      $days = $byZone[$z['id']] ?? [];
      $gal  = array_sum(array_column($days, 'gal'));
      $runs = array_sum(array_column($days, 'runs'));
      $max  = $days ? max(array_map(fn($d) => (float)$d['gal'], $days)) : 0;
    ?>
    <tr>
      <td><b><?= htmlspecialchars($z['name']) ?></b><br>
        <?php if ($z['is_master']): ?><span class="pill on">master valve</span><?php endif; ?>
        <?php if (!$z['enabled']): ?><span class="pill">off</span><?php endif; ?></td>
      <td class="mono" style="font-size:11px"><?= htmlspecialchars($outOpts[$z['device_id']] ?? ('#' . $z['device_id'])) ?><br>
        <span class="pill">on=<?= (int)$z['cmd_on'] ?> off=<?= (int)$z['cmd_off'] ?></span></td>
      <td style="font-size:11px;color:var(--dim)">
        <?= $z['flow_device_id'] ? 'flow: ' . htmlspecialchars($z['flow_variable']) : '—' ?><br>
        <?= $z['soil_device_id'] ? 'soil: ' . htmlspecialchars($z['soil_variable'])
              . ($z['soil_skip_above'] !== null ? ' ≥' . (float)$z['soil_skip_above'] : '') : '—' ?></td>
      <td><?= $runs ?> run<?= $runs === 1 ? '' : 's' ?><?= $gal ? ', ' . round($gal, 1) . ' gal' : '' ?>
        <?php if ($days): ?>
        <div class="chartwrap"><div class="bars">
          <?php foreach (array_slice($days, -14) as $d => $row):
            $h = $max > 0 ? max(2, (int)round((float)$row['gal'] / $max * 78)) : 2; ?>
            <div style="height:<?= $h ?>px" title="<?= $d ?>: <?= round((float)$row['gal'],1) ?> gal">
              <span><?= substr($d, 8, 2) ?></span></div>
          <?php endforeach; ?>
        </div></div>
        <?php endif; ?></td>
      <td style="white-space:nowrap">
        <a class="btn ghost" href="zones.php?edit=<?= (int)$z['id'] ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this zone?')">
          <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
          <button class="danger" type="submit">Remove</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent log <small>&mdash; skips, automations and flow notices</small></h2>
  <?php if (!$skips): ?><div class="empty">Nothing logged yet.</div><?php else: ?>
  <table>
    <tr><th>When</th><th>Zone</th><th>Reason</th><th>Detail</th></tr>
    <?php foreach ($skips as $s): ?>
    <tr><td class="mono" style="font-size:11px"><?= htmlspecialchars($s['created']) ?></td>
        <td><?= htmlspecialchars($s['zname'] ?? '—') ?></td>
        <td><span class="pill"><?= htmlspecialchars($s['reason']) ?></span></td>
        <td style="font-size:12px;color:var(--dim)"><?= htmlspecialchars($s['detail']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php irr_foot();
