<?php
// OpenRanch — Graphs. One card per device, every metric it reports drawn as a
// line over the last 7 days. Tap a card for a full-screen view with a range
// picker; ranges past what the plan retains are greyed out, because the data
// genuinely is not there.
//
//   graphs.php                     the page
//   graphs.php?series=1&slug=&days= JSON for one device, all metrics
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/claim_lib.php';
require_once __DIR__ . '/nav.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

// How far back this account's own readings go. Mirrored rows are kept longer by
// a separate prune, but the picker follows the plan the customer pays for.
function graphs_retention_days(PDO $db, $cid) {
  $plan = function_exists('claim_plan') ? claim_plan($db, $cid) : 'free';
  $free = defined('FREE_RETENTION_DAYS') ? (int)FREE_RETENTION_DAYS : 7;
  $pro  = defined('PRO_RETENTION_DAYS')  ? (int)PRO_RETENTION_DAYS  : 90;
  return [$plan, $plan === 'pro' ? $pro : $free, $free, $pro];
}
[$plan, $retain, $freeDays, $proDays] = graphs_retention_days($db, $cid);

// Variables worth drawing. Booleans and counters still chart fine, but a
// heartbeat line tells nobody anything, so it is dropped from the default view.
const GRAPH_SKIP = ['heartbeat', 'fw_version'];

function graph_devices(PDO $db, $cid) {
  $col = function_exists('claim_mirror_col') ? claim_mirror_col($db) : '0 AS is_mirrored';
  $q = $db->prepare("SELECT id, slug, name, variables, enabled, $col
                       FROM devices WHERE customer_id = ? ORDER BY name");
  $q->execute([$cid]);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}

// ---- JSON: every metric on one device over N days -------------------------
if (isset($_GET['series'])) {
  header('Content-Type: application/json');
  $slug = (string)($_GET['slug'] ?? '');
  $days = max(1, min($retain, (int)($_GET['days'] ?? 7)));

  $q = $db->prepare('SELECT id, slug, name, variables FROM devices
                      WHERE slug = ? AND customer_id = ?');
  $q->execute([$slug, $cid]);
  $d = $q->fetch(PDO::FETCH_ASSOC);
  if (!$d) json_out(['error' => 'unknown device'], 404);

  // Target ~400 points per line: enough to see shape on a phone, small enough
  // that a 30-second board over 90 days does not ship a megabyte of JSON.
  $series = [];
  foreach (array_filter(array_map('trim', explode(',', $d['variables']))) as $v) {
    if (in_array($v, GRAPH_SKIP, true)) continue;
    $r = $db->prepare('SELECT value, created FROM readings
                        WHERE device_id = ? AND variable = ?
                          AND created >= (NOW() - INTERVAL ? DAY)
                        ORDER BY created');
    $r->execute([$d['id'], $v, $days]);
    $rows = $r->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    if ($n === 0) continue;
    $step = max(1, (int)ceil($n / 400));
    $pts = [];
    for ($i = 0; $i < $n; $i += $step) {
      $pts[] = ['t' => $rows[$i]['created'], 'v' => (float)$rows[$i]['value']];
    }
    if ($rows[$n - 1] !== ($rows[$i - $step] ?? null)) {
      $pts[] = ['t' => $rows[$n - 1]['created'], 'v' => (float)$rows[$n - 1]['value']];
    }
    $series[] = ['metric' => $v, 'points' => $pts, 'count' => $n];
  }
  json_out(['device' => $d['slug'], 'name' => $d['name'],
            'days' => $days, 'series' => $series]);
}

$devices = graph_devices($db, $cid);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'OpenRanch') ?> &mdash; Graphs</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include __DIR__ . '/pwa_head.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  :root { --bg:#fbf7ee; --card:#fff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --green:#3f6212; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; -webkit-tap-highlight-color:transparent; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh;
         padding:12px 12px calc(78px + env(safe-area-inset-bottom)); }
  header { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px; margin-bottom:12px; }
  h1 { font-size:20px; } h1 span { color:var(--accent-ink); }
  nav.top { display:flex; flex-wrap:wrap; gap:6px; margin-left:auto; }
  nav.top a { font-size:12px; color:var(--accent-ink); text-decoration:none; padding:5px 10px;
              border:1px solid var(--line); border-radius:7px; background:var(--card); }
  nav.top a.on { background:var(--accent); color:#3a2205; font-weight:700; border-color:var(--accent); }
  .wrap { max-width:1100px; margin:0 auto; }
  .note { background:var(--card); border:1px solid var(--line); border-radius:10px;
          padding:10px 12px; font-size:12px; color:var(--dim); margin-bottom:12px;
          display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
  .note b { color:var(--text); }
  .note a { margin-left:auto; background:var(--accent); color:#3a2205; font-weight:700;
            text-decoration:none; padding:6px 12px; border-radius:7px; font-size:12px; }
  .grid { display:grid; gap:12px; grid-template-columns:1fr; }
  @media (min-width:700px) { .grid { grid-template-columns:1fr 1fr; } }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px;
          padding:12px; cursor:pointer; box-shadow:0 3px 10px rgba(43,42,34,.05); }
  .card h2 { font-size:14px; display:flex; align-items:center; gap:8px; margin-bottom:2px; }
  .card .sub { font-size:11px; color:var(--dim); margin-bottom:8px; }
  .card .box { position:relative; height:190px; }
  .pill { font-size:10px; padding:2px 7px; border-radius:20px; background:var(--bg);
          border:1px solid var(--line); color:var(--dim); }
  .empty { color:var(--dim); font-size:13px; padding:10px 0; }

  .sheet { position:fixed; inset:0; z-index:80; background:rgba(20,19,14,.55);
           display:none; align-items:stretch; justify-content:center; padding:10px; }
  .sheet.on { display:flex; }
  .sheet .inner { background:var(--card); border-radius:14px; width:100%; max-width:1000px;
                  display:flex; flex-direction:column; overflow:hidden; }
  .sheet .bar { display:flex; flex-wrap:wrap; align-items:center; gap:8px;
                padding:12px; border-bottom:1px solid var(--line); }
  .sheet .bar h3 { font-size:15px; flex:1 1 160px; }
  .sheet .ranges { display:flex; gap:6px; }
  .sheet .ranges button { padding:6px 12px; border:1px solid var(--line); border-radius:7px;
        background:var(--bg); font-family:inherit; font-size:12px; cursor:pointer; color:var(--text); }
  .sheet .ranges button.on { background:var(--accent); border-color:var(--accent);
        color:#3a2205; font-weight:700; }
  .sheet .ranges button:disabled { opacity:.4; cursor:not-allowed; }
  .sheet .x { border:0; background:none; font-size:22px; line-height:1; cursor:pointer; color:var(--dim); }
  .sheet .body { flex:1; padding:12px; min-height:0; }
  .sheet .box { position:relative; height:min(62vh, 460px); }
  .locked { font-size:11px; color:var(--dim); padding:0 12px 10px; }
</style>
</head>
<body>
<div class="wrap">
<header>
  <h1>Open<span>Ranch</span></h1>
  <nav class="top">
    <a href="index.php?tab=1">Dashboard</a>
    <a href="graphs.php" class="on">Graphs</a>
    <a href="zones.php">Zones</a>
    <a href="programs.php">Programs</a>
    <a href="rules.php">Automations</a>
  </nav>
</header>

<?php if ($plan !== 'pro'): ?>
  <div class="note">
    <b>Free plan keeps <?= (int)$freeDays ?> days</b>
    &mdash; upgrade for <?= (int)$proDays ?>. Older readings are removed nightly.
    <a href="claim.php">Upgrade</a>
  </div>
<?php endif; ?>

<?php if (!$devices): ?>
  <div class="note">No devices yet. Claim one and its readings will chart here.</div>
<?php else: ?>
<div class="grid" id="grid">
  <?php foreach ($devices as $d): ?>
    <div class="card" data-slug="<?= htmlspecialchars($d['slug']) ?>"
         data-name="<?= htmlspecialchars($d['name']) ?>">
      <h2><?= htmlspecialchars($d['name']) ?>
        <?php if (!empty($d['is_mirrored'])): ?><span class="pill">mirrored</span><?php endif; ?>
        <?php if (!$d['enabled']): ?><span class="pill">off</span><?php endif; ?></h2>
      <div class="sub">last 7 days &middot; tap to expand</div>
      <div class="box"><canvas></canvas></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<div class="sheet" id="sheet">
  <div class="inner">
    <div class="bar">
      <h3 id="sheetTitle"></h3>
      <div class="ranges" id="ranges"></div>
      <button class="x" id="sheetClose" aria-label="Close">&times;</button>
    </div>
    <div class="locked" id="locked"></div>
    <div class="body"><div class="box"><canvas id="bigChart"></canvas></div></div>
  </div>
</div>

<script>
const RETAIN = <?= (int)$retain ?>;          // days this plan keeps
const PLAN   = <?= json_encode($plan) ?>;
const RANGES = [1, 7, 30, 90];

// Harvest palette, walked in order so each metric on a card is distinguishable
// and the same metric keeps its colour between the card and the full view.
const COLORS = ['#d98a2b','#5a7d3a','#3f6212','#9a5410','#b3261e','#6b6a5a',
                '#7a9e3f','#c46f1b','#2b6b7a','#8a6d3b'];

Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
Chart.defaults.color = '#6b6a5a';

function opts(compact) {
  return {
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: true, position: 'bottom',
                labels: { boxWidth: 10, boxHeight: 10, padding: compact ? 8 : 14,
                          font: { size: compact ? 10 : 12 } } },
      tooltip: {
        backgroundColor: '#2b2a22', padding: 8, displayColors: true,
        callbacks: {
          // The x scale is linear (epoch ms) so we can avoid a date adapter;
          // that means the tooltip title has to be formatted here, or it reads
          // out the raw timestamp.
          title: items => items.length
            ? new Date(items[0].parsed.x).toLocaleString(undefined,
                { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
            : '',
          label: c => `${c.dataset.label}: ${Number(c.parsed.y).toLocaleString(undefined,
                        { maximumFractionDigits: 2 })}`,
        },
      },
    },
    scales: {
      x: { type: 'time', grid: { color: '#efe7d3' },
           ticks: { maxRotation: 0, autoSkipPadding: 18, font: { size: compact ? 9 : 11 } } },
      y: { grid: { color: '#efe7d3' }, ticks: { font: { size: compact ? 9 : 11 } } },
    },
    elements: { point: { radius: 0, hitRadius: 8 }, line: { borderWidth: compact ? 1.6 : 2, tension: .25 } },
  };
}

// Chart.js needs a time adapter for the time scale. Rather than pull a second
// library, feed it numeric timestamps and format the ticks ourselves.
function toXY(points) { return points.map(p => ({ x: Date.parse(p.t.replace(' ', 'T') + 'Z'), y: p.v })); }

function makeConfig(series, compact) {
  const o = opts(compact);
  o.scales.x = {
    type: 'linear',
    grid: { color: '#efe7d3' },
    ticks: {
      maxRotation: 0, autoSkipPadding: 20, font: { size: compact ? 9 : 11 },
      callback: v => {
        const d = new Date(v);
        return o._span > 2 * 86400000
          ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
          : d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
      },
    },
  };
  return {
    type: 'line',
    data: { datasets: series.map((s, i) => ({
      label: s.metric, data: toXY(s.points),
      borderColor: COLORS[i % COLORS.length],
      backgroundColor: COLORS[i % COLORS.length] + '22',
      fill: false, spanGaps: true })) },
    options: o,
  };
}

async function load(slug, days) {
  const r = await fetch(`?series=1&slug=${encodeURIComponent(slug)}&days=${days}`);
  if (!r.ok) return null;
  return r.json();
}

// ---- the small cards -------------------------------------------------------
const charts = new Map();
document.querySelectorAll('.card').forEach(async card => {
  const slug = card.dataset.slug;
  const data = await load(slug, Math.min(7, RETAIN));
  const cv = card.querySelector('canvas');
  if (!data || !data.series.length) {
    card.querySelector('.box').innerHTML = '<div class="empty">No readings in this window yet.</div>';
    return;
  }
  const cfg = makeConfig(data.series, true);
  cfg.options._span = Math.min(7, RETAIN) * 86400000;
  charts.set(slug, new Chart(cv, cfg));
  card.addEventListener('click', () => openSheet(slug, card.dataset.name));
});

// ---- the full-screen view --------------------------------------------------
const sheet = document.getElementById('sheet');
let big = null, current = { slug: null, days: 7 };

function renderRanges() {
  const box = document.getElementById('ranges');
  box.innerHTML = '';
  RANGES.forEach(d => {
    const b = document.createElement('button');
    b.textContent = d === 1 ? '24h' : d + 'd';
    b.className = d === current.days ? 'on' : '';
    // Beyond the plan's retention there is nothing stored, so the button is
    // disabled rather than drawing an empty chart and looking broken.
    if (d > RETAIN) { b.disabled = true; b.title = `Your plan keeps ${RETAIN} days`; }
    b.onclick = () => { current.days = d; draw(); };
    box.appendChild(b);
  });
  document.getElementById('locked').textContent =
    PLAN === 'pro' ? '' : `Longer ranges need the ${<?= (int)$proDays ?>}-day plan.`;
}

async function draw() {
  renderRanges();
  const data = await load(current.slug, current.days);
  if (big) { big.destroy(); big = null; }
  if (!data || !data.series.length) return;
  const cfg = makeConfig(data.series, false);
  cfg.options._span = current.days * 86400000;
  big = new Chart(document.getElementById('bigChart'), cfg);
}

function openSheet(slug, name) {
  current = { slug, days: Math.min(7, RETAIN) };
  document.getElementById('sheetTitle').textContent = name;
  sheet.classList.add('on');
  draw();
}
function closeSheet() { sheet.classList.remove('on'); if (big) { big.destroy(); big = null; } }
document.getElementById('sheetClose').onclick = closeSheet;
sheet.addEventListener('click', e => { if (e.target === sheet) closeSheet(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSheet(); });
</script>
<?php or_bottom_nav('graphs'); ?>
<div id="installbar"></div>
<script src="/pwa.js" defer></script>
</body>
</html>
