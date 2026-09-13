<?php
// OpenRanch — push subscription store/remove
//
//   POST /subscribe.php   JSON {endpoint, keys:{p256dh, auth}}      -> save
//   POST /subscribe.php   JSON {endpoint, unsubscribe:true}         -> remove
//   GET  /subscribe.php                                             -> status
//
// Requires a logged-in customer session. Push is a per-customer feature;
// unassigned (operator-owned) devices keep using email notifications.

require 'config.php';
or_session_start();

$customer = current_customer();
if (!$customer) json_out(['error' => 'not signed in'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $stmt = db()->prepare('SELECT COUNT(*) c FROM push_subscriptions WHERE customer_id = ?');
  $stmt->execute([$customer['id']]);
  json_out([
    'signed_in'     => true,
    'subscriptions' => (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'],
    'vapid_public'  => VAPID_PUBLIC,
  ]);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['endpoint'])) {
  json_out(['error' => 'endpoint required'], 400);
}
$endpoint = $body['endpoint'];
if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strncmp($endpoint, 'https://', 8) !== 0) {
  json_out(['error' => 'endpoint must be an https URL'], 400);
}

// Unsubscribe — only ever removes a row owned by this customer.
if (!empty($body['unsubscribe'])) {
  db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND customer_id = ?')
      ->execute([$endpoint, $customer['id']]);
  json_out(['status' => 'unsubscribed']);
}

$p256dh = $body['keys']['p256dh'] ?? '';
$auth   = $body['keys']['auth']   ?? '';
if ($p256dh === '' || $auth === '') json_out(['error' => 'keys.p256dh and keys.auth required'], 400);

// If this endpoint was already registered (same phone, new login), move it to
// the current customer rather than leaving it pointed at the old account.
db()->prepare(
  'INSERT INTO push_subscriptions (customer_id, endpoint, p256dh, auth, ua)
   VALUES (?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id),
                           p256dh = VALUES(p256dh),
                           auth = VALUES(auth),
                           ua = VALUES(ua)')
  ->execute([
    $customer['id'], $endpoint, $p256dh, $auth,
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
  ]);

json_out(['status' => 'subscribed']);
