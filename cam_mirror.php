<?php
// OpenRanch — mirror the Watchdog camera's latest frame, admin account only.
//
//   */5 * * * * www-data php /var/www/rcr-dash/cam_mirror.php
//
// Strictly one-way and read-only: this reads Watchdog's live.jpg off disk and
// copies it in as a snapshot on a mirrored camera device. It never writes to,
// deletes from, or otherwise touches the Watchdog tree -- the source path is
// only ever opened for reading.
//
// The mirrored camera belongs to the admin account (the one whose email is
// ALERT_EMAIL), matching how the mirrored sensors already work, and it is
// marked is_mirrored so the dashboard paints it read-only and cam_upload.php
// refuses to write to it.
//
//   --once  copy one frame and report
//   -v      say what happened

require __DIR__ . '/config.php';
require_once __DIR__ . '/camera_lib.php';

const WD_CAM_ROOT = '/var/www/watchdog/cam/cam';
const MIRROR_SLUG = 'watchdog_cam_shop';
const MIRROR_NAME = 'Shop Camera (Watchdog)';
// Don't store the same picture twice: the source is overwritten about once a
// second, but most of those frames are identical to the eye and we only keep
// one every few minutes.
const MIN_GAP_SECONDS = 280;

$verbose = in_array('-v', $argv ?? [], true) || in_array('--once', $argv ?? [], true);
function vlog($m) { global $verbose; if ($verbose) echo $m, "\n"; }

$db = db();

// Which camera on the source side. 'shop' is the only one today; if Watchdog
// grows another, this picks the most recently updated live.jpg.
$candidates = glob(WD_CAM_ROOT . '/*/live.jpg') ?: [];
if (!$candidates) { vlog('no Watchdog camera frame found; nothing to mirror'); exit(0); }
usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
$src = $candidates[0];

if (!is_readable($src)) { vlog("cannot read $src"); exit(0); }
$mtime = filemtime($src);

// The admin account owns mirrored rows, exactly as the sensor mirror does.
$q = $db->prepare('SELECT id FROM customers WHERE email = ?');
$q->execute([defined('ALERT_EMAIL') ? ALERT_EMAIL : '']);
$cid = $q->fetchColumn();
if (!$cid) { vlog('no admin account; nothing to mirror into'); exit(0); }
$cid = (int)$cid;

// Create the mirrored camera device once. enabled=1 so it renders; is_mirrored
// so nothing here can command it and cam_upload.php will not accept frames for
// it; commandable=0 because "take a photo" belongs to the system that owns it.
$q = $db->prepare('SELECT * FROM devices WHERE slug = ?');
$q->execute([MIRROR_SLUG]);
$dev = $q->fetch(PDO::FETCH_ASSOC);
if (!$dev) {
  $db->prepare('INSERT INTO devices (slug, name, token, variables, commandable, enabled,
                  expected_interval, notes, customer_id, is_camera, is_mirrored)
                VALUES (?, ?, ?, ?, 0, 1, 300, ?, ?, 1, 1)')
     ->execute([MIRROR_SLUG, MIRROR_NAME, bin2hex(random_bytes(16)), 'snapshot',
                'Read-only copy of the Watchdog shop camera.', $cid]);
  $q->execute([MIRROR_SLUG]);
  $dev = $q->fetch(PDO::FETCH_ASSOC);
  vlog('created the mirrored camera device');
}

$latest = cam_latest($db, $dev['id']);
if ($latest) {
  $age = time() - strtotime($latest['taken'] . ' UTC');
  if ($age < MIN_GAP_SECONDS) { vlog("last mirrored frame is {$age}s old; skipping"); exit(0); }
}

$bytes = @file_get_contents($src);
if ($bytes === false || !cam_is_jpeg($bytes)) { vlog('source frame is not a readable JPEG'); exit(0); }

// Identical bytes to the last one we stored means the source camera has not
// refreshed; storing it again would just burn retention on a duplicate.
if ($latest) {
  $prev = cam_path(MIRROR_SLUG, $latest['filename']);
  if ($prev && is_file($prev) && md5_file($prev) === md5($bytes)) {
    vlog('source frame is unchanged; skipping'); exit(0);
  }
}

[$ok, $msg, $file] = cam_store($db, $dev, $bytes, 'mirror');
vlog($ok ? sprintf('mirrored %s (%d bytes, source %s)', $file, strlen($bytes), gmdate('H:i:s', $mtime))
         : "mirror failed: $msg");

// Retention for mirrored frames follows the admin account's own plan.
if ($ok && random_int(1, 20) === 1) {
  cam_prune($db, $dev['id'], MIRROR_SLUG, cam_retention_days($db, $cid));
}
