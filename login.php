<?php
// OpenRanch Dashboard v2 — customer login
// Customers sign themselves up in signup.php; admin.php can still create one.
// On success the session holds customer_id; index.php and cmd.php read it.

require 'config.php';
or_session_start();

// Already logged in? Straight to the dashboard.
if (current_customer()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  $stmt = db()->prepare('SELECT id, password_hash FROM customers WHERE email = ?');
  $stmt->execute([$email]);
  $c = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($c && password_verify($pass, $c['password_hash'])) {
    session_regenerate_id(true);          // no fixation
    $_SESSION['customer_id'] = (int)$c['id'];
    header('Location: index.php'); exit;
  }
  // Same message either way — don't reveal which emails exist.
  $error = 'Wrong email or password.';
  usleep(400000);                          // blunt brute-forcing a little
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenRanch — Sign in</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<?php include 'pwa_head.php'; ?>
<style>
  :root { --bg:#fbf7ee; --card:#ffffff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --red:#b3261e; --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--grad); background-attachment:fixed; color:var(--text); font-family:'DM Sans',system-ui,sans-serif;
         min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
  .box { background:var(--card); border:1px solid var(--line); border-radius:12px;
         box-shadow:0 6px 24px rgba(43,42,34,.08);
         padding:28px; width:100%; max-width:360px; }
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
  button:active { transform:scale(.99); }
  .err { margin-top:16px; padding:9px 12px; border-radius:8px; font-size:13px;
         background:rgba(179,38,30,.10); border:1px solid rgba(179,38,30,.45); color:var(--red); }
  .foot { margin-top:18px; font-size:11px; color:var(--dim); text-align:center; }
  .foot a { color:var(--dim); }
</style>
</head>
<body>
<form class="box" method="post">
  <h1>Open<span>Ranch</span></h1>
  <div class="sub">Sign in to see your devices</div>

  <label for="email">Email</label>
  <input id="email" name="email" type="email" autocomplete="username" required autofocus
         value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">

  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>

  <button type="submit">Sign in</button>

  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="foot">Need an account? <a href="signup.php">Create one</a>.<br>
    <a href="index.php">&larr; Back to public dashboard</a></div>
</form>
<div id="installbar"></div>
<script src="/pwa.js" defer></script>
</body>
</html>
