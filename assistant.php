<?php
// OpenRanch — Assistant settings: the API token the bot uses, the code that
// links a Telegram chat to this account, and the two per-account toggles.
require __DIR__ . '/config.php';
require_once __DIR__ . '/irrigation_lib.php';
require_once __DIR__ . '/irrigation_ui.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

// Link codes use the same unambiguous alphabet as device claim codes -- someone
// is reading this off a screen and typing it into a phone.
function assistant_code() {
  $a = CLAIM_ALPHABET; $out = '';
  for ($i = 0; $i < 6; $i++) $out .= $a[random_int(0, strlen($a) - 1)];
  return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'token') {
    // 40 hex characters. Regenerating invalidates the old one immediately,
    // which is the point: it is how you revoke a bot that has gone astray.
    $db->prepare('UPDATE customers SET api_token = ? WHERE id = ?')
       ->execute([bin2hex(random_bytes(20)), $cid]);
    irr_back('assistant.php', 'New API token generated. The old one stopped working.');
  }

  if ($act === 'code') {
    for ($try = 0; $try < 10; $try++) {
      $code = assistant_code();
      try {
        $db->prepare('UPDATE customers SET link_code = ?, link_expires = (NOW() + INTERVAL 15 MINUTE)
                      WHERE id = ?')->execute([$code, $cid]);
        irr_back('assistant.php', 'Link code ready. It expires in 15 minutes.');
      } catch (PDOException $e) { /* collision, try again */ }
    }
    irr_back('assistant.php', null, 'Could not allocate a link code. Try again.');
  }

  if ($act === 'unlink') {
    $db->prepare('UPDATE customers SET telegram_chat_id = NULL WHERE id = ?')->execute([$cid]);
    irr_back('assistant.php', 'Telegram chat unlinked.');
  }

  if ($act === 'prefs') {
    $db->prepare('UPDATE customers SET bot_voice = ?, bot_briefing = ? WHERE id = ?')
       ->execute([!empty($_POST['bot_voice']) ? 1 : 0,
                  !empty($_POST['bot_briefing']) ? 1 : 0, $cid]);
    irr_back('assistant.php', 'Preferences saved.');
  }
}

$q = $db->prepare('SELECT api_token, telegram_chat_id, link_code, link_expires,
                          bot_voice, bot_briefing FROM customers WHERE id = ?');
$q->execute([$cid]);
$a = $q->fetch(PDO::FETCH_ASSOC);
$codeLive = $a['link_code'] && (!$a['link_expires'] || strtotime($a['link_expires']) > time());

irr_head('Assistant', '');
irr_msg();
?>
<div class="card">
  <h2>Telegram assistant <small>&mdash; ask about the ranch in plain English</small></h2>
  <?php if ($a['telegram_chat_id']): ?>
    <p style="font-size:13px">This account is linked to a Telegram chat.
      <span class="pill on">linked</span></p>
    <form method="post" class="actions">
      <input type="hidden" name="act" value="unlink">
      <button class="danger" type="submit">Unlink this chat</button></form>
  <?php else: ?>
    <?php if ($codeLive): ?>
      <!-- Never show a "/link <code>" placeholder here. People send it exactly as
           written; <code> normalises to CODE, which is not 6 characters, and the
           dashboard rejects it. Print the whole command with the real code in it
           so there is nothing left to substitute. -->
      <p style="font-size:13px;color:var(--dim)">
        Open Telegram, start a chat with the OpenRanch bot, and send it this,
        exactly as it appears:</p>
      <div style="font-family:'JetBrains Mono',monospace; font-size:30px; letter-spacing:.12em;
                  text-align:center; padding:16px; background:var(--bg); border:1px solid var(--line);
                  border-radius:10px; margin:12px 0">/link <?= htmlspecialchars($a['link_code']) ?></div>
      <div style="font-size:11px;color:var(--dim);text-align:center">
        Expires <?= htmlspecialchars($a['link_expires']) ?> UTC. One use only.</div>
    <?php else: ?>
      <p style="font-size:13px;color:var(--dim)">
        Open Telegram and start a chat with the OpenRanch bot, then get a code
        below &mdash; it comes with the exact message to send.</p>
    <?php endif; ?>
    <form method="post" class="actions">
      <input type="hidden" name="act" value="code">
      <button type="submit"><?= $codeLive ? 'New code' : 'Get a link code' ?></button></form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Preferences</h2>
  <form method="post">
    <input type="hidden" name="act" value="prefs">
    <div class="actions">
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="bot_voice" value="1" style="width:auto"
               <?= $a['bot_voice'] ? 'checked' : '' ?>> reply with a voice note as well as text</label>
    </div>
    <div class="actions">
      <label style="text-transform:none;letter-spacing:0;margin:0">
        <input type="checkbox" name="bot_briefing" value="1" style="width:auto"
               <?= $a['bot_briefing'] ? 'checked' : '' ?>> send me a 07:00 morning briefing</label>
    </div>
    <div class="actions"><button type="submit">Save preferences</button></div>
  </form>
  <p style="font-size:11px;color:var(--dim);margin-top:8px">
    You can also say <b>/voice on</b> or <b>/voice off</b> to the bot at any time.</p>
</div>

<div class="card">
  <h2>API token <small>&mdash; for the assistant, or your own tools</small></h2>
  <?php if ($a['api_token']): ?>
    <div class="mono" style="font-size:12px; word-break:break-all; padding:10px;
         background:var(--bg); border:1px solid var(--line); border-radius:8px">
      <?= htmlspecialchars($a['api_token']) ?></div>
  <?php else: ?>
    <div class="empty">No token yet.</div>
  <?php endif; ?>
  <p style="font-size:11px;color:var(--dim);margin-top:8px">
    Read and control only this account's devices, at
    <span class="mono"><?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/api/v1/') ?></span>.
    Send it as <span class="mono">Authorization: Bearer &lt;token&gt;</span>. Treat it like a password:
    anyone holding it can water your zones.</p>
  <form method="post" class="actions">
    <input type="hidden" name="act" value="token">
    <button type="submit" onclick="return confirm('Generate a new token? The current one stops working immediately.')">
      <?= $a['api_token'] ? 'Regenerate token' : 'Generate token' ?></button></form>
</div>
<?php irr_foot();
