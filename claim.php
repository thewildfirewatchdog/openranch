<?php
// OpenRanch — claim a device
//
// A board self-registers through register.php and is handed a single-use claim
// code, which its firmware displays. Whoever installed it signs in here, types
// the code, and the device becomes theirs: assigned to their account, named
// from the sensor type it reports, enabled, and the code cleared so it cannot
// be claimed twice.
//
// Claiming is what replaces an admin enabling the row by hand, so it is also
// the point where the free-tier limit is enforced.

require 'config.php';
require_once 'claim_lib.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }

$plan    = claim_plan(db(), $customer['id']);
$paid    = claim_plan_is_paid($plan);
$limit   = claim_limit();
$owned   = claim_device_count(db(), $customer['id']);
$atLimit = !$paid && $owned >= $limit;

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = claim_normalise($_POST['code'] ?? '');

  if (!claim_valid($code)) {
    // Deliberately not "no such code": at this point we have not looked, and
    // saying the format is wrong is both true and more useful.
    $error = 'That is not a valid claim code. Codes are '
           . CLAIM_LEN . ' characters, letters and digits only.';

  } else {
    $stmt = db()->prepare(
      'SELECT id, slug, name, variables, customer_id, ' . claim_mirror_col(db()) .
      '  FROM devices WHERE claim_code = ?');
    $stmt->execute([$code]);
    $dev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dev) {
      // A spent code is cleared to NULL, so "already claimed" and "never
      // existed" look identical from here. Say both rather than guess.
      $error = 'No device is waiting on that code. It may already have been '
             . 'claimed, or mistyped -- power-cycle the board to have it '
             . 'register again and show its code.';
    } elseif ($atLimit) {
      // Checked after the lookup, so a wrong code still gets a useful error
      // instead of an upgrade prompt -- but before the UPDATE, so a valid code
      // is never spent on a claim that is about to be refused.
      $error = 'limit';
    } elseif (!empty($dev['is_mirrored'])) {
      // Should be unreachable -- mirrored rows never get a code -- but a device
      // that cannot be controlled from here must never be handed to anyone.
      $error = 'That device is mirrored from another system and cannot be claimed here.';
    } elseif ($dev['customer_id'] !== null) {
      $error = 'That code has already been used. Each code claims one device once.';
    } else {
      $label = claim_type_label($dev['variables']);
      $name  = claim_unique_name(db(), $customer['id'], $label);

      // One statement, and it re-checks the two things that could have changed
      // since the SELECT: another session claiming the same code, and the code
      // being cleared. If that race is lost, 0 rows change and nothing is
      // silently taken from the winner.
      $upd = db()->prepare(
        'UPDATE devices
            SET customer_id = ?, name = ?, enabled = 1, claim_code = NULL
          WHERE id = ? AND customer_id IS NULL AND claim_code = ?');
      $upd->execute([$customer['id'], $name, $dev['id'], $code]);

      if ($upd->rowCount() === 1) {
        $ok    = $name;
        $owned = claim_device_count(db(), $customer['id']);
        $atLimit = !$paid && $owned >= $limit;
      } else {
        $error = 'That code was just used by someone else.';
      }
    }
  }
}

$contact = defined('ALERT_EMAIL') ? ALERT_EMAIL : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'OpenRanch') ?> &mdash; Add a device</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include 'pwa_head.php'; ?>
<style>
  :root { --bg:#fbf7ee; --card:#ffffff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --green:#3f6212; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh; display:flex;
         align-items:center; justify-content:center; padding:20px; }
  .box { background:var(--card); border:1px solid var(--line); border-radius:12px;
         box-shadow:0 6px 24px rgba(43,42,34,.08); padding:28px; width:100%; max-width:420px; }
  h1 { font-size:22px; margin-bottom:4px; } h1 span { color:var(--accent-ink); }
  .sub { color:var(--dim); font-size:12px; margin-bottom:20px; }
  label { display:block; font-size:11px; color:var(--dim); letter-spacing:.06em;
          text-transform:uppercase; margin:14px 0 6px; }
  input { width:100%; padding:12px; background:var(--bg); color:var(--text);
          border:1px solid var(--line); border-radius:8px; font-family:'JetBrains Mono',monospace;
          font-size:22px; letter-spacing:.28em; text-align:center; text-transform:uppercase; }
  input:focus { outline:none; border-color:var(--accent); }
  button { width:100%; margin-top:18px; padding:11px; border:0; border-radius:8px;
           background:var(--accent); color:#3a2205; font-family:inherit; font-weight:700;
           font-size:14px; cursor:pointer; }
  .msg { margin-top:16px; padding:10px 12px; border-radius:8px; font-size:13px; }
  .err { background:rgba(179,38,30,.10); border:1px solid rgba(179,38,30,.45); color:var(--red); }
  .good { background:rgba(63,98,18,.10); border:1px solid rgba(63,98,18,.45); color:var(--green); }
  .quota { margin-top:18px; font-size:12px; color:var(--dim); }
  .bar { height:6px; border-radius:3px; background:var(--bg); border:1px solid var(--line);
         margin-top:6px; overflow:hidden; }
  .bar i { display:block; height:100%; background:var(--accent); }
  .up { margin-top:16px; padding:14px; border-radius:8px; background:var(--bg);
        border:1px solid var(--line); }
  .up b { display:block; margin-bottom:4px; }
  .up p { font-size:12px; color:var(--dim); margin-bottom:10px; }
  .up a.cta { display:block; text-align:center; padding:10px; border-radius:8px;
              background:var(--accent); color:#3a2205; font-weight:700; font-size:13px;
              text-decoration:none; }
  .foot { margin-top:18px; font-size:11px; color:var(--dim); text-align:center; }
  .foot a { color:var(--dim); }
</style>
</head>
<body>
<form class="box" method="post">
  <h1>Add a <span>device</span></h1>
  <div class="sub">Type the code your board is showing.</div>

  <label for="code">Claim code</label>
  <input id="code" name="code" maxlength="16" autocomplete="off" autocapitalize="characters"
         spellcheck="false" required autofocus placeholder="ABC234">

  <button type="submit">Claim it</button>

  <?php if ($ok !== ''): ?>
    <div class="msg good">Claimed. Added to your dashboard as
      <b><?= htmlspecialchars($ok) ?></b> and switched on &mdash; readings appear
      as soon as the board sends them.</div>
  <?php elseif ($error === 'limit'): ?>
    <div class="msg err">You have used all <?= (int)$limit ?> devices on the free plan.</div>
  <?php elseif ($error !== ''): ?>
    <div class="msg err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="quota">
    <?php if ($paid): ?>
      <?= (int)$owned ?> device<?= $owned === 1 ? '' : 's' ?> &middot; no limit on your plan
    <?php else: ?>
      <?= (int)$owned ?> of <?= (int)$limit ?> free devices used
      <div class="bar"><i style="width:<?= $limit > 0 ? min(100, (int)round($owned / $limit * 100)) : 100 ?>%"></i></div>
    <?php endif; ?>
  </div>

  <?php if ($atLimit): ?>
    <div class="up">
      <b>Need more than <?= (int)$limit ?>?</b>
      <?php if (claim_stripe_ready()): ?>
        <p>Upgrade for unlimited devices on this account.</p>
        <a class="cta" href="checkout.php">Upgrade</a>
      <?php else: ?>
        <p>Paid plans are not self-serve yet &mdash; get in touch and we will
           raise the limit on your account.</p>
        <?php if ($contact !== ''): ?>
          <a class="cta" href="mailto:<?= htmlspecialchars($contact) ?>?subject=<?= rawurlencode('More devices on my OpenRanch account') ?>">Contact us</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="foot"><a href="index.php">&larr; Back to the dashboard</a></div>
</form>
</body>
</html>
