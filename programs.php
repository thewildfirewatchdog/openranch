<?php
// OpenRanch — programs: when each zone waters, and for how long.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
ww_session_start();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

$zones = array_values(array_filter(irr_zones($db, $cid), fn($z) => !$z['is_master']));
$s     = irr_settings($db, $cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'save') {
    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim((string)($_POST['name'] ?? ''));
    $times = implode(',', irr_parse_times($_POST['start_times'] ?? ''));
    if ($name === '')  irr_back('programs.php', null, 'A program needs a name.');
    if ($times === '') irr_back('programs.php', null, 'Give at least one start time as HH:MM.');

    $dow = implode(',', irr_parse_dow(implode(',', (array)($_POST['dow'] ?? []))));
    $args = [$name, !empty($_POST['enabled']) ? 1 : 0, $times,
             ($_POST['days_mode'] ?? 'dow') === 'interval' ? 'interval' : 'dow',
             $dow !== '' ? $dow : '0,1,2,3,4,5,6',
             max(1, (int)($_POST['interval_days'] ?? 2)),
             ($_POST['interval_anchor'] ?: null),
             max(0, min(300, (int)($_POST['seasonal_pct'] ?? 100))),
             !empty($_POST['sequential']) ? 1 : 0,
             !empty($_POST['weather_adjust']) ? 1 : 0];

    if ($id) {
      $args[] = $id; $args[] = $cid;
      $db->prepare('UPDATE irr_programs SET name=?, enabled=?, start_times=?, days_mode=?,
                      days_of_week=?, interval_days=?, interval_anchor=?, seasonal_pct=?,
                      sequential=?, weather_adjust=? WHERE id=? AND customer_id=?')->execute($args);
      $pid = $id;
    } else {
      array_unshift($args, $cid);
      $db->prepare('INSERT INTO irr_programs (customer_id, name, enabled, start_times, days_mode,
                      days_of_week, interval_days, interval_anchor, seasonal_pct, sequential,
                      weather_adjust) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
      $pid = (int)$db->lastInsertId();
    }

    // Per-zone durations: a blank or zero minute count removes the zone.
    $db->prepare('DELETE FROM irr_program_zones WHERE program_id = ?')->execute([$pid]);
    $ins = $db->prepare('INSERT INTO irr_program_zones (program_id, zone_id, minutes, sort_order)
                         VALUES (?,?,?,?)');
    foreach ((array)($_POST['zmin'] ?? []) as $zid => $min) {
      $min = (float)$min;
      if ($min <= 0) continue;
      $zid = (int)$zid;
      if (!irr_zone($db, $cid, $zid)) continue;      // not this customer's zone
      $ins->execute([$pid, $zid, $min, (int)($_POST['zorder'][$zid] ?? 0)]);
    }
    irr_back('programs.php', $id ? 'Program saved.' : 'Program added.');
  }

  if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM irr_programs WHERE id = ? AND customer_id = ?')->execute([$id, $cid]);
    $db->prepare('DELETE FROM irr_program_zones WHERE program_id = ?')->execute([$id]);
    irr_back('programs.php', 'Program removed.');
  }

  if ($act === 'settings') {
    $db->prepare('UPDATE irr_settings SET zip=?, lat=?, lon=?, weather_enabled=?, rain_skip_mm=?,
                    temp_baseline_c=?, temp_pct_per_c=?, leak_minutes=?, leak_min_gpm=?
                  WHERE customer_id=?')
       ->execute([trim($_POST['zip'] ?? ''),
                  $_POST['lat'] === '' ? null : (float)$_POST['lat'],
                  $_POST['lon'] === '' ? null : (float)$_POST['lon'],
                  !empty($_POST['weather_enabled']) ? 1 : 0,
                  (float)$_POST['rain_skip_mm'], (float)$_POST['temp_baseline_c'],
                  (float)$_POST['temp_pct_per_c'], max(1,(int)$_POST['leak_minutes']),
                  (float)$_POST['leak_min_gpm'], $cid]);
    // Location changed: drop the cached forecast so the next tick refetches.
    $db->prepare('UPDATE irr_settings SET weather_json=NULL, weather_at=NULL WHERE customer_id=?')->execute([$cid]);
    irr_back('programs.php', 'Settings saved.');
  }
}

$ps = $db->prepare('SELECT * FROM irr_programs WHERE customer_id = ? ORDER BY name');
$ps->execute([$cid]);
$programs = $ps->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if (!empty($_GET['edit'])) {
  $q = $db->prepare('SELECT * FROM irr_programs WHERE id = ? AND customer_id = ?');
  $q->execute([(int)$_GET['edit'], $cid]);
  $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editZones = [];
if ($edit) {
  $q = $db->prepare('SELECT * FROM irr_program_zones WHERE program_id = ?');
  $q->execute([$edit['id']]);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $editZones[$r['zone_id']] = $r;
}
$e = $edit ?: [];
$DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$curDow = irr_parse_dow($e['days_of_week'] ?? '0,1,2,3,4,5,6');

irr_head('Programs', 'programs.php');
irr_msg();
?>
<div class="card">
  <h2><?= $edit ? 'Edit program' : 'Add a program' ?></h2>
  <?php if (!$zones): ?>
    <div class="empty">Add a zone first &mdash; a program waters zones.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <div class="row two">
      <div><label>Name</label><input name="name" required maxlength="80" value="<?= htmlspecialchars($e['name'] ?? '') ?>"></div>
      <div><label>Start times <span style="text-transform:none">(HH:MM, comma separated)</span></label>
        <input name="start_times" value="<?= htmlspecialchars($e['start_times'] ?? '06:00') ?>"></div>
    </div>
    <div class="row two">
      <div><label>Repeat</label>
        <?= irr_select('days_mode', ['dow'=>'On chosen days','interval'=>'Every N days'], $e['days_mode'] ?? 'dow') ?>
        <div class="actions" style="margin-top:8px">
          <?php foreach ($DOW as $i => $d): ?>
            <label class="pill" style="text-transform:none;letter-spacing:0;margin:0;cursor:pointer">
              <input type="checkbox" name="dow[]" value="<?= $i ?>" style="width:auto"
                     <?= in_array($i, $curDow, true) ? 'checked' : '' ?>> <?= $d ?></label>
          <?php endforeach; ?>
        </div></div>
      <div><label>Every N days</label>
        <input name="interval_days" type="number" min="1" value="<?= (int)($e['interval_days'] ?? 2) ?>">
        <label>Starting from</label>
        <input name="interval_anchor" type="date" value="<?= htmlspecialchars($e['interval_anchor'] ?? date('Y-m-d')) ?>"></div>
    </div>
    <div class="row two">
      <div><label>Seasonal adjustment %</label>
        <input name="seasonal_pct" type="number" min="0" max="300" value="<?= (int)($e['seasonal_pct'] ?? 100) ?>"></div>
      <div class="actions" style="align-items:center">
        <label style="text-transform:none;letter-spacing:0;margin:0">
          <input type="checkbox" name="enabled" value="1" style="width:auto" <?= !isset($e['enabled']) || $e['enabled'] ? 'checked' : '' ?>> enabled</label>
        <label style="text-transform:none;letter-spacing:0;margin:0">
          <input type="checkbox" name="sequential" value="1" style="width:auto" <?= !isset($e['sequential']) || $e['sequential'] ? 'checked' : '' ?>> one zone at a time</label>
        <label style="text-transform:none;letter-spacing:0;margin:0">
          <input type="checkbox" name="weather_adjust" value="1" style="width:auto" <?= !isset($e['weather_adjust']) || $e['weather_adjust'] ? 'checked' : '' ?>> weather adjust</label>
      </div>
    </div>
    <label>Zone durations <span style="text-transform:none">(minutes; leave blank to leave a zone out)</span></label>
    <table>
      <tr><th>Zone</th><th style="width:110px">Minutes</th><th style="width:90px">Order</th></tr>
      <?php foreach ($zones as $z): $pz = $editZones[$z['id']] ?? null; ?>
      <tr><td><?= htmlspecialchars($z['name']) ?></td>
          <td><input type="number" step="any" min="0" name="zmin[<?= (int)$z['id'] ?>]"
                     value="<?= $pz ? (float)$pz['minutes'] : '' ?>"></td>
          <td><input type="number" name="zorder[<?= (int)$z['id'] ?>]"
                     value="<?= $pz ? (int)$pz['sort_order'] : (int)$z['sort_order'] ?>"></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Save program' : 'Add program' ?></button>
      <?php if ($edit): ?><a class="btn ghost" href="programs.php">Cancel</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Your programs</h2>
  <?php if (!$programs): ?><div class="empty">No programs yet.</div><?php else: ?>
  <table>
    <tr><th>Program</th><th>When</th><th>Zones</th><th></th></tr>
    <?php foreach ($programs as $p):
      $q = $db->prepare('SELECT pz.minutes, z.name FROM irr_program_zones pz
                           JOIN irr_zones z ON z.id = pz.zone_id
                          WHERE pz.program_id = ? ORDER BY pz.sort_order, pz.id');
      $q->execute([$p['id']]); $pzs = $q->fetchAll(PDO::FETCH_ASSOC);
      $when = $p['days_mode'] === 'interval'
        ? 'every ' . (int)$p['interval_days'] . ' days'
        : implode(' ', array_map(fn($i) => $DOW[$i], irr_parse_dow($p['days_of_week'])));
    ?>
    <tr>
      <td><b><?= htmlspecialchars($p['name']) ?></b><br>
        <?= $p['enabled'] ? '<span class="pill on">on</span>' : '<span class="pill">off</span>' ?>
        <span class="pill"><?= $p['sequential'] ? 'sequential' : 'parallel' ?></span>
        <?php if ((int)$p['seasonal_pct'] !== 100): ?><span class="pill"><?= (int)$p['seasonal_pct'] ?>%</span><?php endif; ?>
        <?php if ($p['weather_adjust']): ?><span class="pill">weather</span><?php endif; ?></td>
      <td class="mono" style="font-size:11px"><?= htmlspecialchars($p['start_times']) ?><br>
        <span style="color:var(--dim)"><?= htmlspecialchars($when) ?></span></td>
      <td style="font-size:12px">
        <?php foreach ($pzs as $z): ?><?= htmlspecialchars($z['name']) ?> &middot; <?= (float)$z['minutes'] ?>m<br><?php endforeach; ?>
        <?php if (!$pzs): ?><span style="color:var(--dim)">no zones</span><?php endif; ?></td>
      <td style="white-space:nowrap">
        <a class="btn ghost" href="programs.php?edit=<?= (int)$p['id'] ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this program?')">
          <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="danger" type="submit">Remove</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Weather and flow settings</h2>
  <form method="post">
    <input type="hidden" name="act" value="settings">
    <div class="row three">
      <div><label>Zip / place</label><input name="zip" value="<?= htmlspecialchars($s['zip']) ?>" placeholder="93644"></div>
      <div><label>Latitude</label><input name="lat" type="number" step="any" value="<?= $s['lat'] ?? '' ?>"></div>
      <div><label>Longitude</label><input name="lon" type="number" step="any" value="<?= $s['lon'] ?? '' ?>"></div>
    </div>
    <div class="row three">
      <div><label>Skip above (mm rain)</label><input name="rain_skip_mm" type="number" step="any" value="<?= (float)$s['rain_skip_mm'] ?>"></div>
      <div><label>Baseline temp (C)</label><input name="temp_baseline_c" type="number" step="any" value="<?= (float)$s['temp_baseline_c'] ?>"></div>
      <div><label>% per degree</label><input name="temp_pct_per_c" type="number" step="any" value="<?= (float)$s['temp_pct_per_c'] ?>"></div>
    </div>
    <div class="row three">
      <div><label>Unscheduled flow after (min)</label><input name="leak_minutes" type="number" min="1" value="<?= (int)$s['leak_minutes'] ?>"></div>
      <div><label>Ignore flow below (gpm)</label><input name="leak_min_gpm" type="number" step="any" value="<?= (float)$s['leak_min_gpm'] ?>"></div>
      <div style="display:flex;align-items:flex-end">
        <label style="text-transform:none;letter-spacing:0;margin:0">
          <input type="checkbox" name="weather_enabled" value="1" style="width:auto" <?= $s['weather_enabled'] ? 'checked' : '' ?>> use weather</label></div>
    </div>
    <div class="actions"><button type="submit">Save settings</button></div>
    <?php if ($s['weather_at']): $w = irr_weather_extract(json_decode($s['weather_json'], true)); ?>
      <div style="font-size:11px;color:var(--dim);margin-top:10px">
        Forecast cached <?= htmlspecialchars($s['weather_at']) ?> UTC<?php if ($w): ?>
        &middot; <?= round($w['past_mm'],1) ?> mm yesterday, <?= round($w['today_mm'],1) ?> mm today,
        max <?= $w['today_tmax_c'] === null ? '—' : round($w['today_tmax_c'],1) . 'C' ?><?php endif; ?>
      </div>
    <?php endif; ?>
  </form>
</div>
<?php irr_foot();
