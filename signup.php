<?php
// OpenRanch — customer self-signup
//
// Creates an account with an email and a password, signs it in, and sends it
// straight to claim.php, which is the only thing a new account can usefully do.
//
// There is no email verification: an address is a login name here, nothing is
// sent to it except outage alerts the customer opts into by owning a device.
// Accounts start on the free plan and are capped by FREE_DEVICE_LIMIT, so an
// unverified signup cannot consume anything.

require 'config.php';
require_once 'claim_lib.php';
or_boot_session();

if (current_customer()) { header('Location: index.php'); exit; }

const SIGNUP_MIN_PASSWORD = 8;

// This PHP build has no mbstring (see the same note in register.php), and a
// name can legitimately contain multibyte characters, so cut on character
// boundaries with a UTF-8-aware regex. substr() would split one and leave
// invalid UTF-8 in the column.
function signup_cut($s, $max) {
  return preg_replace('/^(.{0,' . (int)$max . '}).*$/us', '$1', $s);
}

$error = '';
$email = '';
$name  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $name  = trim((string)($_POST['name'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    $error = 'That does not look like an email address.';
  } elseif (strlen($pass) < SIGNUP_MIN_PASSWORD) {
    $error = 'Pick a password of at least ' . SIGNUP_MIN_PASSWORD . ' characters.';
  } else {
    // Let the UNIQUE index decide, rather than checking first and inserting
    // after: two signups racing on the same address would both pass the check.
    try {
      db()->prepare('INSERT INTO customers (email, password_hash, name) VALUES (?, ?, ?)')
          ->execute([$email, password_hash($pass, PASSWORD_DEFAULT),
                     signup_cut($name, 100)]);

      $id = (int)db()->lastInsertId();
      session_regenerate_id(true);        // no fixation, same as login.php
      $_SESSION['customer_id'] = $id;
      header('Location: claim.php'); exit;

    } catch (PDOException $e) {
      if (($e->errorInfo[1] ?? 0) == 1062) {
        $error = 'That email already has an account. Sign in instead.';
      } else {
        $error = 'Could not create the account. Try again.';
      }
      usleep(400000);
    }
  }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'OpenRanch') ?> &mdash; Create an account</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include 'pwa_head.php'; ?>
<style>
  :root { --bg:#fbf7ee; --card:#ffffff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text);
         font-family:'DM Sans',system-ui,sans-serif; min-height:100vh; display:flex;
         align-items:center; justify-content:center; padding:20px; }
  .box { background:var(--card); border:1px solid var(--line); border-radius:12px;
         box-shadow:0 6px 24px rgba(43,42,34,.08); padding:28px; width:100%; max-width:360px; }
  h1 { font-size:22px; margin-bottom:4px; } h1 span { color:var(--accent-ink); }
  .sub { color:var(--dim); font-size:12px; margin-bottom:20px; }
  label { display:block; font-size:11px; color:var(--dim); letter-spacing:.06em;
          text-transform:uppercase; margin:14px 0 6px; }
  input { width:100%; padding:10px 12px; background:var(--bg); color:var(--text);
          border:1px solid var(--line); border-radius:8px; font-family:inherit; font-size:14px; }
  input:focus { outline:none; border-color:var(--accent); }
  button { width:100%; margin-top:20px; padding:11px; border:0; border-radius:8px;
           background:var(--accent); color:#3a2205; font-family:inherit; font-weight:700;
           font-size:14px; cursor:pointer; }
  .hint { font-size:11px; color:var(--dim); margin-top:6px; }
  .err { margin-top:16px; padding:9px 12px; border-radius:8px; font-size:13px;
         background:rgba(179,38,30,.10); border:1px solid rgba(179,38,30,.45); color:var(--red); }
  .foot { margin-top:18px; font-size:11px; color:var(--dim); text-align:center; }
  .foot a { color:var(--dim); }
</style>
</head>
<body>
<form class="box" method="post">
  <h1>Create an <span>account</span></h1>
  <div class="sub">Then add your first device with its claim code.</div>

  <label for="email">Email</label>
  <input id="email" name="email" type="email" autocomplete="username" required autofocus
         value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">

  <label for="name">Name <span style="text-transform:none">(optional)</span></label>
  <input id="name" name="name" type="text" autocomplete="name"
         value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">

  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="new-password" required
         minlength="<?= SIGNUP_MIN_PASSWORD ?>">
  <div class="hint">At least <?= SIGNUP_MIN_PASSWORD ?> characters.</div>

  <button type="submit">Create account</button>

  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="foot">Already have one? <a href="login.php">Sign in</a><br>
    <a href="index.php">&larr; Back to public dashboard</a></div>
</form>
</body>
</html>
