<?php
// OpenRanch Dashboard v1 — main dashboard
// Every device appears as a card:
//   TEMPLATE (gray)      = shell, not programmed yet, shows its variable slots
//   WAITING (yellow dot) = enabled but no data yet
//   LIVE (green dot)     = reporting; shows latest value per variable
//   STALE (red dot)      = enabled but silent past 3x expected interval
// Commandable + enabled devices get ON/OFF buttons (PIN required, remembered in-page).
// Auto-refreshes data every 10 seconds without reloading the page.
//
// v2: if a customer is logged in, the page shows ONLY that customer's devices
// and their command buttons authenticate with the session instead of the PIN.
// Anonymous visitors still see every device, exactly as v1 did.
// Tapping a variable row opens a 24h chart (data fetched on tap, not on load).
//
// v3: a device that reports both flow_gpm and total_gal is a flow meter and
// gets a dedicated card instead of the generic variable table — a large GPM
// readout, Total gallons / Last hour tiles, a FLOW OK status light, a flashing
// LOW FLOW LIMIT banner (tank dry / suction lost) and a PIN-gated "Reset total"
// button that posts cmd=2 to cmd.php and cmd=0 two seconds later. Every other
// device type keeps the generic card, untouched.
//
// v4: the flow meter card also carries a 7-day daily water usage bar chart,
// fed by daily.php and refreshed on the same 10s cycle as everything else.
// Days are California calendar days (see daily.php); the newest bar is today,
// still filling. Generic cards are unaffected.

require 'config.php';
require_once 'thresholds_lib.php';
or_session_resume();
$customer = current_customer();

// ---- JSON data endpoint for the auto-refresh ----
if (isset($_GET['data'])) {
  if ($customer) {
    $stmt = db()->prepare('SELECT * FROM devices WHERE customer_id = ? ORDER BY id');
    $stmt->execute([$customer['id']]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $devices = db()->query('SELECT * FROM devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
  }
  // Threshold rows and the states ingest.php already decided from them: two
  // queries for the whole page, not two per card.
  $allThresholds = or_thresholds_all();
  $allStates     = or_threshold_states_all();

  $out = [];
  foreach ($devices as $d) {
    $vals = [];
    $lastSeen = null;
    if ($d['enabled']) {
      $stmt = db()->prepare(
        'SELECT r.variable, r.value, r.created FROM readings r
         INNER JOIN (SELECT variable, MAX(id) mid FROM readings WHERE device_id = ?
                     GROUP BY variable) x ON x.mid = r.id');
      $stmt->execute([$d['id']]);
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $vals[$row['variable']] = $row['value'];
        if ($lastSeen === null || $row['created'] > $lastSeen) $lastSeen = $row['created'];
      }
    }
    $state = 'template';
    if ($d['enabled']) {
      if ($lastSeen === null) $state = 'waiting';
      else {
        $age = time() - strtotime($lastSeen);
        $state = ($age > 3 * $d['expected_interval']) ? 'stale' : 'live';
      }
    }
    // The card paints this; it never re-derives it from the limits. `thresholds`
    // rides along only so the gauge can draw its tick markers and prefill the
    // edit form — the ok/warn/alarm decision in `alarm` is already made.
    $th    = $allThresholds[$d['slug']] ?? [];
    $alarm = or_threshold_rollup($d, $th, $allStates[(int)$d['id']] ?? [], $lastSeen);

    $out[] = [
      'slug' => $d['slug'], 'name' => $d['name'],
      'variables' => array_map('trim', explode(',', $d['variables'])),
      'commandable' => (int)$d['commandable'], 'enabled' => (int)$d['enabled'],
      'notes' => $d['notes'], 'state' => $state,
      'values' => $vals, 'last_seen' => $lastSeen,
      'thresholds' => array_values(array_map(fn($t) => [
          'variable'   => $t['variable'],
          'low_alarm'  => $t['low_alarm']  === null ? null : (float)$t['low_alarm'],
          'low_warn'   => $t['low_warn']   === null ? null : (float)$t['low_warn'],
          'high_warn'  => $t['high_warn']  === null ? null : (float)$t['high_warn'],
          'high_alarm' => $t['high_alarm'] === null ? null : (float)$t['high_alarm'],
          'enabled'    => (int)$t['enabled'],
        ], $th)),
      'alarm' => $alarm,
    ];
  }
  json_out($out);
}

// ---- 24h history for one variable (fetched when a row is tapped) ----
if (isset($_GET['history'])) {
  $slug = $_GET['slug'] ?? '';
  $var  = $_GET['var'] ?? '';

  $stmt = db()->prepare('SELECT id, variables, enabled, customer_id FROM devices WHERE slug = ?');
  $stmt->execute([$slug]);
  $d = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$d) json_out(['error' => 'unknown device'], 404);

  // A logged-in customer can only chart their own devices.
  if ($customer && (int)$d['customer_id'] !== (int)$customer['id']) {
    json_out(['error' => 'not your device'], 403);
  }
  if (!in_array($var, array_map('trim', explode(',', $d['variables'])), true)) {
    json_out(['error' => 'unknown variable'], 404);
  }

  $stmt = db()->prepare(
    'SELECT value, created FROM readings
     WHERE device_id = ? AND variable = ? AND created >= NOW() - INTERVAL 24 HOUR
     ORDER BY created');
  $stmt->execute([$d['id'], $var]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Downsample so a 30s-interval board doesn't ship ~2880 points to a phone.
  $n = count($rows);
  $max = 300;
  if ($n > $max) {
    $step = (int)ceil($n / $max);
    $keep = [];
    $lastIdx = -1;
    for ($i = 0; $i < $n; $i += $step) { $keep[] = $rows[$i]; $lastIdx = $i; }
    if ($lastIdx !== $n - 1) $keep[] = $rows[$n - 1];   // always keep the newest
    $rows = $keep;
  }

  json_out([
    'slug' => $slug, 'variable' => $var,
    'points' => array_map(
      fn($r) => ['t' => $r['created'], 'v' => (float)$r['value']], $rows),
  ]);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenRanch — Live Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include 'pwa_head.php'; ?>
<style>
  /* OpenRanch harvest palette — cream ground, sage primary, golden-orange accent,
     soft sky blue for the water/level widgets. Status stays semantic: green ok,
     orange warning, red fault. Each status has a fill tone and a darker *-ink
     tone for text, because the vivid fills do not clear 4.5:1 as small text. */
  :root { --bg:#fbf7ee; --card:#ffffff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --green:#5a7d3a;  --green-ink:#4e7a34;
          --yellow:#c9860f; --yellow-ink:#9a6208;
          --red:#b3261e;    --red-ink:#b3261e;
          --accent:#d98a2b; --accent-ink:#9a5410;
          --sky:#7fb3d5;    --sky-ink:#2b6a90;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--bg); color:var(--text); font-family:'DM Sans',system-ui,sans-serif; padding:20px; }
  header { display:flex; align-items:baseline; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  h1 { font-size:22px; } h1 span { color:var(--accent); }
  header small { color:var(--dim); }
  .who { margin-left:auto; font-size:12px; color:var(--dim); }
  .who b { color:var(--text); font-weight:500; }
  .who a { color:var(--accent); text-decoration:none; margin-left:10px; }
  .who a:hover { text-decoration:underline; }
  /* Adding a device is the one action a new account needs, so it reads as a
     button rather than sitting in the row of plain links beside it. */
  .who a.addbtn { background:var(--accent); color:#3a2205; font-weight:700;
                  padding:5px 10px; border-radius:7px; }
  .who a.addbtn:hover { text-decoration:none; filter:brightness(1.05); }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:14px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; }
  .card.template { opacity:.55; }
  .card h2 { font-size:15px; display:flex; align-items:center; gap:8px; }
  .dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
  .dot.live { background:var(--green); box-shadow:0 0 6px var(--green); }
  .dot.waiting { background:var(--yellow); }
  .dot.stale { background:var(--red); box-shadow:0 0 6px var(--red); }
  .dot.template { background:var(--dim); }
  .badge { margin-left:auto; font-size:10px; letter-spacing:.08em; color:var(--dim); }
  .notes { font-size:11px; color:var(--dim); margin:6px 0 10px; }
  table { width:100%; border-collapse:collapse; }
  td { padding:4px 0; font-size:13px; border-bottom:1px solid var(--line); }
  td.var { color:var(--dim); font-family:'JetBrains Mono',monospace; font-size:11px; }
  td.val { text-align:right; font-family:'JetBrains Mono',monospace; font-weight:600; }
  td.val.empty { color:#b9b2a0; }
  tr.varrow.tappable { cursor:pointer; }
  tr.varrow.tappable:hover td.var { color:var(--accent); }
  tr.varrow.open td.var { color:var(--accent); }
  tr.varrow.tappable td.var::after { content:' \25B8'; opacity:.5; }
  tr.varrow.open td.var::after { content:' \25BE'; opacity:1; }
  .chartbox { height:130px; padding:8px 0 2px; }
  .charthint { font-size:10px; color:var(--dim); text-align:right; padding-bottom:6px; }
  .empty-note { color:var(--dim); font-size:13px; padding:20px; text-align:center;
                border:1px dashed var(--line); border-radius:12px; }
  .seen { font-size:11px; color:var(--dim); margin-top:8px; }
  .btns { display:flex; gap:8px; margin-top:12px; }
  button { flex:1; padding:8px; border:0; border-radius:8px; font-family:inherit;
           font-weight:700; cursor:pointer; font-size:13px; }
  button.on { background:var(--green); color:#ffffff; }
  button.off { background:#ece5d2; color:var(--text); }
  button:active { transform:scale(.97); }
  /* ---- AUX sub-rows (weather / master control board card only) ---- */
  .auxrow { display:flex; align-items:center; gap:8px; margin-top:8px; }
  .auxrow .auxlabel { font-size:11px; font-weight:700; color:var(--dim);
                      letter-spacing:.04em; width:42px; flex:none; }
  .auxrow .auxstate { display:flex; align-items:center; gap:5px; font-size:11px;
                      font-weight:700; color:var(--dim); width:52px; flex:none; }
  .auxrow .auxstate .lamp { width:9px; height:9px; border-radius:50%; background:#d6cfb8; }
  .auxrow .auxstate.on { color:var(--green); }
  .auxrow .auxstate.on .lamp { background:var(--green); box-shadow:0 0 7px var(--green); }
  .auxrow button { padding:6px; font-size:11px; }
  /* ---- flow meter card ---- */
  .card.alarming { border-color:var(--red);
                   box-shadow:0 0 0 1px var(--red), 0 0 26px rgba(179,38,30,.20); }
  .alarm { display:flex; align-items:center; gap:10px; margin:10px 0 12px; padding:11px 12px;
           border-radius:10px; background:var(--red); color:#fff; font-weight:700;
           font-size:14px; letter-spacing:.05em; animation:alarmflash 1s steps(1,end) infinite; }
  .alarm .alarmicon { font-size:20px; line-height:1; }
  .alarm small { display:block; font-size:10px; font-weight:500; letter-spacing:.08em; opacity:.85; }
  @keyframes alarmflash {
    0%,49%   { background:var(--red); color:#fff;     box-shadow:0 0 20px rgba(179,38,30,.55); }
    50%,100% { background:#ffe3e0;     color:#8f1f18; box-shadow:0 0 0 rgba(179,38,30,0); }
  }
  /* Never let the alarm vanish for someone who has motion turned off — it just
     stops blinking and stays lit. */
  @media (prefers-reduced-motion:reduce) {
    .alarm { animation:none; box-shadow:0 0 20px rgba(179,38,30,.55); }
  }
  .flowmain { display:flex; align-items:baseline; justify-content:center; gap:8px;
              padding:12px 0 2px; border-radius:10px; }
  .flowval { font-family:'JetBrains Mono',monospace; font-weight:600; font-size:46px; line-height:1; }
  .flowval.empty { color:#b9b2a0; }
  .flowunit { font-size:14px; letter-spacing:.1em; color:var(--dim); font-weight:500; }
  .flowmain.tappable { cursor:pointer; }
  .flowmain.tappable:hover .flowunit { color:var(--accent); }
  .flowmain.open .flowunit { color:var(--accent); }
  .light { display:flex; align-items:center; justify-content:center; gap:7px;
           margin:8px 0 12px; font-size:11px; font-weight:700;
           letter-spacing:.12em; color:var(--dim); }
  .light .lamp { width:10px; height:10px; border-radius:50%; background:#d6cfb8; }
  .light.on { color:var(--green); }
  .light.on .lamp { background:var(--green); box-shadow:0 0 8px var(--green); }
  .flowstats { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .stat { background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:8px 10px; }
  .stat.tappable { cursor:pointer; }
  .stat.tappable:hover, .stat.open { border-color:var(--accent); }
  .statlabel { font-size:10px; letter-spacing:.06em; color:var(--dim); }
  .statval { font-family:'JetBrains Mono',monospace; font-weight:600; font-size:17px; margin-top:3px; }
  .statval.empty { color:#b9b2a0; }
  .flowchart { margin-top:10px; }
  .daily { margin-top:14px; border-top:1px solid var(--line); padding-top:10px; }
  .dailyhead { display:flex; align-items:baseline; gap:6px; font-size:10px;
               letter-spacing:.06em; color:var(--dim); margin-bottom:2px; }
  .dailyhead b { color:var(--text); font-weight:500; letter-spacing:.04em; }
  .dailybox { height:150px; }
  .dailynote { font-size:10px; color:var(--dim); text-align:center; padding:26px 0; }
  button.reset { background:#ece5d2; color:var(--text); }
  button.reset:disabled { opacity:.55; cursor:default; transform:none; }
  /* ---- threshold states ---- */
  /* .card.alarming above is reused as-is for a threshold alarm — same red halo
     the low-flow alarm already uses, so one glow means one thing. Warn is the
     same idea a step quieter: a lit yellow edge and a warm tint, no halo, so a
     card that needs watching and a card that needs acting on never look alike
     at a glance across a grid. */
  .card.warning { border-color:var(--yellow); background:#fdf4e0;
                  box-shadow:0 0 0 1px var(--yellow), 0 0 18px rgba(201,134,15,.18); }

  /* ---- gauge card ---- */
  .gauge { padding:4px 0 0; }
  .gauge.tappable { cursor:pointer; }
  .gauge svg { display:block; width:100%; height:auto; }
  .gauge.tappable:hover .gaugevar { fill:var(--accent); }
  .gaugestate { text-align:center; font-size:10px; letter-spacing:.1em;
                font-weight:700; color:var(--dim); margin:2px 0 4px; }
  .gaugestate.warn  { color:var(--yellow); }
  .gaugestate.alarm { color:var(--red); }
  .gaugestate small { display:block; font-size:10px; font-weight:500;
                      letter-spacing:.04em; color:var(--dim); margin-top:3px; }

  /* ---- threshold editor (logged-in customers) ---- */
  .thedit { margin-top:12px; border-top:1px solid var(--line); padding-top:9px; }
  .thedit summary { font-size:10px; letter-spacing:.08em; color:var(--dim);
                    cursor:pointer; list-style:none; }
  .thedit summary::-webkit-details-marker { display:none; }
  .thedit summary::before { content:'\2699\00a0 '; }
  .thedit summary:hover { color:var(--accent); }
  .thgrid { display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-top:9px; }
  .thgrid label { font-size:9px; letter-spacing:.06em; color:var(--dim); }
  .thgrid input { width:100%; margin-top:3px; padding:5px 7px; background:var(--bg);
                  border:1px solid var(--line); border-radius:6px; color:var(--text);
                  font-family:'JetBrains Mono',monospace; font-size:12px; }
  .thgrid input:focus { outline:none; border-color:var(--accent); }
  .throw { display:flex; align-items:center; gap:10px; margin-top:10px;
           font-size:11px; color:var(--dim); }
  .throw label { display:flex; align-items:center; gap:5px; cursor:pointer; }
  .throw input[type=checkbox] { width:auto; margin:0; accent-color:var(--accent); }
  .throw button { flex:0 0 auto; margin-left:auto; padding:6px 14px;
                  background:var(--accent); color:#3a2205; font-size:12px; }
  .thhint { font-size:10px; color:var(--dim); margin-top:7px; line-height:1.5; }
  #toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
           background:var(--card); border:1px solid var(--line); padding:10px 18px;
           border-radius:10px; font-size:13px; display:none; }

  /* ---- harvest theme overrides -------------------------------------------
     Later rules, same selectors as above, so the token swap lands without
     rewriting the block it overrides. Two jobs: (1) anywhere the accent was
     used as TEXT it becomes --accent-ink (the golden fill is 2.8:1 on white
     and fails AA as small text); (2) the water/level widgets pick up sky
     blue, and the header carries the warm gradient. */
  header { background:var(--grad); border:1px solid var(--line); border-radius:12px;
           padding:14px 16px; }
  h1 span { color:var(--accent-ink); }
  .who a { color:var(--accent-ink); }
  tr.varrow.tappable:hover td.var { color:var(--accent-ink); }
  tr.varrow.open td.var { color:var(--accent-ink); }
  .gauge.tappable:hover .gaugevar { fill:var(--accent-ink); }
  .thedit summary:hover { color:var(--accent-ink); }
  .gaugestate.warn  { color:var(--yellow-ink); }
  .gaugestate.alarm { color:var(--red-ink); }
  /* status dots: semantic, with a halo soft enough for a light ground */
  .dot.live  { background:var(--green); box-shadow:0 0 6px rgba(90,125,58,.55); }
  .dot.waiting { background:var(--yellow); }
  .dot.stale { background:var(--red); box-shadow:0 0 6px rgba(179,38,30,.45); }
  .auxrow .auxstate.on { color:var(--green-ink); }
  .auxrow .auxstate.on .lamp { background:var(--green); box-shadow:0 0 7px rgba(90,125,58,.6); }
  /* ---- water / level widgets: soft sky blue ---- */
  .flowval { color:var(--sky-ink); }
  .flowval.empty { color:#b9b2a0; }
  .flowmain.tappable:hover .flowunit { color:var(--sky-ink); }
  .flowmain.open .flowunit { color:var(--sky-ink); }
  .light.on { color:var(--sky-ink); }
  .light.on .lamp { background:var(--sky); box-shadow:0 0 8px rgba(127,179,213,.9); }
  .stat.tappable:hover, .stat.open { border-color:var(--sky); }
  #toast { background:var(--text); color:var(--bg); border-color:var(--text); }
</style>
</head>
<body>
<header>
  <h1>Open<span>Ranch</span></h1>
  <small>live dashboard &middot; refreshes every 10s &middot; data kept <?= RETENTION_DAYS ?> days</small>
  <div class="who">
    <?php if ($customer): ?>
      signed in as <b><?= htmlspecialchars($customer['name'] !== '' ? $customer['name'] : $customer['email']) ?></b>
      <a href="claim.php" class="addbtn">+ Add a device</a>
      <button type="button" class="pushbtn" id="pushbtn" style="display:none">Enable notifications</button>
      <a href="logout.php">sign out</a>
    <?php else: ?>
      <a href="login.php">customer sign in</a>
    <?php endif; ?>
  </div>
</header>
<div class="grid" id="grid"></div>
<div id="toast"></div>
<div id="installbar"></div>
<script src="/pwa.js" defer></script>

<script>
// A logged-in customer authenticates commands with their session cookie;
// everyone else is prompted for the admin PIN exactly as before.
const IS_CUSTOMER = <?= $customer ? 'true' : 'false' ?>;
let PIN = sessionStorage.getItem('or_pin') || '';

// key `slug|var` -> { points: [...], chart: Chart|null, loading: bool }
const openCharts = new Map();
// slug -> { days: [...]|null, chart: Chart|null, error: string|null }
const dailyCharts = new Map();
let chartLib = null;

// ---- flow meters ----------------------------------------------------------
// A flow meter is any device reporting flow_gpm together with total_gal. The
// pumps and sprinklers also report flow_gpm as one reading among many, and they
// must keep the generic card, so the totaliser is what tells the two apart.
const FLOW_VARS = ['flow_gpm', 'total_gal', 'flow_gph', 'flow_ok', 'low_flow_alarm'];
const isFlowMeter = d => d.variables.includes('flow_gpm') && d.variables.includes('total_gal');

// Slugs with a reset in flight, so the button stays disabled across the 10s
// refresh that rebuilds every card.
const resetPending = new Set();

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.display = 'block';
  setTimeout(() => t.style.display = 'none', 2500);
}

function fmt(v) {
  if (v === undefined) return '&mdash;';
  const n = Number(v);
  return Number.isInteger(n) ? n : n.toFixed(2);
}

// Flow readings are always fractional in practice; a fixed decimal keeps the
// big readout from jittering in width every refresh.
function fmtFlow(v, dp) {
  if (v === undefined) return '&mdash;';
  const n = Number(v);
  if (!isFinite(n)) return '&mdash;';
  return n.toLocaleString(undefined, { minimumFractionDigits: dp, maximumFractionDigits: dp });
}

// Server timestamps are MySQL DATETIME strings in UTC ("2026-08-27 00:01:50").
// "2026-08-27T00:01:50" with no zone designator is parsed by JS as LOCAL time,
// which shifts every age by the viewer's UTC offset (-7h in California, giving
// negative "ago" values). The trailing Z pins it to UTC, where it was written.
function parseUTC(ts) {
  return new Date(String(ts).trim().replace(' ', 'T') + 'Z');
}

function ago(ts) {
  if (!ts) return 'never';
  const s = Math.floor((Date.now() - parseUTC(ts)) / 1000);
  if (s < 60) return s + 's ago';
  if (s < 3600) return Math.floor(s/60) + 'm ago';
  return Math.floor(s/3600) + 'h ago';
}

// `label` omitted -> the usual ON/OFF toast; null -> no toast at all (used for
// the housekeeping cmd=0 that follows a total reset). Returns whether the
// command was accepted, so callers can stop after a bad PIN.
async function sendCmd(slug, cmd, label) {
  const body = new URLSearchParams({ slug: slug, cmd: cmd });
  if (!IS_CUSTOMER) {
    if (!PIN) {
      PIN = prompt('PIN:') || '';
      sessionStorage.setItem('or_pin', PIN);
    }
    body.set('pin', PIN);
  }
  const r = await fetch('cmd.php', { method: 'POST', body });
  const j = await r.json();
  if (j.error) {
    toast('Error: ' + j.error);
    if (j.error === 'bad pin') { PIN=''; sessionStorage.removeItem('or_pin'); }
    return false;
  }
  if (label === undefined) toast(slug + ' → ' + (cmd ? 'ON / START' : 'OFF / STOP'));
  else if (label !== null) toast(label);
  return true;
}

// "Reset total": cmd=2 tells the meter to zero its totaliser, then cmd=0 puts
// the command slot back to idle two seconds later so a board that polls after
// the reset doesn't read the 2 again and zero itself a second time.
async function resetTotal(slug) {
  if (resetPending.has(slug)) return;
  if (!confirm('Reset the running total on ' + slug + ' to zero?\n\nThis cannot be undone.')) return;

  resetPending.add(slug);
  render(lastDevices);

  if (!await sendCmd(slug, 2, slug + ' → RESET TOTAL')) {
    resetPending.delete(slug);
    render(lastDevices);
    return;
  }
  setTimeout(async () => {
    await sendCmd(slug, 0, null);
    resetPending.delete(slug);
    render(lastDevices);
  }, 2000);
}

// ---- 7-day daily usage chart (flow meter cards only) ---------------------
// daily.php always returns exactly 7 days ending today, zero-filled, so the
// chart keeps its shape and the axis never rescales between refreshes.
const DAILY_MAX = 500;                       // fixed y-axis ceiling, gallons
const dailyCanvasId = slug => 'daily_' + slug.replace(/[^A-Za-z0-9]/g, '_');

// Whole gallons: a bar label is ~35px wide on a phone, and tenths of a gallon
// are noise at this scale. The tooltip still carries the precise figure.
const fmtGal = v => Math.round(Number(v) || 0).toLocaleString();

// Pulls the usage series for every flow meter on the page. Failures are held
// per-slug so one bad response can never stall or blank the whole dashboard.
async function fetchDaily(slug) {
  let entry = dailyCharts.get(slug);
  if (!entry) dailyCharts.set(slug, entry = { days: null, chart: null, error: null });
  try {
    const r = await fetch('daily.php?slug=' + encodeURIComponent(slug));
    const j = await r.json();
    if (j.error) throw new Error(j.error);
    entry.days = j.days;
    entry.error = null;
  } catch (err) {
    // Keep the last good series on screen rather than flashing it away.
    if (!entry.days) entry.error = err.message;
  }
}

// Chart.js has no built-in bar labels and one CDN dependency is enough, so the
// numbers are drawn directly. A day over the fixed 500 ceiling has its bar
// clipped by the axis, so its label is pinned inside the plot area instead of
// being drawn off the top of the canvas where it would be invisible.
const barValues = {
  id: 'barValues',
  afterDatasetsDraw(chart) {
    const { ctx, chartArea } = chart;
    const data = chart.data.datasets[0].data;
    ctx.save();
    ctx.font = '600 10px \'JetBrains Mono\', monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    chart.getDatasetMeta(0).data.forEach((bar, i) => {
      ctx.fillStyle = data[i] > DAILY_MAX ? '#b3261e' : '#6b6a5a';
      ctx.fillText(fmtGal(data[i]), bar.x, Math.max(bar.y - 4, chartArea.top + 11));
    });
    ctx.restore();
  },
};

function drawDaily(slug) {
  const e = dailyCharts.get(slug);
  if (!e || !e.days) return;
  const cv = document.getElementById(dailyCanvasId(slug));
  if (!cv || typeof Chart === 'undefined') return;
  if (e.chart) e.chart.destroy();

  e.chart = new Chart(cv, {
    type: 'bar',
    plugins: [barValues],
    data: {
      // Two lines so seven "Sat 8/29" labels still fit across a 310px card.
      labels: e.days.map(d => d.label.split(' ')),
      datasets: [{
        data: e.days.map(d => d.gallons),
        backgroundColor: e.days.map(d => d.today ? '#2b6a90' : 'rgba(127,179,213,.45)'),
        borderColor:     e.days.map(d => d.today ? '#2b6a90' : 'rgba(127,179,213,.70)'),
        borderWidth: 1, borderRadius: 3, maxBarThickness: 34,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      layout: { padding: { top: 14 } },      // headroom for the bar labels
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#2b2a22', borderColor: '#e8dfc9', borderWidth: 1,
          titleColor: '#fbf7ee', bodyColor: '#fbf7ee',
          callbacks: {
            title: items => e.days[items[0].dataIndex].label
                            + (e.days[items[0].dataIndex].today ? ' (today, so far)' : ''),
            label: item => item.parsed.y.toFixed(1) + ' gal',
          },
        },
      },
      scales: {
        x: { ticks: { color: '#6b6a5a', font: { size: 9 } },
             grid: { display: false } },
        y: { min: 0, max: DAILY_MAX, ticks: { color: '#6b6a5a', font: { size: 9 }, stepSize: 100 },
             grid: { color: '#e8dfc9' } },
      },
    },
  });
}

// The block that sits under the tiles on a flow card.
function dailyInner(d) {
  const e = dailyCharts.get(d.slug);
  let body;
  if (e && e.days)      body = `<div class="dailybox"><canvas id="${dailyCanvasId(d.slug)}"></canvas></div>`;
  else if (e && e.error) body = `<div class="dailynote">daily usage unavailable — ${e.error}</div>`;
  else                   body = `<div class="dailynote">loading daily usage…</div>`;
  return `<div class="daily">
            <div class="dailyhead"><b>DAILY USAGE</b> &middot; gallons &middot; last 7 days</div>
            ${body}
          </div>`;
}

// ---- history charts: Chart.js is only downloaded once a row is tapped ----
function loadChartLib() {
  if (!chartLib) {
    chartLib = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
      s.onload = resolve;
      s.onerror = () => { chartLib = null; reject(new Error('could not load Chart.js')); };
      document.head.appendChild(s);
    });
  }
  return chartLib;
}

const canvasId = key => 'chart_' + key.replace(/[^A-Za-z0-9]/g, '_');

async function toggleChart(slug, variable) {
  const key = slug + '|' + variable;
  if (openCharts.has(key)) {                 // tapped an open row -> close it
    const e = openCharts.get(key);
    if (e.chart) e.chart.destroy();
    openCharts.delete(key);
    render(lastDevices);
    return;
  }
  openCharts.set(key, { points: null, chart: null, loading: true });
  render(lastDevices);                        // show the loading placeholder

  try {
    const [ , r] = await Promise.all([
      loadChartLib(),
      fetch('?history=1&slug=' + encodeURIComponent(slug) + '&var=' + encodeURIComponent(variable)),
    ]);
    const j = await r.json();
    if (j.error) throw new Error(j.error);
    const e = openCharts.get(key);
    if (!e) return;                           // closed again while loading
    e.points = j.points; e.loading = false;
  } catch (err) {
    openCharts.delete(key);
    toast('Chart error: ' + err.message);
  }
  render(lastDevices);
}

function drawChart(key, variable) {
  const e = openCharts.get(key);
  if (!e || !e.points) return;
  const cv = document.getElementById(canvasId(key));
  if (!cv || typeof Chart === 'undefined') return;
  if (e.chart) e.chart.destroy();

  const labels = e.points.map(p => {
    const d = parseUTC(p.t);   // UTC in, rendered in the viewer's local clock
    return d.getHours().toString().padStart(2,'0') + ':' +
           d.getMinutes().toString().padStart(2,'0');
  });

  e.chart = new Chart(cv, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: variable,
        data: e.points.map(p => p.v),
        borderColor: '#d98a2b',
        backgroundColor: 'rgba(217,138,43,.14)',
        borderWidth: 2, pointRadius: 0, tension: .25, fill: true,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      plugins: { legend: { display: false },
                 tooltip: { backgroundColor: '#2b2a22', borderColor: '#e8dfc9',
                            borderWidth: 1, titleColor: '#fbf7ee', bodyColor: '#fbf7ee' } },
      scales: {
        x: { ticks: { color: '#6b6a5a', font: { size: 9 }, maxTicksLimit: 5 },
             grid: { color: '#e8dfc9' } },
        y: { ticks: { color: '#6b6a5a', font: { size: 9 }, maxTicksLimit: 4 },
             grid: { color: '#e8dfc9' } },
      },
    },
  });
}

// ---- threshold gauges -----------------------------------------------------
// A card gets a gauge when the device declares a variable that has an enabled
// threshold row. Nothing here decides ok/warn/alarm: ingest.php graded the
// reading when it landed and the server hands the verdict over in d.alarm. This
// code only draws it, plus a tick marker at each limit so the number on the arc
// has something to be read against.
//
// Geometry: a 240 degree arc opening downwards, centred in a 200x140 viewBox.
// `t` is the position along it — 0 at the left end, 1 at the right.
const GA = { cx: 100, cy: 90, r: 76, start: 150, sweep: 240 };
const TH_COLS  = ['low_alarm', 'low_warn', 'high_warn', 'high_alarm'];
const TH_LABEL = { low_alarm:'LOW LIMIT',    low_warn:'LOW WARNING',
                   high_warn:'HIGH WARNING', high_alarm:'HIGH LIMIT',
                   stale:'SENSOR OFFLINE' };
const TH_SHORT = { low_alarm:'Low limit', low_warn:'Low warn',
                   high_warn:'High warn', high_alarm:'High limit' };
// Display-only spelling of the wire state. `al.state` itself stays ok/warn/alarm
// because ingest.php, threshold_state.state and the JSON all speak that word.
const STATE_LABEL = { ok:'OK', warn:'WARNING', alarm:'LIMIT' };
const STATE_COLOR = { ok:'#5a7d3a', warn:'#c9860f', alarm:'#b3261e' };
const DIM = '#6b6a5a';

// The first threshold row naming a variable this device actually reports. A row
// for a variable it never sends is ignored — same rule as ?history=.
const gaugeThreshold = d =>
  (d.thresholds || []).find(t => d.variables.includes(t.variable)) || null;

const gaugeLimits = t =>
  TH_COLS.map(c => t[c]).filter(v => v !== null && v !== undefined).map(Number);

function gaugeXY(r, t) {
  const a = (GA.start + t * GA.sweep) * Math.PI / 180;
  return [GA.cx + r * Math.cos(a), GA.cy + r * Math.sin(a)];
}

function gaugeArc(r, t0, t1) {
  const [x0, y0] = gaugeXY(r, t0), [x1, y1] = gaugeXY(r, t1);
  const large = (t1 - t0) * GA.sweep > 180 ? 1 : 0;
  return `M ${x0.toFixed(2)} ${y0.toFixed(2)} A ${r} ${r} 0 ${large} 1 `
       + `${x1.toFixed(2)} ${y1.toFixed(2)}`;
}

// Round a scale end outwards to a number a person would have picked (550, not
// 514.5), so the arc reads like an instrument face.
function niceEnd(v, up) {
  if (v === 0) return 0;
  const step = Math.pow(10, Math.floor(Math.log10(Math.abs(v)))) / 2;
  return (up ? Math.ceil(v / step) : Math.floor(v / step)) * step;
}

// The arc spans the limits with 15% headroom, so an alarm tick never sits on
// the very end of the scale with nowhere for an over-range reading to go.
function gaugeScale(t) {
  const lims = gaugeLimits(t);
  if (!lims.length) return { min: 0, max: 100 };
  let lo = Math.min(...lims), hi = Math.max(...lims);
  if (hi === lo) { lo -= 1; hi += 1; }
  const pad = (hi - lo) * 0.15;
  // Limits that are all positive mean a pressure, a level or a flow: start the
  // scale at zero rather than at some arbitrary number below the low alarm.
  const min = (lo >= 0 && lo - pad <= 0) ? 0 : niceEnd(lo - pad, false);
  return { min, max: niceEnd(hi + pad, true) };
}

// Tick labels share the arc with each other, so they stay short.
function fmtTick(v) {
  const n = Number(v);
  if (Math.abs(n) >= 1000) return (n / 1000) + 'k';
  return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

function gaugeSvg(d, t, state, offline) {
  const sc   = gaugeScale(t);
  const span = (sc.max - sc.min) || 1;
  const pos  = v => Math.max(0, Math.min(1, (v - sc.min) / span));
  const raw  = d.values[t.variable];
  const has  = raw !== undefined;
  // Offline greys the whole face out: the last number is still shown, because
  // knowing what it was when the sensor went quiet is the useful part, but it
  // is no longer coloured as if it were current.
  const color = offline ? DIM : (STATE_COLOR[state] || DIM);
  const dp = span >= 50 ? 0 : 1;

  let ticks = '';
  for (const c of TH_COLS) {
    if (t[c] === null || t[c] === undefined) continue;
    const tt = pos(Number(t[c]));
    const [ix, iy] = gaugeXY(GA.r - 10, tt);
    const [ox, oy] = gaugeXY(GA.r + 7,  tt);
    const [lx, ly] = gaugeXY(GA.r - 19, tt);
    const tc = c.endsWith('alarm') ? STATE_COLOR.alarm : STATE_COLOR.warn;
    ticks += `<line x1="${ix.toFixed(2)}" y1="${iy.toFixed(2)}"
                    x2="${ox.toFixed(2)}" y2="${oy.toFixed(2)}"
                    stroke="${tc}" stroke-width="2" stroke-linecap="round"/>
              <text x="${lx.toFixed(2)}" y="${(ly + 2.4).toFixed(2)}" fill="${tc}"
                    opacity=".85" font-size="7" text-anchor="middle"
                    font-family="'JetBrains Mono',monospace">${fmtTick(t[c])}</text>`;
  }

  // A reading exactly at the scale minimum would otherwise draw a zero-length
  // path and disappear; a hair of arc keeps the pointer visible at the bottom.
  let value = '';
  if (has) {
    const tv = Math.max(pos(Number(raw)), 0.006);
    value = `<path d="${gaugeArc(GA.r, 0, tv)}" fill="none" stroke="${color}"
                   stroke-width="10" stroke-linecap="round"/>`;
  }

  const [e0x, e0y] = gaugeXY(GA.r + 9, 0), [e1x, e1y] = gaugeXY(GA.r + 9, 1);

  return `<svg viewBox="0 0 200 140" role="img"
               aria-label="${t.variable} ${has ? fmtFlow(raw, dp) : 'no reading'}">
    <path d="${gaugeArc(GA.r, 0, 1)}" fill="none" stroke="#e8dfc9"
          stroke-width="10" stroke-linecap="round"/>
    ${value}
    ${ticks}
    <text x="${e0x.toFixed(2)}" y="${(e0y + 2.4).toFixed(2)}" fill="${DIM}" opacity=".7"
          font-size="7" text-anchor="middle"
          font-family="'JetBrains Mono',monospace">${fmtTick(sc.min)}</text>
    <text x="${e1x.toFixed(2)}" y="${(e1y + 2.4).toFixed(2)}" fill="${DIM}" opacity=".7"
          font-size="7" text-anchor="middle"
          font-family="'JetBrains Mono',monospace">${fmtTick(sc.max)}</text>
    <text x="${GA.cx}" y="88" fill="${has ? color : '#b9b2a0'}" font-size="30"
          font-weight="600" text-anchor="middle"
          font-family="'JetBrains Mono',monospace">${has ? fmtFlow(raw, dp) : '&mdash;'}</text>
    <text x="${GA.cx}" y="104" class="gaugevar" fill="${DIM}" font-size="9"
          letter-spacing="1.2" text-anchor="middle"
          font-family="'JetBrains Mono',monospace">${t.variable}</text>
  </svg>`;
}

// "limit ≤ 20 · warn ≤ 50 · warn ≥ 400 · limit ≥ 450"
function thSummary(t) {
  const bits = [];
  for (const c of TH_COLS) {
    if (t[c] === null || t[c] === undefined) continue;
    bits.push((c.endsWith('alarm') ? 'limit ' : 'warn ')
              + (c.startsWith('low') ? '≤ ' : '≥ ') + fmtTick(t[c]));
  }
  return bits.join(' · ');
}

function gaugeStateText(d, t, al) {
  if (!al || al.state === 'ok') {
    return `WITHIN LIMITS<small>${thSummary(t)}</small>`;
  }
  if (al.reason === 'stale') {
    // The dead-sensor alarm: no reading is not the same as a reading of zero.
    return `SENSOR OFFLINE<small>silent for ${ago(d.last_seen).replace(' ago','')}`
         + ` &middot; limits cannot be checked</small>`;
  }
  const dir = al.reason && al.reason.startsWith('low') ? 'at or below' : 'at or above';
  const val = al.value === null || al.value === undefined ? '' : fmtTick(al.value) + ' is ';
  return `${TH_LABEL[al.reason] || STATE_LABEL[al.state] || al.state.toUpperCase()}`
       + `<small>${val}${dir} ${fmtTick(al.limit)} &middot; ${t.variable}</small>`;
}

function gaugeBlock(d, t, al) {
  const state   = al ? al.state : 'ok';
  const offline = !!(al && al.reason === 'stale');
  const tap     = canChart(d, t.variable) ? ' tappable' : '';
  const key     = d.slug + '|' + t.variable;
  const open    = openCharts.has(key) ? ' open' : '';

  let html = `<div class="gauge${tap}${open}" data-slug="${d.slug}" data-var="${t.variable}">
                ${gaugeSvg(d, t, state, offline)}
              </div>
              <div class="gaugestate ${state}">${gaugeStateText(d, t, al)}</div>`;
  if (openCharts.has(key)) html += `<div class="flowchart">${chartInner(key)}</div>`;
  return html;
}

// ---- threshold editor (logged-in customers) ------------------------------
// The grid is rebuilt from scratch every 10 seconds, which would otherwise slam
// the form shut and swallow a half-typed number mid-edit. So the open flag and
// the field contents live out here, keyed by slug, and the re-render paints
// them back — the same trick the open charts and the pending resets use.
const thEditors = new Map();

function thDraftFrom(t) {
  const dr = { enabled: !!t.enabled };
  for (const c of TH_COLS) dr[c] = (t[c] === null || t[c] === undefined) ? '' : String(t[c]);
  return dr;
}

function thEditorState(d, t) {
  let e = thEditors.get(d.slug);
  if (!e) thEditors.set(d.slug, e = { open: false, draft: null });
  if (!e.draft) e.draft = thDraftFrom(t);      // null = reload from the server
  return e;
}

function thEditor(d, t) {
  if (!IS_CUSTOMER) return '';                 // PIN holders use thresholds.php
  const dr = thEditorState(d, t).draft;
  let fields = '';
  for (const c of TH_COLS) {
    fields += `<div><label>${TH_SHORT[c]}</label>
      <input type="number" step="any" placeholder="&mdash;" value="${dr[c]}"
             data-slug="${d.slug}" data-col="${c}"></div>`;
  }
  return `<details class="thedit" data-slug="${d.slug}"${thEditors.get(d.slug).open ? ' open' : ''}>
    <summary>limit thresholds &middot; ${t.variable}</summary>
    <div class="thgrid">${fields}</div>
    <div class="throw">
      <label><input type="checkbox" data-slug="${d.slug}" data-col="enabled"
                    ${dr.enabled ? 'checked' : ''}> enabled</label>
      <button type="button" onclick="saveThresholds('${d.slug}','${t.variable}')">Save</button>
    </div>
    <div class="thhint">Leave a field blank to stop policing that side.
      Order must read low limit &le; low warn &le; high warn &le; high limit.</div>
  </details>`;
}

async function saveThresholds(slug, variable) {
  const e = thEditors.get(slug);
  if (!e) return;
  const body = new URLSearchParams({ action: 'save', slug: slug, variable: variable });
  for (const c of TH_COLS) body.set(c, String(e.draft[c] ?? '').trim());
  body.set('enabled', e.draft.enabled ? '1' : '0');
  try {
    const r = await fetch('thresholds.php', { method: 'POST', body });
    const j = await r.json();
    if (j.error) { toast('Error: ' + j.error); return; }
    e.draft = null;                            // repaint from what was stored
    toast(slug + ' thresholds saved');
    await refresh();
  } catch (err) {
    toast('Error: ' + err.message);
  }
}

// ---- rendering ----
let lastDevices = [];

// The open-chart block for one `slug|var`, or '' when that row is closed.
// Shared by both card types so a chart looks the same wherever it is opened.
function chartInner(key) {
  const e = openCharts.get(key);
  if (!e) return '';
  if (e.loading) return `<div class="charthint">loading 24h history…</div>`;
  if (!e.points.length) return `<div class="charthint">no readings in the last 24h</div>`;
  return `<div class="chartbox"><canvas id="${canvasId(key)}"></canvas></div>
          <div class="charthint">last 24h &middot; ${e.points.length} points</div>`;
}

// Generic variable table rows (also used on a flow card for any variable that
// isn't part of the flow set, e.g. battery_v or rssi).
function varRows(d, vars) {
  let rows = '';
  for (const v of vars) {
    const has = d.values[v] !== undefined;
    const key = d.slug + '|' + v;
    const entry = openCharts.get(key);
    const tappable = d.enabled ? ' tappable' : '';
    rows += `<tr class="varrow${tappable}${entry ? ' open' : ''}"
                 data-slug="${d.slug}" data-var="${v}">
             <td class="var">${v}</td>
             <td class="val ${has?'':'empty'}">${fmt(d.values[v])}</td></tr>`;
    if (entry) rows += `<tr><td colspan="2">${chartInner(key)}</td></tr>`;
  }
  return rows;
}

// An empty table would draw a stray border, so a card whose every variable is
// on the gauge gets no table at all.
const tableFor = (d, vars) => vars.length ? `<table>${varRows(d, vars)}</table>` : '';

function genericBtns(d) {
  if (!(d.commandable && d.enabled)) return '';
  return `<div class="btns">
    <button class="on" onclick="sendCmd('${d.slug}',1)">ON / START</button>
    <button class="off" onclick="sendCmd('${d.slug}',0)">OFF / STOP</button></div>`
    + auxBtns(d);
}

// ---- AUX sub-rows: weather / master control board only --------------------
// That board's firmware maps cmd 0 = both AUX off, 1 = both AUX on (the master
// ON/START-OFF/STOP pair above), then 2/3 = AUX_1 on/off and 4/5 = AUX_2 on/off.
// Those four codes are meaningless to every other board, so this is gated on the
// slug and every other card renders exactly the markup it did before.
const AUX_SLUG = 'weather_station';
const AUX_ROWS = [
  { label: 'AUX 1', variable: 'aux1', on: 2, off: 3 },
  { label: 'AUX 2', variable: 'aux2', on: 4, off: 5 },
];

function auxBtns(d) {
  if (d.slug !== AUX_SLUG) return '';
  return AUX_ROWS.map(a => {
    // Tri-state: the board has said 1, has said 0, or has never reported yet.
    const raw = d.values[a.variable];
    const lit = Number(raw) === 1;
    const txt = raw === undefined ? '--' : (lit ? 'ON' : 'OFF');
    return `<div class="auxrow">
      <span class="auxlabel">${a.label}</span>
      <span class="auxstate ${lit ? 'on' : 'off'}"><span class="lamp"></span>${txt}</span>
      <button class="on"  onclick="sendCmd('${d.slug}',${a.on},'${a.label} ON')">ON</button>
      <button class="off" onclick="sendCmd('${d.slug}',${a.off},'${a.label} OFF')">OFF</button>
    </div>`;
  }).join('');
}

// ---- flow meter card ----
// Only charts variables the device actually declares — tapping one it never
// reports would just come back 404 from ?history=1.
function canChart(d, v) { return d.enabled && d.variables.includes(v); }

function statTile(d, v, label, dp) {
  const tap  = canChart(d, v) ? ' tappable' : '';
  const open = openCharts.has(d.slug + '|' + v) ? ' open' : '';
  const has  = d.values[v] !== undefined;
  return `<div class="stat${tap}${open}" data-slug="${d.slug}" data-var="${v}">
            <div class="statlabel">${label}</div>
            <div class="statval ${has?'':'empty'}">${fmtFlow(d.values[v], dp)}</div>
          </div>`;
}

function flowBody(d, alarm) {
  let html = '';

  // Tank dry / suction lost. Flashing banner plus a red halo on the whole card.
  if (alarm) {
    html += `<div class="alarm"><span class="alarmicon">&#9888;</span>
               <span>LOW FLOW LIMIT<small>TANK DRY / SUCTION LOST</small></span></div>`;
  }

  const gpmTap  = canChart(d, 'flow_gpm') ? ' tappable' : '';
  const gpmOpen = openCharts.has(d.slug + '|flow_gpm') ? ' open' : '';
  const hasGpm  = d.values.flow_gpm !== undefined;
  html += `<div class="flowmain${gpmTap}${gpmOpen}" data-slug="${d.slug}" data-var="flow_gpm">
             <div class="flowval ${hasGpm?'':'empty'}">${fmtFlow(d.values.flow_gpm, 1)}</div>
             <div class="flowunit">GPM</div>
           </div>`;

  // Gray unless the board is actively reporting flow_ok = 1.
  const ok = Number(d.values.flow_ok) === 1;
  html += `<div class="light ${ok ? 'on' : 'off'}"><span class="lamp"></span>FLOW OK</div>`;

  html += `<div class="flowstats">
             ${statTile(d, 'total_gal', 'Total gallons', 1)}
             ${statTile(d, 'flow_gph',  'Last hour',     1)}
           </div>`;

  html += dailyInner(d);

  for (const v of ['flow_gpm', 'total_gal', 'flow_gph']) {
    const key = d.slug + '|' + v;
    if (openCharts.has(key)) html += `<div class="flowchart">${chartInner(key)}</div>`;
  }

  const extra = d.variables.filter(v => !FLOW_VARS.includes(v));
  if (extra.length) html += `<table>${varRows(d, extra)}</table>`;
  return html;
}

function flowBtns(d) {
  if (!(d.commandable && d.enabled)) return '';
  const busy = resetPending.has(d.slug);
  return `<div class="btns">
    <button class="reset"${busy ? ' disabled' : ''} onclick="resetTotal('${d.slug}')">
      ${busy ? 'RESETTING…' : 'RESET TOTAL'}</button></div>`;
}

function render(devices) {
  const grid = document.getElementById('grid');

  // Canvases are about to be thrown away — drop their Chart instances first.
  for (const e of openCharts.values()) { if (e.chart) { e.chart.destroy(); e.chart = null; } }
  for (const e of dailyCharts.values()) { if (e.chart) { e.chart.destroy(); e.chart = null; } }

  grid.innerHTML = '';
  if (!devices.length) {
    grid.innerHTML = '<div class="empty-note">No devices assigned to your account yet.</div>';
    return;
  }

  for (const d of devices) {
    const flow = isFlowMeter(d);
    const th   = gaugeThreshold(d);
    const al   = d.alarm || null;                 // ok/warn/alarm, decided server-side

    // The flow card's own low-flow alarm and a tripped threshold both light the
    // card the same way — one red glow, one meaning. Warn is the softer yellow
    // tint, and never shown on top of an alarm.
    const lowFlow = flow && Number(d.values.low_flow_alarm) === 1;
    const alarm   = lowFlow || (al && al.state === 'alarm');
    const warn    = !alarm && al && al.state === 'warn';
    const gauge   = !flow && th && th.enabled && d.enabled;

    const card = document.createElement('div');
    card.className = 'card' + (d.state === 'template' ? ' template' : '')
                            + (flow ? ' flowcard' : '') + (gauge ? ' gaugecard' : '')
                            + (alarm ? ' alarming' : '') + (warn ? ' warning' : '');

    const label = { template:'TEMPLATE — NOT PROGRAMMED', waiting:'WAITING FOR DATA',
                    live:'LIVE', stale:'STALE — CHECK BOARD' }[d.state];

    // A gauged variable is shown on the arc, so it is dropped from the table
    // underneath rather than printed twice. Everything else the device reports
    // still gets its row.
    let body;
    if (flow)       body = flowBody(d, lowFlow);
    else if (gauge) body = gaugeBlock(d, th, al) + tableFor(d, d.variables.filter(v => v !== th.variable));
    else            body = tableFor(d, d.variables);

    card.innerHTML = `
      <h2><span class="dot ${d.state}"></span>${d.name}<span class="badge">${label}</span></h2>
      <div class="notes">${d.notes}</div>
      ${body}
      <div class="seen">last seen: ${d.enabled ? ago(d.last_seen) : 'template shell'}</div>
      ${flow ? flowBtns(d) : genericBtns(d)}
      ${th ? thEditor(d, th) : ''}`;
    grid.appendChild(card);
  }

  grid.querySelectorAll('tr.varrow.tappable, .flowmain.tappable, .stat.tappable, .gauge.tappable')
      .forEach(el => el.addEventListener('click',
        () => toggleChart(el.dataset.slug, el.dataset.var)));

  // Hold the editor's open flag and its half-typed contents across the next
  // re-render, which is at most ten seconds away.
  grid.querySelectorAll('.thedit').forEach(el => el.addEventListener('toggle', () => {
    const e = thEditors.get(el.dataset.slug);
    if (e) e.open = el.open;
  }));
  grid.querySelectorAll('.thedit input').forEach(el => el.addEventListener('input', () => {
    const e = thEditors.get(el.dataset.slug);
    if (e) e.draft[el.dataset.col] = el.type === 'checkbox' ? el.checked : el.value;
  }));

  // Redraw any charts that were open, from data already fetched.
  for (const [key, e] of openCharts) {
    if (!e.loading && e.points && e.points.length) drawChart(key, key.split('|')[1]);
  }
  for (const d of devices) if (isFlowMeter(d)) drawDaily(d.slug);
}

async function refresh() {
  const r = await fetch('?data=1');
  const devices = await r.json();

  // Flow cards need Chart.js up front (their usage chart is always on screen,
  // not tap-to-open) and a fresh usage series. Both are awaited before painting
  // so a refresh is one clean render, not a flash of placeholder. Every one of
  // these settles even on failure, so the dashboard still paints if the CDN or
  // daily.php is unreachable.
  const meters = devices.filter(isFlowMeter);
  if (meters.length) {
    await Promise.allSettled(
      [loadChartLib().catch(() => {})].concat(meters.map(d => fetchDaily(d.slug))));
  }

  lastDevices = devices;
  render(lastDevices);
}

refresh();
setInterval(refresh, 10000);
</script>
</body>
</html>
