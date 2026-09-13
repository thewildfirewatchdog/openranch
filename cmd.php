<?php
// OpenRanch Dashboard v1 — command writer (dashboard buttons call this)
//
//   POST /cmd.php   body: pin=<ADMIN_PIN>&slug=<device_slug>&cmd=<0-5>
//
//   cmd 1 = ON/START, 0 = OFF/STOP, 2 = one-shot action (flow meters read it as
//   "zero the running total"). Boards that only understand 0/1 never see a 2,
//   because only the flow meter card sends one — and the dashboard drops the
//   command back to 0 two seconds later so a slow poller can't act on it twice.
//
//   v3: the range widened from 0|1|2 to 0-5 for the weather / master control
//   board, whose firmware maps 0 = both AUX off, 1 = both AUX on, 2 = AUX_1 on,
//   3 = AUX_1 off, 4 = AUX_2 on, 5 = AUX_2 off. The meaning of a code is the
//   receiving board's business — this endpoint only stores it. 3/4/5 reach a
//   board only if some card sends them, and only the weather_station card does,
//   so every other device still sees the exact 0/1/2 traffic it saw before.
//
// v2: a logged-in customer session is accepted as an ALTERNATIVE to the PIN,
// but only for devices assigned to that customer. The admin-PIN path below is
// untouched — when the PIN matches we never start a session, so those requests
// are byte-for-byte what they always were (no Set-Cookie, same JSON, same codes).

require 'config.php';

$customer = null;
if (($_POST['pin'] ?? '') !== ADMIN_PIN) {
  or_session_start();
  $customer = current_customer();
  if (!$customer) json_out(['error' => 'bad pin'], 401);   // same reply as before
}

$slug = $_POST['slug'] ?? '';
$cmd  = (int)($_POST['cmd'] ?? -1);
// 0-5 are the relay codes boards have always understood. 6 is "take a photo",
// read only by cameras; a relay never receives one because only a camera card
// sends it. The range widened rather than a second endpoint appearing, so
// poll.php stays the single place a board asks "what should I do".
if ($cmd < 0 || $cmd > 6) json_out(['error' => 'cmd must be 0-6'], 400);

$stmt = db()->prepare('SELECT id, enabled, commandable, customer_id FROM devices WHERE slug = ?');
$stmt->execute([$slug]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) json_out(['error' => 'unknown device'], 404);

// Customers may only command their own devices. Admin (PIN) skips this.
if ($customer && (int)$device['customer_id'] !== (int)$customer['id']) {
  json_out(['error' => 'not your device'], 403);
}

if (!$device['enabled']) json_out(['error' => 'device not enabled'], 403);
if (!$device['commandable']) json_out(['error' => 'device not commandable'], 403);

db()->prepare('INSERT INTO commands (device_id, cmd) VALUES (?, ?)')
    ->execute([$device['id'], $cmd]);

// keep commands table tiny
db()->prepare('DELETE FROM commands WHERE device_id = ? AND id NOT IN
               (SELECT id FROM (SELECT id FROM commands WHERE device_id = ?
                ORDER BY id DESC LIMIT 5) x)')
    ->execute([$device['id'], $device['id']]);

json_out(['status' => 'ok', 'cmd' => $cmd]);
