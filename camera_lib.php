<?php
// OpenRanch — camera helpers, shared by the upload endpoint, the dashboard,
// the API and the prune.
//
// Images live on disk under CAM_ROOT/<device-slug>/YYYYMMDD-HHMMSS.jpg and one
// row per image lives in `snapshots`. The table is the index: the gallery, the
// timeline and retention all read it rather than scanning directories, which
// matters once a camera has a few thousand frames.

require_once __DIR__ . '/claim_lib.php';

function cam_root() {
  return defined('SNAPSHOT_ROOT') ? SNAPSHOT_ROOT : __DIR__ . '/snapshots';
}

// The largest frame we will store. An ESP32-CAM at SVGA is ~40-80 KB; anything
// past a couple of megabytes is a misconfigured board or something that is not
// a camera, and is refused rather than filling the disk.
const CAM_MAX_BYTES = 3145728;

function cam_dir($slug, $create = false) {
  $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)$slug);
  if ($slug === '') return null;
  $d = cam_root() . '/' . $slug;
  if ($create && !is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}

// A filename we generated, never one a caller supplied.
function cam_valid_name($f) {
  return (bool)preg_match('/^\d{8}-\d{6}(-[a-z]+)?\.jpg$/', (string)$f);
}

function cam_path($slug, $file) {
  if (!cam_valid_name($file)) return null;
  $d = cam_dir($slug);
  return $d ? "$d/$file" : null;
}

// JPEG or nothing. Checking the magic bytes stops a device token being used to
// drop arbitrary files into a web-served directory.
function cam_is_jpeg($bytes) {
  return strlen($bytes) > 3
      && substr($bytes, 0, 2) === "\xFF\xD8"
      && substr($bytes, -2) === "\xFF\xD9";
}

function cam_devices(PDO $db, $cid) {
  $q = $db->prepare('SELECT * FROM devices WHERE customer_id = ? AND is_camera = 1 ORDER BY name');
  $q->execute([$cid]);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}

function cam_latest(PDO $db, $deviceId) {
  $q = $db->prepare('SELECT * FROM snapshots WHERE device_id = ? ORDER BY taken DESC, id DESC LIMIT 1');
  $q->execute([$deviceId]);
  return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cam_list(PDO $db, $deviceId, $limit = 60, $sinceHours = null) {
  $sql = 'SELECT * FROM snapshots WHERE device_id = ?';
  $args = [$deviceId];
  if ($sinceHours !== null) { $sql .= ' AND taken >= (NOW() - INTERVAL ? HOUR)'; $args[] = (int)$sinceHours; }
  $sql .= ' ORDER BY taken DESC, id DESC LIMIT ' . max(1, min(500, (int)$limit));
  $q = $db->prepare($sql); $q->execute($args);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}

// Store one frame. Returns [ok, message, filename].
function cam_store(PDO $db, array $device, $bytes, $source = 'auto') {
  if (!cam_is_jpeg($bytes))                return [false, 'not a JPEG', null];
  if (strlen($bytes) > CAM_MAX_BYTES)      return [false, 'image too large', null];

  $dir = cam_dir($device['slug'], true);
  if (!$dir || !is_dir($dir))              return [false, 'could not open the camera directory', null];

  // Second resolution is enough for a camera that snapshots on a timer; a
  // collision inside one second just gets a suffix rather than overwriting.
  $name = gmdate('Ymd-His') . ($source === 'manual' ? '-manual' : ($source === 'mirror' ? '-mirror' : '')) . '.jpg';
  $n = 1;
  while (file_exists("$dir/$name") && $n < 50) {
    $name = gmdate('Ymd-His') . "-$n" . ($source === 'manual' ? '-manual' : '') . '.jpg';
    $n++;
  }

  // Write to a temp name and rename: a reader must never see a half-written
  // frame, and `latest.jpg` is replaced atomically for the same reason.
  $tmp = "$dir/.tmp-" . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $bytes) === false) return [false, 'could not write the image', null];
  @chmod($tmp, 0644);
  if (!@rename($tmp, "$dir/$name")) { @unlink($tmp); return [false, 'could not store the image', null]; }

  $w = $h = null;
  $info = @getimagesizefromstring($bytes);
  if ($info) { $w = (int)$info[0]; $h = (int)$info[1]; }

  $db->prepare('INSERT INTO snapshots (device_id, customer_id, filename, bytes, width, height, source)
                VALUES (?, ?, ?, ?, ?, ?, ?)')
     ->execute([$device['id'], $device['customer_id'], $name, strlen($bytes), $w, $h, $source]);

  return [true, 'stored', $name];
}

// Snapshot retention follows the same plan allowance as readings.
function cam_retention_days(PDO $db, $cid) {
  $plan = function_exists('claim_plan') ? claim_plan($db, $cid) : 'free';
  return $plan === 'pro'
       ? (defined('PRO_RETENTION_DAYS')  ? (int)PRO_RETENTION_DAYS  : 90)
       : (defined('FREE_RETENTION_DAYS') ? (int)FREE_RETENTION_DAYS : 7);
}

// Delete rows and their files together. Returns how many went.
function cam_prune(PDO $db, $deviceId, $slug, $days, $dry = false) {
  $q = $db->prepare('SELECT id, filename FROM snapshots
                      WHERE device_id = ? AND taken < (NOW() - INTERVAL ? DAY)');
  $q->execute([$deviceId, (int)$days]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if ($dry) return count($rows);

  $del = $db->prepare('DELETE FROM snapshots WHERE id = ?');
  $n = 0;
  foreach ($rows as $r) {
    $p = cam_path($slug, $r['filename']);
    if ($p && is_file($p)) @unlink($p);
    $del->execute([$r['id']]);
    $n++;
  }
  return $n;
}

// The command code the firmware reads as "take a photo now". 0-5 are the
// existing relay codes; 6 is the first free one, so cameras and relays can
// share poll.php without either reinterpreting the other's numbers.
const CAM_CMD_SNAPSHOT = 6;
