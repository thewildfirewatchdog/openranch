<?php
// OpenRanch — irrigation panel for the top of the dashboard.
// Included by index.php for a signed-in customer who has at least one zone.
// Server-rendered and self-contained: no dependency on the dashboard's JS.

$irrZones = irr_zones($db_irr = db(), $irrCid = (int)$customer['id']);
if ($irrZones):
  $irrS    = irr_settings($db_irr, $irrCid);
  $irrRuns = irr_running($db_irr, $irrCid);
  $runByZone = []; foreach ($irrRuns as $r) $runByZone[$r['zone_id']] = $r;

  $irrLoc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(irr_tz());
  $pq = $db_irr->prepare('SELECT * FROM irr_programs WHERE customer_id = ? AND enabled = 1');
  $pq->execute([$irrCid]);
  $next = null; $nextName = '';
  foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $n = irr_next_occurrence($p, $irrLoc);
    if ($n && ($next === null || $n < $next)) { $next = $n; $nextName = $p['name']; }
  }

  $tq = $db_irr->prepare("SELECT COUNT(*) c, COALESCE(SUM(gallons),0) g FROM irr_runs
                           WHERE customer_id = ? AND started >= (NOW() - INTERVAL 24 HOUR)
                             AND status IN ('done','stopped')");
  $tq->execute([$irrCid]);
  $today = $tq->fetch(PDO::FETCH_ASSOC);

  $held = !empty($irrS['rain_delay_until']) &&
          (new DateTimeImmutable($irrS['rain_delay_until'])) > new DateTimeImmutable('now');
?>
<style>
  .irr { background:var(--card); border:1px solid var(--line); border-radius:12px;
         padding:14px; margin-bottom:14px; }
  .irr h2 { font-size:14px; margin-bottom:4px; }
  .irr .sum { font-size:12px; color:var(--dim); margin-bottom:10px; }
  .irr .zrow { display:flex; flex-wrap:wrap; align-items:center; gap:8px;
               padding:8px 0; border-top:1px solid var(--line); }
  .irr .zname { font-weight:500; font-size:13px; flex:1 1 140px; }
  .irr form { display:flex; gap:6px; align-items:center; margin:0; }
  .irr input[type=number] { width:68px; padding:6px 8px; background:var(--bg); color:var(--text);
        border:1px solid var(--line); border-radius:7px; font-family:inherit; font-size:13px; }
  .irr button { padding:6px 12px; border:0; border-radius:7px; background:var(--accent);
        color:#3a2205; font-family:inherit; font-weight:700; font-size:12px; cursor:pointer; }
  .irr button.ghost { background:var(--bg); color:var(--text); border:1px solid var(--line); font-weight:500; }
  .irr .tag { font-size:10px; padding:2px 7px; border-radius:20px; background:var(--bg);
              border:1px solid var(--line); color:var(--dim); }
  .irr .tag.run { background:var(--accent); color:#3a2205; border-color:var(--accent); font-weight:700; }
  .irr .tag.held { background:rgba(179,38,30,.10); color:var(--red); border-color:rgba(179,38,30,.4); }
  .irr .links { margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; }
  .irr .links a { font-size:12px; color:var(--accent-ink); text-decoration:none;
                  border:1px solid var(--line); border-radius:7px; padding:5px 10px; }
</style>
<div class="irr">
  <h2>Irrigation</h2>
  <div class="sum">
    <?php if ($held): ?>
      <span class="tag held">held until <?= htmlspecialchars($irrS['rain_delay_until']) ?> UTC</span>
    <?php elseif ($irrRuns): ?>
      <span class="tag run"><?= count($irrRuns) ?> zone<?= count($irrRuns) === 1 ? '' : 's' ?> running</span>
    <?php elseif ($next): ?>
      next: <b><?= htmlspecialchars($nextName) ?></b> at <?= $next->format('D H:i') ?>
    <?php else: ?>
      no program scheduled
    <?php endif; ?>
    &middot; last 24h: <?= (int)$today['c'] ?> run<?= (int)$today['c'] === 1 ? '' : 's' ?><?php
      if ((float)$today['g'] > 0) echo ', ' . round((float)$today['g'], 1) . ' gal'; ?>
  </div>

  <?php foreach ($irrZones as $z): $run = $runByZone[$z['id']] ?? null; ?>
  <div class="zrow">
    <div class="zname"><?= htmlspecialchars($z['name']) ?>
      <?php if ($z['is_master']): ?><span class="tag">master</span><?php endif; ?>
      <?php if (!$z['enabled']): ?><span class="tag">off</span><?php endif; ?>
      <?php if ($run): ?><span class="tag run">until <?= substr($run['ends_at'], 11, 5) ?> UTC</span><?php endif; ?>
    </div>
    <?php if ($run): ?>
      <form method="post" action="irrigation_run.php">
        <input type="hidden" name="act" value="stop"><input type="hidden" name="zone_id" value="<?= (int)$z['id'] ?>">
        <input type="hidden" name="back" value="index.php">
        <button class="ghost" type="submit">Stop</button></form>
    <?php elseif ($z['enabled'] && !$z['is_master']): ?>
      <form method="post" action="irrigation_run.php">
        <input type="hidden" name="act" value="run"><input type="hidden" name="zone_id" value="<?= (int)$z['id'] ?>">
        <input type="hidden" name="back" value="index.php">
        <input type="number" name="minutes" min="0.5" step="0.5" value="10" aria-label="minutes">
        <button type="submit">Run now</button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="links">
    <?php if ($irrRuns): ?>
      <form method="post" action="irrigation_run.php">
        <input type="hidden" name="act" value="stopall"><input type="hidden" name="back" value="index.php">
        <button class="ghost" type="submit">Stop everything</button></form>
    <?php endif; ?>
    <?php if ($held): ?>
      <form method="post" action="irrigation_run.php">
        <input type="hidden" name="act" value="undelay"><input type="hidden" name="back" value="index.php">
        <button class="ghost" type="submit">Clear hold</button></form>
    <?php else: ?>
      <form method="post" action="irrigation_run.php">
        <input type="hidden" name="act" value="delay"><input type="hidden" name="back" value="index.php">
        <input type="number" name="hours" min="1" max="240" value="24" aria-label="hours">
        <button class="ghost" type="submit">Hold programs</button></form>
    <?php endif; ?>
    <a href="zones.php">Zones</a><a href="programs.php">Programs</a><a href="rules.php">Automations</a>
  </div>
</div>
<?php endif; ?>
