<?php
// ---------------------------------------------------------------------------
// OpenRanch Dashboard — configuration template.
//
//     cp config.example.php config.php    (install.sh does this for you)
//
// config.php is gitignored and must never be committed: it holds the database
// password, the admin PIN, the provisioning secret and the Web Push private
// key. Serve it as PHP only — if your web server ever hands it out as plain
// text, everything below is public.
//
// Recommended ownership, matching what install.sh sets:
//     chown root:www-data config.php && chmod 640 config.php
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Database. The account needs SELECT, INSERT, UPDATE and DELETE on this
// schema; it never needs CREATE or DROP once schema.sql has been imported.
// ---------------------------------------------------------------------------
define('DB_HOST', 'localhost');           // MySQL/MariaDB host
define('DB_NAME', 'openranch');           // database created by install.sh
define('DB_USER', 'openranch');           // application database user
define('DB_PASS', 'CHANGE_ME_DB_PASSWORD');  // that user's password

// ---------------------------------------------------------------------------
// Admin PIN. Guards every write made from a browser that is not signed in as a
// customer: turning devices on and off, editing thresholds, adding devices,
// and the pump.php control page. It is the whole of admin authentication, so
// make it long and random rather than memorable:
//     php -r 'echo random_int(10000000, 99999999), "\n";'
// ---------------------------------------------------------------------------
define('ADMIN_PIN', 'CHANGE_ME_ADMIN_PIN');

// ---------------------------------------------------------------------------
// Where outage notifications are sent. alerts.php mails this address when a
// device stops reporting. Delivery uses PHP's mail(), so the host needs a
// working MTA (or configure sendmail_path in php.ini).
// ---------------------------------------------------------------------------
define('ALERT_EMAIL', 'admin@example.com');

// ---------------------------------------------------------------------------
// Site identity. SITE_NAME appears in page titles and notification subjects.
// BASE_URL is the public origin, no trailing slash, used to build absolute
// links in outbound messages. It must be the HTTPS origin you actually serve
// from — Web Push and the PWA both require a secure context.
// ---------------------------------------------------------------------------
define('SITE_NAME', 'OpenRanch');
define('BASE_URL',  'https://dashboard.example.com');

// ---------------------------------------------------------------------------
// How many days of readings to keep. ingest.php deletes anything older on
// roughly 2% of requests, so the table stays bounded with no cron job.
//
// This is global: one number for every device and every variable. Raise it if
// you chart longer windows — a device reporting 10 variables every 15 seconds
// writes ~1.7M rows a month, so budget disk before setting this high.
// ---------------------------------------------------------------------------
define('RETENTION_DAYS', 9);

// ---------------------------------------------------------------------------
// Timezone used for calendar-day boundaries in the daily usage chart
// (daily.php). Readings are always STORED in UTC; this only decides where one
// local day ends and the next begins when they are bucketed for display.
// Any PHP timezone identifier: https://www.php.net/manual/timezones.php
// ---------------------------------------------------------------------------
define('FLOW_TZ', 'UTC');

// ---------------------------------------------------------------------------
// Auto-provisioning shared secret. Boards send this as the Provision-Key
// header to register.php on first boot to claim a device row and receive their
// own permanent token. Generate one with:
//     php -r 'echo bin2hex(random_bytes(16)), "\n";'
//
// Rotating it means reflashing any firmware that still needs to self-register;
// boards that already hold a device token are unaffected. Leave it empty only
// if you add every device by hand in admin.php.
// ---------------------------------------------------------------------------
define('PROVISION_KEY', 'CHANGE_ME_PROVISION_KEY');

// ---------------------------------------------------------------------------
// Web Push (VAPID). Optional — leave the two keys empty and the dashboard
// still works, it just falls back to email notifications and the "enable
// notifications" button stays inert.
//
// Generate a keypair (the public value is the base64url of the raw P-256
// point, which is what a browser expects as applicationServerKey):
//
//   openssl ecparam -genkey -name prime256v1 -noout -out vapid_private.pem
//   openssl ec -in vapid_private.pem -pubout -outform DER 2>/dev/null \
//     | tail -c 65 | base64 | tr '+/' '-_' | tr -d '=\n'; echo
//
// Paste the private PEM into VAPID_PRIVATE_PEM and the printed string into
// VAPID_PUBLIC. Rotating these invalidates every existing subscription.
// ---------------------------------------------------------------------------
define('VAPID_SUBJECT', 'mailto:' . ALERT_EMAIL);
define('VAPID_PUBLIC',  '');
define('VAPID_PRIVATE_PEM', <<<'PEMKEY'
-----BEGIN PRIVATE KEY-----
CHANGE_ME_OR_LEAVE_THE_WHOLE_BLOCK_EMPTY_TO_DISABLE_WEB_PUSH
-----END PRIVATE KEY-----
PEMKEY);

// ---------------------------------------------------------------------------
// Session cookie name. Change it only if another application on the same
// hostname already uses this name.
// ---------------------------------------------------------------------------
define('SESSION_NAME', 'or_sess');


// ===========================================================================
// Shared helpers. Everything below is code, not configuration — you should not
// need to edit any of it. It lives here because every entry point requires
// this one file.
// ===========================================================================

// Lazily-opened PDO handle, reused for the life of the request.
function db() {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
  }
  return $pdo;
}

// Every endpoint answers JSON and stops. Keeping this in one place is what
// makes the device-facing responses byte-identical across endpoints.
function json_out($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

// ---------------------------------------------------------------------------
// Customer sessions.
// Definitions only — nothing here runs on include, so ingest.php and poll.php
// keep returning byte-identical responses with no Set-Cookie header. Pages
// that need a session call or_session_start() explicitly.
// ---------------------------------------------------------------------------

function or_session_start() {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name(SESSION_NAME);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    // Requires HTTPS. If you are testing over plain HTTP on localhost, set
    // this to false temporarily — never in production.
    'secure'   => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// For public pages: resume an existing session but never hand a cookie to an
// anonymous visitor who does not already have one.
function or_session_resume() {
  if (isset($_COOKIE[SESSION_NAME])) or_session_start();
}

// The logged-in customer row (id, email, name), or null. Safe to call only
// after or_session_start() or or_session_resume().
function current_customer() {
  static $cache = null;
  if (empty($_SESSION['customer_id'])) return null;
  if ($cache !== null && $cache['id'] == $_SESSION['customer_id']) return $cache;
  $stmt = db()->prepare('SELECT id, email, name FROM customers WHERE id = ?');
  $stmt->execute([$_SESSION['customer_id']]);
  $c = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$c) { unset($_SESSION['customer_id']); return null; }  // deleted account
  return $cache = $c;
}
