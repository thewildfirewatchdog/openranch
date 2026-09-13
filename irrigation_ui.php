<?php
// OpenRanch — shared chrome for the irrigation pages (zones, programs, rules).
require_once __DIR__ . '/nav.php';
// Same harvest palette as the dashboard, laid out mobile-first: one column by
// default, widening only where there is room.

function irr_head($title, $active = '') {
  $GLOBALS['irr_active_page'] = $active;
  $site = defined('SITE_NAME') ? SITE_NAME : 'OpenRanch';
  $tabs = ['index.php' => 'Dashboard', 'graphs.php' => 'Graphs', 'zones.php' => 'Zones',
           'programs.php' => 'Programs', 'rules.php' => 'Automations'];
  ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($site) ?> &mdash; <?= htmlspecialchars($title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include __DIR__ . '/pwa_head.php'; ?>
<style>
  :root { --bg:#fbf7ee; --card:#fff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --green:#3f6212; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh;
         padding:14px 14px calc(76px + env(safe-area-inset-bottom)); }
  header { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px; margin-bottom:14px; }
  h1 { font-size:20px; } h1 span { color:var(--accent-ink); }
  nav { display:flex; flex-wrap:wrap; gap:6px; margin-left:auto; }
  nav a { font-size:12px; color:var(--accent-ink); text-decoration:none; padding:5px 10px;
          border:1px solid var(--line); border-radius:7px; background:var(--card); }
  nav a.on { background:var(--accent); color:#3a2205; font-weight:700; border-color:var(--accent); }
  .wrap { max-width:900px; margin:0 auto; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px;
          padding:14px; margin-bottom:12px; box-shadow:0 4px 14px rgba(43,42,34,.05); }
  .card h2 { font-size:14px; margin-bottom:10px; }
  .card h2 small { font-weight:400; color:var(--dim); font-size:11px; }
  label { display:block; font-size:11px; color:var(--dim); letter-spacing:.05em;
          text-transform:uppercase; margin:10px 0 4px; }
  input, select, textarea { width:100%; padding:9px 10px; background:var(--bg); color:var(--text);
          border:1px solid var(--line); border-radius:8px; font-family:inherit; font-size:14px; }
  input:focus, select:focus { outline:none; border-color:var(--accent); }
  .row { display:grid; gap:10px; grid-template-columns:1fr; }
  @media (min-width:620px) { .row.two { grid-template-columns:1fr 1fr; }
                             .row.three { grid-template-columns:1fr 1fr 1fr; } }
  button, .btn { display:inline-block; padding:9px 14px; border:0; border-radius:8px;
          background:var(--accent); color:#3a2205; font-family:inherit; font-weight:700;
          font-size:13px; cursor:pointer; text-decoration:none; text-align:center; }
  button.ghost, .btn.ghost { background:var(--bg); color:var(--text); border:1px solid var(--line); font-weight:500; }
  button.danger { background:rgba(179,38,30,.12); color:var(--red); border:1px solid rgba(179,38,30,.4); }
  .actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.06em;
       color:var(--dim); padding:6px 6px 6px 0; border-bottom:1px solid var(--line); }
  td { padding:8px 6px 8px 0; border-bottom:1px solid var(--line); vertical-align:top; }
  .msg { padding:9px 12px; border-radius:8px; font-size:13px; margin-bottom:12px; }
  .msg.good { background:rgba(63,98,18,.10); border:1px solid rgba(63,98,18,.45); color:var(--green); }
  .msg.bad  { background:rgba(179,38,30,.10); border:1px solid rgba(179,38,30,.45); color:var(--red); }
  .pill { display:inline-block; font-size:10px; padding:2px 7px; border-radius:20px;
          background:var(--bg); border:1px solid var(--line); color:var(--dim); }
  .pill.on { background:rgba(63,98,18,.12); color:var(--green); border-color:rgba(63,98,18,.35); }
  .pill.run { background:var(--accent); color:#3a2205; border-color:var(--accent); font-weight:700; }
  .mono { font-family:'JetBrains Mono',monospace; }
  .empty { color:var(--dim); font-size:13px; padding:8px 0; }
  .bars { display:flex; align-items:flex-end; gap:4px; height:80px; margin-top:8px; }
  .bars div { flex:1; background:var(--accent); border-radius:3px 3px 0 0; min-height:2px; position:relative; }
  .bars div span { position:absolute; bottom:-16px; left:0; right:0; text-align:center;
                   font-size:9px; color:var(--dim); }
  .chartwrap { padding-bottom:20px; }
</style>
</head>
<body>
<div class="wrap">
<header>
  <h1>Open<span>Ranch</span></h1>
  <nav>
    <?php foreach ($tabs as $href => $label): ?>
      <a href="<?= $href ?>"<?= $active === $href ? ' class="on"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<?php
}

function irr_foot() {
  echo "</div>\n";
  // Map the top-nav key onto the bottom-nav key; they are the same pages.
  $map = ['zones.php' => 'sensors', 'programs.php' => 'programs',
          'rules.php' => 'rules',   'more.php' => 'more',
          'graphs.php' => 'graphs'];
  or_bottom_nav($map[$GLOBALS['irr_active_page'] ?? ''] ?? '');
  echo "</body>\n</html>\n";
}

function irr_msg() {
  if (!empty($_GET['ok']))  echo '<div class="msg good">' . htmlspecialchars($_GET['ok']) . '</div>';
  if (!empty($_GET['err'])) echo '<div class="msg bad">'  . htmlspecialchars($_GET['err']) . '</div>';
}

function irr_back($page, $ok = null, $err = null) {
  $q = $ok !== null ? '?ok=' . rawurlencode($ok) : ($err !== null ? '?err=' . rawurlencode($err) : '');
  header('Location: ' . $page . $q);
  exit;
}

// Devices this customer may point a zone or rule at.
function irr_device_options(PDO $db, $cid, $commandableOnly = false) {
  $sql = 'SELECT id, name, slug, commandable FROM devices WHERE customer_id = ?';
  if ($commandableOnly) $sql .= ' AND commandable = 1';
  $sql .= ' ORDER BY name';
  $st = $db->prepare($sql); $st->execute([$cid]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function irr_select($name, array $opts, $current, $blank = null, $attrs = '') {
  $h = '<select name="' . htmlspecialchars($name) . '" ' . $attrs . '>';
  if ($blank !== null) $h .= '<option value="">' . htmlspecialchars($blank) . '</option>';
  foreach ($opts as $val => $label) {
    $sel = ((string)$val === (string)$current) ? ' selected' : '';
    $h .= '<option value="' . htmlspecialchars((string)$val) . '"' . $sel . '>'
        . htmlspecialchars((string)$label) . '</option>';
  }
  return $h . '</select>';
}
