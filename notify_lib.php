<?php
// OpenRanch — notification wording.
//
// Every notice the system sends starts life as a template. If Claude is
// configured and the customer still has budget for the day, it is rewritten
// into one plain sentence with a little context; otherwise the template goes
// out unchanged. The template is always a complete, sendable message on its own
// -- the model is an improvement, never a dependency.
//
// Budget: NOTICE_DAILY_CAP generated notices per customer per day. Past that,
// notices keep flowing, they are just the template wording.

require_once __DIR__ . '/claim_lib.php';

const NOTICE_DAILY_CAP = 20;
const NOTICE_MODEL     = 'claude-sonnet-4-6';

// Secrets live with the bot, not in the dashboard config: one copy, one owner.
function notice_api_key() {
  static $k = null;
  if ($k !== null) return $k;
  $k = '';
  foreach (['/opt/openranch-bot/.env'] as $p) {
    if (!is_readable($p)) continue;
    foreach (file($p) as $line) {
      if (preg_match('/^ANTHROPIC_API_KEY=(.+)$/', trim($line), $m)) { $k = trim($m[1]); break 2; }
    }
  }
  return $k;
}

// Returns true and consumes one, or false when today's allowance is spent.
function notice_take_budget(PDO $db, $cid) {
  $today = gmdate('Y-m-d');
  try {
    $db->prepare('INSERT INTO notice_budget (customer_id, day, used) VALUES (?, ?, 0)
                  ON DUPLICATE KEY UPDATE customer_id = customer_id')->execute([$cid, $today]);
    $q = $db->prepare('SELECT used FROM notice_budget WHERE customer_id = ? AND day = ?');
    $q->execute([$cid, $today]);
    if ((int)$q->fetchColumn() >= NOTICE_DAILY_CAP) return false;
    $db->prepare('UPDATE notice_budget SET used = used + 1 WHERE customer_id = ? AND day = ?')
       ->execute([$cid, $today]);
    return true;
  } catch (PDOException $e) {
    return false;                       // no budget table: stay on templates
  }
}

const NOTICE_SYSTEM = <<<'SYS'
You rewrite one machine notification for someone who looks after a small ranch.

Return ONE sentence, plain English, under 200 characters. No greeting, no
sign-off, no markdown, no emoji. It may be read aloud.

Say what happened and what it means in practice. Use the numbers you are given
and nothing else -- never invent a reading, a name or a time. Say
"notification", "limit" and "automation" rather than alert, threshold or rule.

If the facts are thin, a shorter sentence is better than a padded one.
SYS;

// $context is small structured JSON: device, metric, value, limit, minutes, etc.
function notice_compose(PDO $db, $cid, $kind, array $context, $fallback) {
  $key = notice_api_key();
  if ($key === '' || !notice_take_budget($db, $cid)) return $fallback;

  $payload = json_encode([
    'model' => NOTICE_MODEL,
    'max_tokens' => 200,
    'system' => NOTICE_SYSTEM,
    'messages' => [[
      'role' => 'user',
      'content' => "Kind: $kind\nFacts: " . json_encode($context)
                 . "\nCurrent wording: $fallback",
    ]],
  ]);

  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => 12, 'ignore_errors' => true,
    'header' => "content-type: application/json\r\n"
              . "anthropic-version: 2023-06-01\r\n"
              . "x-api-key: $key\r\n",
    'content' => $payload,
  ]]);
  $raw = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
  if ($raw === false) return $fallback;

  $j = json_decode($raw, true);
  if (!is_array($j) || !empty($j['error'])) {
    error_log('notice_compose: ' . substr((string)($j['error']['message'] ?? 'unknown'), 0, 160));
    return $fallback;
  }
  $text = '';
  foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
  $text = trim(preg_replace('/\s+/', ' ', $text));
  // A refusal, an empty answer or something absurdly long is not an improvement.
  if ($text === '' || mb_strlen_safe($text) > 300) return $fallback;
  return $text;
}

// No mbstring on this build; count characters with a UTF-8-aware regex.
function mb_strlen_safe($s) { return preg_match_all('/./u', $s); }

// Compose, then deliver by every channel the customer has: web push always,
// Telegram when a chat is linked. Failures on one channel never stop another.
function notice_send(PDO $db, $cid, $kind, $title, array $context, $fallback) {
  $body = notice_compose($db, $cid, $kind, $context, $fallback);

  if (function_exists('wp_send_to_customer')) {
    try { wp_send_to_customer($cid, ['title' => $title, 'body' => $body, 'tag' => 'openranch-' . $kind]); }
    catch (Throwable $e) { error_log('notice push failed: ' . $e->getMessage()); }
  }
  notice_telegram($db, $cid, $title, $body);
  return $body;
}

function notice_telegram(PDO $db, $cid, $title, $body) {
  try {
    $q = $db->prepare('SELECT telegram_chat_id FROM customers WHERE id = ?');
    $q->execute([$cid]);
    $chat = $q->fetchColumn();
    if (!$chat) return false;
  } catch (PDOException $e) { return false; }

  $token = '';
  if (is_readable('/opt/openranch-bot/.env')) {
    foreach (file('/opt/openranch-bot/.env') as $line) {
      if (preg_match('/^TELEGRAM_BOT_TOKEN=(.+)$/', trim($line), $m)) { $token = trim($m[1]); break; }
    }
  }
  if ($token === '') return false;

  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
    'header' => "content-type: application/json\r\n",
    'content' => json_encode(['chat_id' => (int)$chat, 'text' => "$title\n$body"]),
  ]]);
  return @file_get_contents("https://api.telegram.org/bot$token/sendMessage", false, $ctx) !== false;
}
