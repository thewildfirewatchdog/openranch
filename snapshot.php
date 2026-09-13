<?php
// OpenRanch — serves stored camera images.
//
//   snapshot.php?device=<slug>&latest=1        the newest frame
//   snapshot.php?device=<slug>&file=<name>     one specific frame
//   snapshot.php?device=<slug>&list=1[&hours=] JSON index for the gallery
//   snapshot.php?device=<slug>&thumb=1&...     same image, smaller
//
// Readable by the owning customer (session), by an API token, or through a
// share link's token. Images are NOT served straight off the filesystem: the
// snapshots directory is denied in nginx so that a stored frame can only be
// reached through this check.

require __DIR__ . '/config.php';
require_once __DIR__ . '/camera_lib.php';
or_boot_session();

$slug = (string)($_GET['device'] ?? '');
$db   = db();

// ---- who is asking -----------------------------------------------------
$cid = null;
$customer = current_customer();
if ($customer) $cid = (int)$customer['id'];

if ($cid === null && !empty($_SERVER['HTTP_AUTHORIZATION'])
    && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
  $q = $db->prepare('SELECT id FROM customers WHERE api_token = ?');
  $q->execute([$m[1]]);
  $v = $q->fetchColumn();
  if ($v !== false) $cid = (int)$v;
}
if ($cid === null && !empty($_GET['t'])) {
  $q = $db->prepare('SELECT id FROM customers WHERE share_token = ? AND share_enabled = 1');
  $q->execute([preg_replace('/[^a-f0-9]/', '', (string)$_GET['t'])]);
  $v = $q->fetchColumn();
  if ($v !== false) $cid = (int)$v;
}
if ($cid === null) { http_response_code(401); exit; }

$q = $db->prepare('SELECT * FROM devices WHERE slug = ? AND customer_id = ? AND is_camera = 1');
$q->execute([$slug, $cid]);
$device = $q->fetch(PDO::FETCH_ASSOC);
if (!$device) { http_response_code(404); exit; }

// ---- JSON index --------------------------------------------------------
if (isset($_GET['list'])) {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $hours = isset($_GET['hours']) ? max(1, min(2160, (int)$_GET['hours'])) : null;
  $rows = cam_list($db, $device['id'], (int)($_GET['limit'] ?? 60), $hours);
  json_out(['device' => $device['slug'], 'name' => $device['name'],
            'count' => count($rows),
            'snapshots' => array_map(fn($r) => [
              'file' => $r['filename'], 'taken' => $r['taken'], 'bytes' => (int)$r['bytes'],
              'width' => $r['width'] ? (int)$r['width'] : null,
              'height' => $r['height'] ? (int)$r['height'] : null,
              'source' => $r['source'],
            ], $rows)]);
}

// ---- an image ----------------------------------------------------------
$row = isset($_GET['file'])
     ? (function () use ($db, $device) {
         $q = $db->prepare('SELECT * FROM snapshots WHERE device_id = ? AND filename = ?');
         $q->execute([$device['id'], (string)$_GET['file']]);
         return $q->fetch(PDO::FETCH_ASSOC) ?: null;
       })()
     : cam_latest($db, $device['id']);

if (!$row) { http_response_code(404); exit; }
$path = cam_path($device['slug'], $row['filename']);
if (!$path || !is_file($path)) { http_response_code(404); exit; }

// A stored frame never changes, so it can be cached hard once fetched; the
// "latest" view must not be, or the card freezes on an old picture.
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $row['filename'] . '"');
header('X-Snapshot-Taken: ' . $row['taken']);
if (isset($_GET['file'])) header('Cache-Control: private, max-age=86400, immutable');
else                      header('Cache-Control: no-store');

// Thumbnails are generated on the fly and not cached to disk: a camera card
// shows one image, and GD resizing a 60 KB JPEG is cheaper than managing a
// second copy of every frame plus its own retention.
if (!empty($_GET['thumb']) && function_exists('imagecreatefromjpeg')) {
  $src = @imagecreatefromjpeg($path);
  if ($src) {
    $w = imagesx($src); $h = imagesy($src);
    $tw = 480; $th = (int)round($h * ($tw / $w));
    if ($w > $tw) {
      $dst = imagecreatetruecolor($tw, $th);
      imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
      imagejpeg($dst, null, 78);
      imagedestroy($dst); imagedestroy($src);
      exit;
    }
    imagedestroy($src);
  }
}

header('Content-Length: ' . filesize($path));
readfile($path);
