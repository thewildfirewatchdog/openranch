<?php
// OpenRanch — Controls. The phone-first screen: one big tile per zone and per
// commandable device, sized for a thumb rather than a mouse.
//
//   tap        run the zone for its default duration (or toggle a bare device)
//   long-press ON/OFF toggle, for a zone you want left open
//   Run All / Stop All across every zone
//
// Everything goes through irrigation_run.php and cmd.php, which write the same
// `commands` rows the dashboard has always written. No firmware change.

require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/camera_lib.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

$zones   = irr_zones($db, $cid);
$running = [];
foreach (irr_running($db, $cid) as $r) $running[$r['zone_id']] = $r;

// Commandable devices that are not already the business end of a zone: a valve
// that belongs to a zone is controlled by the zone tile, not twice.
$zoneDevs = array_column($zones, 'device_id');
$dq = $db->prepare('SELECT id, name, slug, commandable, enabled FROM devices
                     WHERE customer_id = ? AND commandable = 1 AND enabled = 1
                       AND (is_mirrored = 0 OR is_mirrored IS NULL) ORDER BY name');
try { $dq->execute([$cid]); $devices = $dq->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) {   // a stock install has no is_mirrored column
  $dq = $db->prepare('SELECT id, name, slug, commandable, enabled FROM devices
                       WHERE customer_id = ? AND commandable = 1 AND enabled = 1 ORDER BY name');
  $dq->execute([$cid]); $devices = $dq->fetchAll(PDO::FETCH_ASSOC);
}
$cameras = cam_devices($db, $cid);
$camIds  = array_column($cameras, 'id');
$loose = array_values(array_filter($devices,
  fn($d) => !in_array($d['id'], $zoneDevs) && !in_array($d['id'], $camIds)));

// Latest command per device, so a tile can show what the board was last told.
$lastCmd = [];
foreach (array_merge($zoneDevs, array_column($loose, 'id')) as $did) {
  $q = $db->prepare('SELECT cmd FROM commands WHERE device_id = ? ORDER BY id DESC LIMIT 1');
  $q->execute([$did]);
  $v = $q->fetchColumn();
  $lastCmd[$did] = $v === false ? null : (int)$v;
}

// Next scheduled run per zone, for the line under each tile.
$loc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(irr_tz());
$nextByZone = [];
$pq = $db->prepare('SELECT * FROM irr_programs WHERE customer_id = ? AND enabled = 1');
$pq->execute([$cid]);
foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
  $n = irr_next_occurrence($p, $loc);
  if (!$n) continue;
  $zq = $db->prepare('SELECT zone_id FROM irr_program_zones WHERE program_id = ?');
  $zq->execute([$p['id']]);
  foreach ($zq->fetchAll(PDO::FETCH_COLUMN) as $zid) {
    if (!isset($nextByZone[$zid]) || $n < $nextByZone[$zid]['at']) {
      $nextByZone[$zid] = ['at' => $n, 'name' => $p['name']];
    }
  }
}

$s = irr_settings($db, $cid);
$held = !empty($s['rain_delay_until']) && (new DateTimeImmutable($s['rain_delay_until'])) > new DateTimeImmutable('now');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'OpenRanch') ?> &mdash; Controls</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include __DIR__ . '/pwa_head.php'; ?>
<style>
  :root { --bg:#fbf7ee; --card:#fff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --green:#3f6212; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; -webkit-tap-highlight-color:transparent; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh;
         padding:12px 12px calc(78px + env(safe-area-inset-bottom)); }
  .top { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  .top h1 { font-size:19px; } .top h1 span { color:var(--accent-ink); }
  .top .who { margin-left:auto; font-size:11px; color:var(--dim); }
  .msg { padding:9px 12px; border-radius:9px; font-size:13px; margin-bottom:10px; }
  .msg.good { background:rgba(63,98,18,.12); border:1px solid rgba(63,98,18,.45); color:var(--green); }
  .msg.bad  { background:rgba(179,38,30,.10); border:1px solid rgba(179,38,30,.45); color:var(--red); }
  .msg.held { background:rgba(179,38,30,.08); border:1px solid rgba(179,38,30,.3); color:var(--red); }

  .bulk { display:flex; gap:8px; margin-bottom:12px; }
  .bulk form { flex:1; display:flex; }
  .bulk button { flex:1; padding:13px; border:0; border-radius:11px; font-family:inherit;
                 font-weight:700; font-size:14px; cursor:pointer; }
  .bulk .run  { background:var(--accent); color:#3a2205; }
  .bulk .stop { background:var(--card); color:var(--red); border:1px solid rgba(179,38,30,.4); }

  .grid { display:grid; gap:10px; grid-template-columns:repeat(2, 1fr); }
  @media (min-width:560px) { .grid { grid-template-columns:repeat(3, 1fr); } }
  @media (min-width:820px) { .grid { grid-template-columns:repeat(4, 1fr); } }

  .tile { position:relative; background:var(--card); border:1px solid var(--line);
          border-radius:14px; padding:14px 12px; min-height:118px; display:flex;
          flex-direction:column; gap:4px; cursor:pointer; user-select:none;
          box-shadow:0 3px 10px rgba(43,42,34,.05); transition:transform .06s, box-shadow .12s; }
  .tile:active { transform:scale(.975); }
  .tile.on { background:var(--accent); border-color:var(--accent); color:#3a2205;
             box-shadow:0 6px 18px rgba(217,138,43,.35); }
  .tile.master { border-style:dashed; }
  .tile .nm { font-weight:700; font-size:15px; line-height:1.2; }
  .tile .sub { font-size:11px; color:var(--dim); }
  .tile.on .sub { color:#6b4410; }
  .tile .cd { margin-top:auto; font-family:'JetBrains Mono',monospace; font-size:22px; font-weight:600; }
  .tile .state { margin-top:auto; font-size:12px; color:var(--dim); }
  .tile.on .state { color:#6b4410; font-weight:700; }
  .tile .camthumb { width:100%; border-radius:8px; margin:6px 0 2px; display:block; }
  .tile.cam { min-height:0; }
  .tile .tag { position:absolute; top:9px; right:9px; font-size:9px; letter-spacing:.06em;
               text-transform:uppercase; color:var(--dim); }
  .tile.on .tag { color:#6b4410; }
  .tile.busy { opacity:.55; pointer-events:none; }
  .hint { font-size:11px; color:var(--dim); margin:14px 0 0; text-align:center; }
  h2.sec { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--dim);
           margin:18px 0 8px; }
</style>
</head>
<body>
<div class="top">
  <h1>Open<span>Ranch</span></h1>
  <div class="who"><?= htmlspecialchars($customer['name'] !== '' ? $customer['name'] : $customer['email']) ?></div>
</div>

<?php if (!empty($_GET['ok'])):  ?><div class="msg good"><?= htmlspecialchars($_GET['ok'])  ?></div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="msg bad"><?=  htmlspecialchars($_GET['err']) ?></div><?php endif; ?>
<?php if ($held): ?><div class="msg held">Programs are held until <?= htmlspecialchars($s['rain_delay_until']) ?> UTC. Manual runs still work.</div><?php endif; ?>

<?php if ($zones): ?>
<div class="bulk">
  <form method="post" action="irrigation_run.php">
    <input type="hidden" name="act" value="runall"><input type="hidden" name="back" value="controls.php">
    <button class="run" type="submit">Run All</button></form>
  <form method="post" action="irrigation_run.php">
    <input type="hidden" name="act" value="stopall"><input type="hidden" name="back" value="controls.php">
    <button class="stop" type="submit">Stop All</button></form>
</div>
<?php endif; ?>

<?php if (!$zones && !$loose): ?>
  <div class="msg">Nothing to control yet. Add a zone on the Zones page, or claim a device that takes commands.</div>
<?php endif; ?>

<?php if ($zones): ?>
<h2 class="sec">Zones</h2>
<div class="grid">
  <?php foreach ($zones as $z): $run = $running[$z['id']] ?? null; $next = $nextByZone[$z['id']] ?? null; ?>
  <div class="tile<?= $run ? ' on' : '' ?><?= $z['is_master'] ? ' master' : '' ?>"
       data-kind="zone" data-id="<?= (int)$z['id'] ?>"
       data-minutes="<?= (float)($z['default_minutes'] ?? 10) ?>"
       data-running="<?= $run ? 1 : 0 ?>"
       data-ends="<?= $run ? htmlspecialchars($run['ends_at']) : '' ?>"
       <?= $z['enabled'] ? '' : 'style="opacity:.5;pointer-events:none"' ?>>
    <span class="tag"><?= $z['is_master'] ? 'master' : 'zone' ?></span>
    <div class="nm"><?= htmlspecialchars($z['name']) ?></div>
    <?php if ($run): ?>
      <div class="cd" data-cd>--:--</div>
    <?php else: ?>
      <div class="state">Tap for <?= (float)($z['default_minutes'] ?? 10) ?> min</div>
    <?php endif; ?>
    <div class="sub"><?php
      if ($run)        echo 'running';
      elseif (!$z['enabled']) echo 'switched off';
      elseif ($next)   echo 'next ' . $next['at']->format('D H:i');
      else             echo 'no program';
    ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($loose): ?>
<h2 class="sec">Devices</h2>
<div class="grid">
  <?php foreach ($loose as $d): $on = ($lastCmd[$d['id']] ?? null) === 1; ?>
  <div class="tile<?= $on ? ' on' : '' ?>" data-kind="device"
       data-slug="<?= htmlspecialchars($d['slug']) ?>" data-on="<?= $on ? 1 : 0 ?>">
    <span class="tag">device</span>
    <div class="nm"><?= htmlspecialchars($d['name']) ?></div>
    <div class="state"><?= $on ? 'ON' : 'OFF' ?></div>
    <div class="sub">Tap to turn <?= $on ? 'off' : 'on' ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cameras): ?>
<h2 class="sec">Cameras</h2>
<div class="grid">
  <?php foreach ($cameras as $cam): $latest = cam_latest($db, $cam['id']); ?>
  <div class="tile cam" data-kind="camera" data-slug="<?= htmlspecialchars($cam['slug']) ?>"
       <?= (!$cam['enabled'] || !empty($cam['is_mirrored'])) ? 'data-readonly="1"' : '' ?>>
    <span class="tag"><?= !empty($cam['is_mirrored']) ? 'mirrored' : 'camera' ?></span>
    <div class="nm"><?= htmlspecialchars($cam['name']) ?></div>
    <?php if ($latest): ?>
      <img class="camthumb" loading="lazy"
           src="snapshot.php?device=<?= urlencode($cam['slug']) ?>&file=<?= urlencode($latest['filename']) ?>&thumb=1"
           alt="latest picture from <?= htmlspecialchars($cam['name']) ?>">
      <div class="sub"><?= htmlspecialchars(substr($latest['taken'], 11, 5)) ?> UTC</div>
    <?php else: ?>
      <div class="state">No picture yet</div>
      <div class="sub">waiting for the camera</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="hint">Tap to run &middot; press and hold a zone to leave it on or off
  <?= $cameras ? '&middot; tap a camera to ask for a fresh picture' : '' ?></p>

<form id="act" method="post" action="irrigation_run.php" hidden>
  <input type="hidden" name="act" id="a_act"><input type="hidden" name="zone_id" id="a_zone">
  <input type="hidden" name="minutes" id="a_min"><input type="hidden" name="back" value="controls.php">
</form>

<script>
// Countdown on any running tile. Ends are UTC from MySQL; append Z so the phone
// parses them as UTC rather than local, which would be hours out.
function tick() {
  document.querySelectorAll('.tile[data-running="1"]').forEach(t => {
    const el = t.querySelector('[data-cd]'); if (!el) return;
    const end = new Date(t.dataset.ends.replace(' ', 'T') + 'Z');
    let s = Math.max(0, Math.round((end - Date.now()) / 1000));
    const m = Math.floor(s / 60); s = s % 60;
    el.textContent = m + ':' + String(s).padStart(2, '0');
    if (m === 0 && s === 0) setTimeout(() => location.reload(), 2000);
  });
}
tick(); setInterval(tick, 1000);

// Tap vs long-press. 500ms is long enough not to fire on a scroll-start and
// short enough that nobody wonders whether it registered.
const LONG = 500;
let timer = null, longFired = false;

function post(act, zoneId, minutes) {
  document.getElementById('a_act').value  = act;
  document.getElementById('a_zone').value = zoneId || '';
  document.getElementById('a_min').value  = minutes || '';
  document.getElementById('act').submit();
}
function cmd(slug, code) {
  const f = document.createElement('form');
  f.method = 'post'; f.action = 'cmd.php';
  f.innerHTML = '<input name="slug" value="' + slug + '"><input name="cmd" value="' + code + '">'
              + '<input name="back" value="controls.php">';
  document.body.appendChild(f);
  // cmd.php answers JSON, so send it in the background and repaint ourselves.
  fetch('cmd.php', { method: 'POST', body: new URLSearchParams({ slug: slug, cmd: code }) })
    .then(() => location.reload());
}

document.querySelectorAll('.tile').forEach(t => {
  const start = () => {
    longFired = false;
    timer = setTimeout(() => {
      longFired = true;
      if (navigator.vibrate) navigator.vibrate(18);
      if (t.dataset.kind === 'zone') {
        // Long-press: leave it on, or shut it, without a duration.
        t.dataset.running === '1' ? post('stop', t.dataset.id)
                                  : post('hold', t.dataset.id);
      }
    }, LONG);
  };
  const cancel = () => { clearTimeout(timer); };
  const fire = e => {
    clearTimeout(timer);
    if (longFired) { e.preventDefault(); return; }
    t.classList.add('busy');
    if (t.dataset.kind === 'zone') {
      t.dataset.running === '1' ? post('stop', t.dataset.id)
                                : post('run', t.dataset.id, t.dataset.minutes);
    } else if (t.dataset.kind === 'camera') {
      // A mirrored or switched-off camera is shown but cannot be asked.
      if (t.dataset.readonly) { t.classList.remove('busy'); return; }
      cmd(t.dataset.slug, 6);
    } else {
      cmd(t.dataset.slug, t.dataset.on === '1' ? 0 : 1);
    }
  };
  t.addEventListener('pointerdown', start);
  t.addEventListener('pointerup', fire);
  t.addEventListener('pointerleave', cancel);
  t.addEventListener('pointercancel', cancel);
  t.addEventListener('contextmenu', e => e.preventDefault());
});
</script>
<?php or_bottom_nav('controls'); ?>
<div id="installbar"></div>
<script src="/pwa.js" defer></script>
</body>
</html>
