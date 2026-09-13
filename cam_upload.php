<?php
// OpenRanch — camera snapshot upload.
//
//   POST /cam_upload.php
//   Header: Device-Token: <token>
//   Body:   the JPEG itself (Content-Type: image/jpeg)
//           -- or multipart/form-data with the file in "image"
//
//   200 {"status":"stored","file":"20260913-174501.jpg","bytes":48211,"pending_command":0}
//
// Authenticated by the device's own token, exactly as ingest.php is. Unlike the
// Watchdog add-on this replaces, there is no shared static key: a token is per
// device and can be regenerated from admin.php if it leaks.
//
// The response carries pending_command so a camera that has just uploaded knows
// whether a "take photo" request is waiting, without a second round trip to
// poll.php.

require __DIR__ . '/config.php';
require_once __DIR__ . '/camera_lib.php';

$token = $_SERVER['HTTP_DEVICE_TOKEN'] ?? '';
if ($token === '') json_out(['error' => 'missing Device-Token header'], 401);

$stmt = db()->prepare('SELECT * FROM devices WHERE token = ?');
$stmt->execute([$token]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device)                 json_out(['error' => 'unknown token'], 401);
if (!$device['enabled'])      json_out(['error' => 'device is a template, not programmed yet. Claim it first.'], 403);
if (empty($device['is_camera'])) json_out(['error' => 'this device is not a camera'], 403);

// A mirrored row is a copy of another system's hardware; accepting an upload
// for it would put a frame somewhere the mirror will overwrite.
if (!empty($device['is_mirrored'])) json_out(['error' => 'device is mirrored and is read-only here'], 403);

// Two shapes, because firmware differs: a raw body is what an ESP32-CAM sends
// most cheaply, multipart is what an HTTP library does by default.
$bytes = '';
if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
  if (($_FILES['image']['size'] ?? 0) > CAM_MAX_BYTES) json_out(['error' => 'image too large'], 413);
  $bytes = (string)file_get_contents($_FILES['image']['tmp_name']);
} else {
  $bytes = (string)file_get_contents('php://input');
}
if ($bytes === '') json_out(['error' => 'no image in the request'], 400);

$source = (($_GET['source'] ?? $_POST['source'] ?? '') === 'manual') ? 'manual' : 'auto';
[$ok, $msg, $file] = cam_store(db(), $device, $bytes, $source);
if (!$ok) json_out(['error' => $msg], 400);

// A snapshot request is consumed by the upload that answers it: leaving it
// pending would have the camera take a photo every poll until something else
// overwrote the command.
$pending = 0;
$q = db()->prepare('SELECT cmd FROM commands WHERE device_id = ? ORDER BY id DESC LIMIT 1');
$q->execute([$device['id']]);
$last = $q->fetchColumn();
if ($last !== false && (int)$last === CAM_CMD_SNAPSHOT) {
  db()->prepare('INSERT INTO commands (device_id, cmd) VALUES (?, 0)')->execute([$device['id']]);
  $pending = 1;
}

// Opportunistic prune, like ingest.php's: roughly one upload in fifty tidies up
// after this camera, so no cron is required for the file side.
if (random_int(1, 50) === 1 && $device['customer_id']) {
  try {
    cam_prune(db(), $device['id'], $device['slug'],
              cam_retention_days(db(), (int)$device['customer_id']));
  } catch (Throwable $e) { /* never cost a camera its upload */ }
}

json_out([
  'status'          => 'stored',
  'file'            => $file,
  'bytes'           => strlen($bytes),
  'answered_request'=> $pending,          // 1 = this upload satisfied a "take photo"
]);
