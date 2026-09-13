<?php
// OpenRanch — public read-only status page.
//
//   /s/<token>   (rewritten to status.php?t=<token> by nginx)
//
// Everything here is read-only by construction: no session is started, no
// customer is resolved from a cookie, and nothing on the page posts anywhere.
// The token is the whole of the authorisation, so it is long, random, and
// revocable from the Integrations page.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$db = db();
$c = null;
if (strlen($token) === 32) {
  $q = $db->prepare('SELECT id, name, email FROM customers
                      WHERE share_token = ? AND share_enabled = 1');
  $q->execute([$token]);
  $c = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$c) {
  http_response_code(404);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><meta charset="utf-8"><title>Not found</title>'
     . '<body style="font-family:system-ui;padding:40px;background:#fbf7ee;color:#2b2a22">'
     . '<h1 style="font-size:20px">This link is not active.</h1>'
     . '<p style="color:#6b6a5a;font-size:14px">It may have been switched off or replaced.</p>';
  exit;
}
$cid = (int)$c['id'];

// Chart series for one metric, if the page is asked for JSON.
if (isset($_GET['series'])) {
  header('Content-Type: application/json');
  $did = (int)($_GET['device'] ?? 0);
  $var = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['metric'] ?? ''));
  $own = $db->prepare('SELECT id FROM devices WHERE id = ? AND customer_id = ?');
  $own->execute([$did, $cid]);
  if (!$own->fetchColumn() || $var === '') json_out(['error' => 'not found'], 404);
  $r = $db->prepare('SELECT value, created FROM readings
                      WHERE device_id = ? AND variable = ? AND created >= (NOW() - INTERVAL 7 DAY)
                      ORDER BY created');
  $r->execute([$did, $var]);
  $rows = $r->fetchAll(PDO::FETCH_ASSOC);
  $n = count($rows); $step = max(1, (int)ceil($n / 300));
  $pts = [];
  for ($i = 0; $i < $n; $i += $step) $pts[] = ['t' => $rows[$i]['created'], 'v' => (float)$rows[$i]['value']];
  json_out(['points' => $pts]);
}

// Levels worth showing on a status board, newest value per metric.
$levels = [];
$dq = $db->prepare('SELECT id, slug, name, variables FROM devices WHERE customer_id = ? ORDER BY name');
$dq->execute([$cid]);
$devices = $dq->fetchAll(PDO::FETCH_ASSOC);
const SHOW = ['tank_level_pct', 'pressure_psi', 'moisture', 'flow_gpm', 'total_gal', 'battery_v'];
foreach ($devices as $d) {
  foreach (array_filter(array_map('trim', explode(',', $d['variables']))) as $v) {
    if (!in_array($v, SHOW, true)) continue;
    $r = $db->prepare('SELECT value, created FROM readings WHERE device_id = ? AND variable = ?
                        ORDER BY created DESC LIMIT 1');
    $r->execute([$d['id'], $v]);
    if ($row = $r->fetch(PDO::FETCH_ASSOC)) {
      $levels[] = ['device' => $d['name'], 'device_id' => (int)$d['id'], 'metric' => $v,
                   'value' => (float)$row['value'], 'at' => $row['created']];
    }
  }
}

$zones = irr_zones($db, $cid);
$running = [];
foreach (irr_running($db, $cid) as $r) $running[$r['zone_id']] = $r;
$lastRun = [];
$lr = $db->prepare("SELECT zone_id, MAX(ended) last_end FROM irr_runs
                     WHERE customer_id = ? AND status IN ('done','stopped') GROUP BY zone_id");
$lr->execute([$cid]);
foreach ($lr->fetchAll(PDO::FETCH_ASSOC) as $r) $lastRun[$r['zone_id']] = $r['last_end'];

$UNITS = ['tank_level_pct' => '%', 'pressure_psi' => ' psi', 'moisture' => '',
          'flow_gpm' => ' gpm', 'total_gal' => ' gal', 'battery_v' => ' V'];
$chartOf = null;
foreach ($levels as $l) { if ($l['metric'] === 'tank_level_pct') { $chartOf = $l; break; } }
if (!$chartOf && $levels) $chartOf = $levels[0];

function ago($ts) {
  if (!$ts) return 'never';
  $s = time() - strtotime($ts . ' UTC');
  if ($s < 90) return 'just now';
  if ($s < 5400) return round($s / 60) . ' min ago';
  if ($s < 172800) return round($s / 3600) . ' h ago';
  return round($s / 86400) . ' days ago';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($c['name'] !== '' ? $c['name'] : 'Ranch') ?> &mdash; status</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  :root { --bg:#fbf7ee; --card:#fff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --green:#3f6212; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh; padding:16px; }
  .wrap { max-width:860px; margin:0 auto; }
  h1 { font-size:21px; margin-bottom:2px; } h1 span { color:var(--accent-ink); }
  .sub { font-size:12px; color:var(--dim); margin-bottom:14px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px;
          padding:14px; margin-bottom:12px; box-shadow:0 3px 10px rgba(43,42,34,.05); }
  .card h2 { font-size:13px; text-transform:uppercase; letter-spacing:.06em;
             color:var(--dim); margin-bottom:10px; }
  .levels { display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); }
  .lvl { background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:11px; }
  .lvl .n { font-size:11px; color:var(--dim); }
  .lvl .v { font-family:'JetBrains Mono',monospace; font-size:23px; font-weight:600; margin:2px 0; }
  .lvl .t { font-size:10px; color:var(--dim); }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  td { padding:7px 6px 7px 0; border-bottom:1px solid var(--line); }
  .pill { font-size:10px; padding:2px 8px; border-radius:20px; background:var(--bg);
          border:1px solid var(--line); color:var(--dim); }
  .pill.run { background:var(--accent); color:#3a2205; border-color:var(--accent); font-weight:700; }
  .box { position:relative; height:200px; }
  .foot { text-align:center; font-size:11px; color:var(--dim); margin-top:16px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Open<span>Ranch</span></h1>
  <div class="sub"><?= htmlspecialchars($c['name'] !== '' ? $c['name'] : 'Ranch') ?>
    &middot; read-only status &middot; updated <?= gmdate('H:i') ?> UTC</div>

  <?php if ($levels): ?>
  <div class="card">
    <h2>Levels</h2>
    <div class="levels">
      <?php foreach ($levels as $l): ?>
        <div class="lvl">
          <div class="n"><?= htmlspecialchars($l['device']) ?> &middot; <?= htmlspecialchars($l['metric']) ?></div>
          <div class="v"><?= rtrim(rtrim(number_format($l['value'], 2, '.', ''), '0'), '.') ?><?= $UNITS[$l['metric']] ?? '' ?></div>
          <div class="t"><?= ago($l['at']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($zones): ?>
  <div class="card">
    <h2>Zones</h2>
    <table>
      <?php foreach ($zones as $z): $r = $running[$z['id']] ?? null; ?>
      <tr>
        <td><b><?= htmlspecialchars($z['name']) ?></b>
            <?php if ($z['is_master']): ?><span class="pill">master</span><?php endif; ?></td>
        <td style="text-align:right">
          <?php if ($r): ?><span class="pill run">running</span>
          <?php else: ?><span style="color:var(--dim)">last watered <?= ago($lastRun[$z['id']] ?? null) ?></span>
          <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($chartOf): ?>
  <div class="card">
    <h2><?= htmlspecialchars($chartOf['device'] . ' · ' . $chartOf['metric']) ?> &mdash; last 7 days</h2>
    <div class="box"><canvas id="c"></canvas></div>
  </div>
  <?php endif; ?>

  <?php if (!$levels && !$zones): ?>
    <div class="card"><div style="font-size:13px;color:var(--dim)">Nothing reporting yet.</div></div>
  <?php endif; ?>

  <div class="foot">Powered by OpenRanch &middot; this page is read-only</div>
</div>
<?php if ($chartOf): ?>
<script>
Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
Chart.defaults.color = '#6b6a5a';
fetch('?t=<?= htmlspecialchars($token) ?>&series=1&device=<?= (int)$chartOf['device_id'] ?>&metric=<?= urlencode($chartOf['metric']) ?>')
  .then(r => r.json()).then(j => {
    if (!j.points || !j.points.length) return;
    new Chart(document.getElementById('c'), {
      type: 'line',
      data: { datasets: [{
        label: <?= json_encode($chartOf['metric']) ?>,
        data: j.points.map(p => ({ x: Date.parse(p.t.replace(' ', 'T') + 'Z'), y: p.v })),
        borderColor: '#d98a2b', backgroundColor: '#d98a2b22', fill: true, tension: .25 }] },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
          tooltip: { backgroundColor: '#2b2a22',
            callbacks: { title: i => i.length ? new Date(i[0].parsed.x)
              .toLocaleString(undefined, { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' }) : '' } } },
        elements: { point: { radius: 0, hitRadius: 8 } },
        scales: { x: { type:'linear', grid:{color:'#efe7d3'},
                       ticks:{ maxRotation:0, autoSkipPadding:20,
                         callback: v => new Date(v).toLocaleDateString(undefined,{month:'short',day:'numeric'}) } },
                  y: { grid:{color:'#efe7d3'} } } },
    });
  });
</script>
<?php endif; ?>
</body>
</html>
