<?php
// OpenRanch Dashboard v1 — admin
// Enable a template when its board is programmed, see tokens, regenerate tokens.
// Access: admin.php?pin=XXXX
//
// v2: also manages customers — create an account with a temp password, and
// assign/unassign devices to it. The PIN gate below is unchanged; admin.php
// remains super-admin and always sees every device regardless of assignment.

require 'config.php';

if (($_REQUEST['pin'] ?? '') !== ADMIN_PIN) {
  http_response_code(401);
  echo '<form><input name="pin" placeholder="PIN" type="password"><button>Go</button></form>';
  exit;
}
$pin = ADMIN_PIN;

function admin_redirect($msg = '') {
  $q = 'admin.php?pin=' . urlencode(ADMIN_PIN);
  if ($msg !== '') $q .= '&msg=' . urlencode($msg);
  header("Location: $q");
  exit;
}

// Actions
if (isset($_GET['toggle'])) {
  db()->prepare('UPDATE devices SET enabled = 1 - enabled WHERE slug = ?')->execute([$_GET['toggle']]);
  header("Location: admin.php?pin=$pin"); exit;
}
if (isset($_GET['newtoken'])) {
  db()->prepare('UPDATE devices SET token = MD5(RAND()) WHERE slug = ?')->execute([$_GET['newtoken']]);
  header("Location: admin.php?pin=$pin"); exit;
}

// ---- v2 actions: customers ----
if (isset($_POST['new_email'])) {
  $email = trim($_POST['new_email']);
  $name  = trim($_POST['new_name'] ?? '');
  $pass  = $_POST['new_password'] ?? '';
  if ($email === '' || $pass === '') {
    admin_redirect('Email and temp password are both required.');
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_redirect('That does not look like an email address.');
  } elseif (strlen($pass) < 8) {
    admin_redirect('Temp password must be at least 8 characters.');
  }
  try {
    db()->prepare('INSERT INTO customers (email, password_hash, name) VALUES (?, ?, ?)')
        ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name]);
    admin_redirect("Customer $email created.");
  } catch (PDOException $e) {
    admin_redirect(($e->errorInfo[1] ?? 0) == 1062
      ? "A customer with the email $email already exists."
      : 'Could not create customer.');
  }
}

if (isset($_POST['reset_id'])) {
  $pass = $_POST['reset_password'] ?? '';
  if (strlen($pass) < 8) admin_redirect('New password must be at least 8 characters.');
  db()->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')
      ->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$_POST['reset_id']]);
  admin_redirect('Password reset.');
}

if (isset($_POST['delete_id'])) {
  $cid = (int)$_POST['delete_id'];
  // Devices fall back to unassigned rather than disappearing.
  db()->prepare('UPDATE devices SET customer_id = NULL WHERE customer_id = ?')->execute([$cid]);
  db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
  admin_redirect('Customer deleted; their devices are now unassigned.');
}

if (isset($_POST['assign_slug'])) {
  $raw = $_POST['customer_id'] ?? '';
  $cid = ($raw === '') ? null : (int)$raw;
  if ($cid !== null) {
    $chk = db()->prepare('SELECT id FROM customers WHERE id = ?');
    $chk->execute([$cid]);
    if (!$chk->fetch()) admin_redirect('No such customer.');
  }
  db()->prepare('UPDATE devices SET customer_id = ? WHERE slug = ?')
      ->execute([$cid, $_POST['assign_slug']]);
  admin_redirect('Device assignment updated.');
}

$devices   = db()->query('SELECT * FROM devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$customers = db()->query('SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id) devcount
                          FROM customers c ORDER BY c.id')->fetchAll(PDO::FETCH_ASSOC);
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenRanch — Device Admin</title>
<style>
  /* OpenRanch harvest palette — same tokens as the dashboard. */
  :root { --bg:#fbf7ee; --card:#ffffff; --line:#e8dfc9; --text:#2b2a22; --dim:#6b6a5a;
          --green:#5a7d3a; --green-ink:#4e7a34; --red:#b3261e;
          --accent:#d98a2b; --accent-ink:#9a5410;
          --grad:linear-gradient(135deg,#f4e7c3 0%,#dbe8c9 55%,#cfe4ef 100%); }
  body { background:var(--bg); color:var(--text); font-family:'DM Sans',system-ui,sans-serif; padding:24px; }
  h1 { font-size:20px; background:var(--grad); border:1px solid var(--line); border-radius:12px;
       padding:14px 16px; margin-bottom:16px; } table { border-collapse:collapse; width:100%; }
  td, th { padding:8px 10px; border-bottom:1px solid var(--line); text-align:left; font-size:14px; }
  code { font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--green-ink); }
  a.btn { color:#ffffff; background:#6b6a5a; padding:4px 10px; border-radius:6px; text-decoration:none; font-size:12px; }
  a.on { background:var(--green); } a.off { background:#6b6a5a; }
  .pill { font-size:11px; padding:2px 8px; border-radius:10px; }
  .en { background:var(--green); color:#ffffff; } .dis { background:#ece5d2; color:#5f5e4f; }
  h2 { font-size:16px; margin:34px 0 4px; color:var(--accent-ink); }
  select, input[type=text], input[type=email], input[type=password] {
    background:var(--card); color:var(--text); border:1px solid var(--line); border-radius:6px;
    padding:5px 8px; font-family:inherit; font-size:12px; }
  button.act { background:var(--accent); color:#3a2205; border:0; border-radius:6px;
    padding:6px 12px; font-family:inherit; font-weight:700; font-size:12px; cursor:pointer; }
  button.small { background:#ece5d2; color:var(--text); border:0; border-radius:6px;
    padding:4px 9px; font-family:inherit; font-size:11px; cursor:pointer; }
  .msg { background:var(--card); border:1px solid var(--accent); border-radius:8px;
         padding:9px 13px; margin:14px 0; font-size:13px; }
  .hint { color:var(--dim); font-size:12px; margin-bottom:10px; }
  form.inline { display:inline; margin:0; }
  .provbox { background:var(--card); border:1px solid var(--line); border-left:3px solid var(--accent);
             border-radius:8px; padding:12px 14px; margin:16px 0 20px; }
  .provbox b { font-size:13px; display:block; margin-bottom:8px; }
  .provkey { display:inline-block; background:var(--bg); border:1px solid var(--line);
             border-radius:6px; padding:6px 10px; color:var(--accent-ink); font-size:13px;
             letter-spacing:.04em; user-select:all; }
  .provhint { display:block; color:var(--dim); font-size:11px; margin-top:8px; line-height:1.5; }
  .provhint code { color:var(--dim); }
</style>
</head>
<body>
<h1>OpenRanch — Device Admin</h1>

<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="provbox">
  <b>Provisioning key — for auto-registering new sensors</b>
  <code class="provkey"><?= htmlspecialchars(PROVISION_KEY) ?></code>
  <span class="provhint">Flash this into a new board so it can POST to
    <code>register.php</code> with a <code>Provision-Key</code> header and claim its
    own slug and token. Registered devices always arrive disabled — enable them below
    before they can send data.</span>
</div>

<p style="color:#6b6a5a">Templates stay disabled (no data accepted) until you enable them here.</p>
<table>
<tr><th>Device</th><th>Status</th><th>MAC</th><th>Token</th><th>Variables</th><th>Customer</th><th></th></tr>
<?php foreach ($devices as $d): ?>
<tr>
  <td><b><?= htmlspecialchars($d['name']) ?></b><br><code><?= $d['slug'] ?></code></td>
  <td><span class="pill <?= $d['enabled'] ? 'en' : 'dis' ?>"><?= $d['enabled'] ? 'ENABLED' : 'TEMPLATE' ?></span></td>
  <td><?php if (!empty($d['mac'])): ?>
        <code style="color:#79c0ff"><?= htmlspecialchars($d['mac']) ?></code>
      <?php else: ?>
        <span style="color:#484f58">&mdash;</span>
      <?php endif; ?></td>
  <td><code><?= $d['token'] ?></code><br>
      <a class="btn" href="?pin=<?= $pin ?>&newtoken=<?= $d['slug'] ?>"
         onclick="return confirm('New token? The board firmware must be updated to match.')">new token</a></td>
  <td style="max-width:280px"><code style="color:#6b6a5a"><?= str_replace(',', ', ', $d['variables']) ?></code></td>
  <td>
    <form class="inline" method="post">
      <input type="hidden" name="pin" value="<?= $pin ?>">
      <input type="hidden" name="assign_slug" value="<?= htmlspecialchars($d['slug'], ENT_QUOTES) ?>">
      <select name="customer_id" onchange="this.form.submit()">
        <option value="" <?= $d['customer_id'] === null ? 'selected' : '' ?>>— unassigned (operator) —</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (int)$d['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['email']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </td>
  <td><a class="btn <?= $d['enabled'] ? 'off' : 'on' ?>" href="?pin=<?= $pin ?>&toggle=<?= $d['slug'] ?>">
      <?= $d['enabled'] ? 'Disable' : 'Enable' ?></a></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Customers</h2>
<div class="hint">Customers sign in at <code>login.php</code> and see only the devices assigned to them.
  Unassigned devices stay private to this admin page and the public dashboard.</div>

<table>
<tr><th>Email</th><th>Name</th><th>Devices</th><th>Created</th><th>Reset password</th><th></th></tr>
<?php if (!$customers): ?>
<tr><td colspan="6" style="color:#6b6a5a">No customers yet.</td></tr>
<?php endif; ?>
<?php foreach ($customers as $c): ?>
<tr>
  <td><code><?= htmlspecialchars($c['email']) ?></code></td>
  <td><?= htmlspecialchars($c['name']) ?></td>
  <td><?= $c['devcount'] ?></td>
  <td style="color:#6b6a5a"><?= $c['created'] ?></td>
  <td>
    <form class="inline" method="post">
      <input type="hidden" name="pin" value="<?= $pin ?>">
      <input type="hidden" name="reset_id" value="<?= $c['id'] ?>">
      <input type="password" name="reset_password" placeholder="new password" required>
      <button class="small" type="submit">Reset</button>
    </form>
  </td>
  <td>
    <form class="inline" method="post"
          onsubmit="return confirm('Delete <?= htmlspecialchars($c['email'], ENT_QUOTES) ?>? Their devices become unassigned.')">
      <input type="hidden" name="pin" value="<?= $pin ?>">
      <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
      <button class="small" type="submit">Delete</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table>

<h2>New customer</h2>
<form method="post">
  <input type="hidden" name="pin" value="<?= $pin ?>">
  <input type="email" name="new_email" placeholder="email" required>
  <input type="text" name="new_name" placeholder="name (optional)">
  <input type="password" name="new_password" placeholder="temp password (8+ chars)" required>
  <button class="act" type="submit">Create customer</button>
</form>

</body>
</html>
