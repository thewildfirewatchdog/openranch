<?php
// OpenRanch — cinematic single-button pump control (product showcase).
//
//   pump.php?device=<slug>&pin=<ADMIN_PIN>
//
// This page is a *client* of the existing system. It adds no endpoint and
// changes no contract:
//   - commands go to cmd.php with exactly the params the dashboard card sends
//   - state is read straight out of the readings table, same query shape as
//     index.php?data=1
//   - ingest.php / poll.php / cmd.php / register.php are untouched
//
// The self-served state feed is deliberately named ?data=1 so it matches the
// rule already in sw.js ("?data=1 -> network ONLY, never cached"). Filming a
// cached "PUMP RUNNING" while the board is actually off would be the worst
// possible bug here, so it reuses that guarantee rather than adding a new one.

require 'config.php';

// ---------------------------------------------------------------
// Auth. index.php has no login gate, so this page brings its own:
// the same ADMIN_PIN / ?pin= mechanism admin.php uses. A logged-in
// customer session is accepted as an alternative for their own
// devices, exactly like cmd.php does it.
//
// Two ways to present the PIN, both checking the same ADMIN_PIN:
//   1. ?pin=<ADMIN_PIN> in the URL  -- unchanged, byte for byte. This is
//      what the dashboard card, the installed PWA's start_url and every
//      existing bookmark use, so it must keep behaving exactly as before.
//   2. the unlock screen below, which remembers the PIN in the existing
//      or_sess session instead, so the operator's URL stays clean.
// Nothing else about this page's contracts moves: no new endpoint, no new
// query parameter on cmd.php, no schema change.
// ---------------------------------------------------------------

or_session_resume();
$customer = current_customer();

$pinUrlOk  = hash_equals(ADMIN_PIN, (string)($_REQUEST['pin'] ?? ''));
$pinSessOk = !empty($_SESSION['pump_pin_ok']);
$pinOk     = $pinUrlOk || $pinSessOk;

$isJson = isset($_GET['data']) || isset($_GET['manifest']);

// ---------------------------------------------------------------
// Unlock-form rate limit: GATE_MAX attempts per GATE_WINDOW seconds per IP.
//
// State is one small file per IP under the system temp dir. Deliberately
// not under /var/www (it would be web-readable) and deliberately not in
// MySQL (that would be a schema change). If the temp dir is unwritable the
// limiter fails OPEN -- locking the operator out of a fire pump because a
// counter file could not be written would be the worse failure.
// ---------------------------------------------------------------

const GATE_MAX    = 5;
const GATE_WINDOW = 60;

function gate_file(string $ip): string {
  $dir = sys_get_temp_dir() . '/or_pump_gate';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  return $dir . '/' . hash('sha256', $ip) . '.txt';
}

// Records one attempt and reports whether it may proceed.
// Returns [allowed, seconds_until_next_allowed]. Attempts made while
// blocked are not recorded, so hammering the form cannot extend the block.
function gate_attempt(string $ip): array {
  $fh = @fopen(gate_file($ip), 'c+');
  if (!$fh) return [true, 0];
  @flock($fh, LOCK_EX);

  $now  = time();
  $hits = array_values(array_filter(
    array_map('intval', preg_split('/\s+/', trim((string)stream_get_contents($fh))) ?: []),
    static fn(int $t): bool => $t > $now - GATE_WINDOW
  ));

  $allowed = count($hits) < GATE_MAX;
  if ($allowed) $hits[] = $now;

  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, implode(' ', $hits));
  @flock($fh, LOCK_UN);
  fclose($fh);

  return [$allowed, $allowed ? 0 : max(1, GATE_WINDOW - ($now - min($hits)))];
}

function gate_clear(string $ip): void { @unlink(gate_file($ip)); }

// Sweep abandoned counters now and then so the temp dir cannot grow forever.
function gate_gc(): void {
  $dir = sys_get_temp_dir() . '/or_pump_gate';
  if (!is_dir($dir)) return;
  foreach (glob($dir . '/*.txt') ?: [] as $f) {
    if (@filemtime($f) < time() - 3600) @unlink($f);
  }
}

if (!$pinOk && !$customer) {
  if ($isJson) json_out(['error' => 'bad pin'], 401);

  $dev    = trim((string)($_GET['device'] ?? ''));
  $action = 'pump.php' . ($dev !== '' ? '?device=' . rawurlencode($dev) : '');
  $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

  $gateErr   = '';    // '' | 'invalid' | 'throttled'
  $gateRetry = 0;
  $httpCode  = 401;

  // The form is POSTed to this same URL. No CSRF token: the session cookie
  // is SameSite=Lax, which already blocks a cross-site POST from carrying
  // one, and minting a token would mean handing every anonymous visitor a
  // cookie -- exactly what config.php's or_session_resume() avoids.
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    if (random_int(1, 50) === 1) gate_gc();
    [$allowed, $gateRetry] = gate_attempt($ip);

    if (!$allowed) {
      $gateErr  = 'throttled';
      $httpCode = 429;
      header('Retry-After: ' . $gateRetry);
    } elseif (hash_equals(ADMIN_PIN, trim((string)$_POST['code']))) {
      gate_clear($ip);
      or_session_start();
      session_regenerate_id(true);
      $_SESSION['pump_pin_ok'] = true;
      $_SESSION['pump_pin_at'] = time();
      // Straight to the button page, PIN nowhere in the URL. 303 so a
      // reload of the destination never re-POSTs the code.
      header('Location: ' . $action, true, 303);
      exit;
    } else {
      $gateErr = 'invalid';
    }
  }

  http_response_code($httpCode);
  header('Cache-Control: no-store, no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Secure Pump Access — OpenRanch</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0b0c0e">
<link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  /* Same tokens and the same type stack as the button page below, so the
     unlock screen reads as the first frame of it rather than a detour. */
  :root {
    --orange: #E8952F;
    --ink:    #0b0c0e;
    --paper:  #e9edf2;
    --dim:    #9aa3ad;
    --bad:    #f85149;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }

  body {
    background: var(--ink);
    color: var(--paper);
    font-family: 'Big Shoulders Display', 'Arial Narrow', system-ui, sans-serif;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: max(20px, env(safe-area-inset-top)) 18px max(20px, env(safe-area-inset-bottom));
    -webkit-tap-highlight-color: transparent;
  }

  /* Decorative only: pointer-transparent, aria-hidden, below the form. */
  .layer { position: fixed; inset: 0; pointer-events: none; }

  #bg {
    z-index: 1;
    background: url('/assets/hero_clean.png') center center / cover no-repeat;
    opacity: .30;
  }
  #vignette {
    z-index: 2;
    background:
      radial-gradient(ellipse at 50% 42%, rgba(0,0,0,.10) 0%, rgba(0,0,0,.72) 78%),
      linear-gradient(to bottom, rgba(0,0,0,.72) 0%, rgba(0,0,0,.30) 26%,
                                 rgba(0,0,0,.46) 66%, rgba(0,0,0,.90) 100%);
  }

  main { position: relative; z-index: 10; width: min(400px, 100%); text-align: center; }

  h1 {
    font-weight: 900;
    font-size: clamp(28px, 9.4vw, 56px);
    line-height: .92;
    letter-spacing: .045em;
    text-transform: uppercase;
    text-shadow: 0 2px 18px rgba(0,0,0,.85), 0 1px 2px rgba(0,0,0,.9);
  }
  h1 em { font-style: normal; color: var(--orange); }

  .rule {
    width: min(60vw, 300px); height: 3px; margin: 9px auto 0;
    background: linear-gradient(90deg, transparent, var(--orange) 18%, var(--orange) 82%, transparent);
    box-shadow: 0 0 14px rgba(255,121,0,.65);
  }

  .sub {
    margin: 13px auto 0;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(9px, 2.7vw, 12px);
    letter-spacing: .24em;
    text-transform: uppercase;
    color: var(--dim);
    text-shadow: 0 1px 6px rgba(0,0,0,.95);
  }

  form { margin-top: clamp(26px, 7vh, 44px); display: flex; flex-direction: column; gap: 12px; }

  .code {
    width: 100%;
    padding: 15px 12px;
    text-align: center;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(24px, 8vw, 34px);
    font-weight: 600;
    /* text-indent cancels the trailing letter-space so the value stays
       optically centred. */
    letter-spacing: .40em;
    text-indent: .40em;
    color: var(--paper);
    background: rgba(9, 11, 13, .74);
    border: 1px solid #30363d;
    border-radius: 14px;
    outline: none;
    box-shadow: inset 0 2px 14px rgba(0,0,0,.6);
    transition: border-color .18s ease, box-shadow .18s ease;
  }
  .code::placeholder { color: #4c545d; letter-spacing: .32em; }
  .code:focus {
    border-color: var(--orange);
    box-shadow: inset 0 2px 14px rgba(0,0,0,.6),
                0 0 0 3px rgba(255,121,0,.16),
                0 0 24px rgba(255,121,0,.22);
  }
  .code.bad { border-color: var(--bad); }

  .unlock {
    width: 100%;
    padding: 15px;
    border: 0;
    border-radius: 14px;
    background: var(--orange);
    color: #160800;
    font-family: inherit;
    font-weight: 900;
    font-size: clamp(19px, 5.6vw, 24px);
    letter-spacing: .18em;
    text-transform: uppercase;
    cursor: pointer;
    box-shadow: 0 10px 30px rgba(255,121,0,.26);
  }
  .unlock:active { transform: translateY(1px); }
  .unlock[disabled] { background: #33383e; color: #8b949e; box-shadow: none; cursor: not-allowed; }

  .err {
    min-height: 1.25em;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(9px, 2.7vw, 12px);
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--bad);
    text-shadow: 0 1px 6px rgba(0,0,0,.95);
  }

  .shake { animation: shake .45s cubic-bezier(.36,.07,.19,.97) both; }
  @keyframes shake {
    10%, 90% { transform: translateX(-2px); }
    20%, 80% { transform: translateX(4px); }
    30%, 50%, 70% { transform: translateX(-8px); }
    40%, 60% { transform: translateX(8px); }
  }
  @media (prefers-reduced-motion: reduce) {
    .shake { animation: none; }
  }
</style>
</head>
<body>

<div class="layer" id="bg" aria-hidden="true"></div>
<div class="layer" id="vignette" aria-hidden="true"></div>

<main>
  <h1>Open<em>Ranch</em></h1>
  <div class="rule"></div>
  <div class="sub">Secure Pump Access</div>

  <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>" autocomplete="off">
    <input id="code" class="code<?= $gateErr === 'invalid' ? ' bad shake' : '' ?>"
           name="code" type="password"
           inputmode="numeric" pattern="[0-9]*" maxlength="12"
           placeholder="••••" autocomplete="off" autocapitalize="off"
           spellcheck="false" autofocus
           aria-label="Access code"
           aria-invalid="<?= $gateErr === 'invalid' ? 'true' : 'false' ?>">
    <button id="unlock" class="unlock" type="submit"
            <?= $gateErr === 'throttled' ? 'disabled' : '' ?>>Unlock</button>
    <div class="err" role="alert" id="err"><?php
      if ($gateErr === 'invalid')        echo 'Invalid code';
      elseif ($gateErr === 'throttled')  echo 'Too many attempts — wait <span id="wait">'
                                            . (int)$gateRetry . '</span>s';
    ?></div>
  </form>
</main>

<script>
(function () {
  'use strict';
  var code = document.getElementById('code');

  // Digits only, and a focus nudge for browsers that ignore autofocus.
  code.addEventListener('input', function () {
    var clean = this.value.replace(/\D+/g, '');
    if (clean !== this.value) this.value = clean;
  });
  try { code.focus({ preventScroll: true }); code.select(); } catch (e) { code.focus(); }

  // Drop the shake class once it has played, so the next wrong code
  // re-triggers the animation instead of silently reusing a finished one.
  code.addEventListener('animationend', function () { code.classList.remove('shake'); });

  // Throttled: count the lock-out down and hand the button back.
  var wait = document.getElementById('wait');
  if (wait) {
    var btn  = document.getElementById('unlock');
    var left = parseInt(wait.textContent, 10) || 0;
    var t = setInterval(function () {
      left -= 1;
      if (left > 0) { wait.textContent = left; return; }
      clearInterval(t);
      document.getElementById('err').textContent = '';
      btn.disabled = false;
      code.focus();
    }, 1000);
  }
})();
</script>
</body>
</html>
<?php
  exit;
}

// ---------------------------------------------------------------
// Device selection.
//
// Default: the bench board, pinned by PRIMARY KEY.
//
// Resolving by name is deliberately gone. Auto-provisioning gives every
// board the same `name` it announces, so "RS150 Pump Button" matched two
// rows (id 26, MAC AA:BB:CC:DD:EE:01 and id 27, MAC AA:BB:CC:DD:EE:02).
// Any name query then had to break the tie on some incidental column, and
// picked the row that was NOT the live board -- the page polled a silent
// device while the operator believed they were driving the bench board.
// Verified 2026-08-26: id 27 is the board that actually ingests
// (relay_state/rssi/heartbeat/fw_version every ~12-18s); id 26 never has.
//
// CLEAN SLATE 2026-08-27 01:25 UTC: ids 26 and 27 (and all their readings and
// commands) were deleted deliberately to end the two-board ambiguity above.
// The one powered board re-registered itself via register.php as id 28
// (MAC AA:BB:CC:DD:EE:01, fw 8) and was enabled by hand. It is now the ONLY
// row whose name or slug matches RS150 -- there is nothing left to tie-break,
// so the id below is unambiguous rather than a guess between duplicates.
//
// An explicit ?device=<slug> still overrides this, unchanged.
// ---------------------------------------------------------------

const DEFAULT_DEVICE_ID = 28;
const DEFAULT_SLUG      = 'rs150_pump_button_3250d';   // id 28, kept in sync

$slug = trim((string)($_GET['device'] ?? ''));
if ($slug === '') {
  // By id, so a renamed or duplicated row can never re-point this page.
  $stmt = db()->prepare('SELECT slug FROM devices WHERE id = ?');
  $stmt->execute([DEFAULT_DEVICE_ID]);
  $slug = $stmt->fetchColumn() ?: DEFAULT_SLUG;
}

$stmt = db()->prepare('SELECT * FROM devices WHERE slug = ?');
$stmt->execute([$slug]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$device) {
  if ($isJson) json_out(['error' => 'unknown device'], 404);
  http_response_code(404);
  echo '<!DOCTYPE html><body style="background:#0b0c0e;color:#f85149;font-family:system-ui;padding:28px">'
     . 'Unknown device: ' . htmlspecialchars($slug, ENT_QUOTES) . '</body>';
  exit;
}

// A customer without the admin PIN may only reach their own devices.
$owns = $pinOk || ($customer && (int)$device['customer_id'] === (int)$customer['id']);
if (!$owns) {
  if ($isJson) json_out(['error' => 'not your device'], 403);
  http_response_code(403);
  echo '<!DOCTYPE html><body style="background:#0b0c0e;color:#f85149;font-family:system-ui;padding:28px">'
     . 'Not your device.</body>';
  exit;
}

// ---------------------------------------------------------------
// Which variable carries the on/off truth. relay_state is the one this
// firmware reports; the fallbacks let the same page drive the other
// commandable boards (demo_pump reports engine_state, etc.) without
// anybody editing this file.
// ---------------------------------------------------------------

$vars = array_map('trim', explode(',', $device['variables']));

function pick_state_var(array $vars): ?string {
  foreach (['relay_state', 'pump_state', 'engine_state', 'sprinkler_state',
            'valve_state', 'zone1_state', 'sequence_state'] as $c) {
    if (in_array($c, $vars, true)) return $c;
  }
  foreach ($vars as $v) if (str_ends_with($v, '_state')) return $v;   // last resort
  return null;
}

$stateVar = pick_state_var($vars);

// ---------------------------------------------------------------
// State payload. Shared by ?data=1 and the first server-rendered paint,
// so the button never flashes a wrong colour on load.
//
// OFFLINE rule: 2x the device's expected interval, with a 30s floor.
//
// The floor was 20s while every device had expected_interval >= 15, so it
// never bound: 2x15 = 30 already. On 2026-08-27 device 26's interval was
// corrected 15 -> 7 to match its measured posting rate, which would have
// dropped this page to max(14,20) = 20s. The floor is 30 so the confirmed
// "OFFLINE within ~45s" behaviour stays at 30s regardless of interval.
// Verified no-op for every other device: the next-lowest interval is 15
// (2x15 = 30), and 30/60/120 all clear the floor on the 2x term.
//
// index.php keeps its own 3x STALE rule -- that logic is not touched.
// ---------------------------------------------------------------

function pump_payload(array $device, ?string $stateVar, bool $owns): array {
  $vals = [];
  $lastSeen = null;

  if ($device['enabled']) {
    $stmt = db()->prepare(
      'SELECT r.variable, r.value, r.created FROM readings r
       INNER JOIN (SELECT variable, MAX(id) mid FROM readings WHERE device_id = ?
                   GROUP BY variable) x ON x.mid = r.id');
    $stmt->execute([$device['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $vals[$row['variable']] = (float)$row['value'];
      if ($lastSeen === null || $row['created'] > $lastSeen) $lastSeen = $row['created'];
    }
  }

  $offlineAfter = max(2 * (int)$device['expected_interval'], 30);
  $age    = $lastSeen === null ? null : max(0, time() - strtotime($lastSeen));
  $online = (bool)$device['enabled'] && $age !== null && $age <= $offlineAfter;

  return [
    'slug'          => $device['slug'],
    'name'          => $device['name'],
    'enabled'       => (int)$device['enabled'],
    'commandable'   => (int)$device['commandable'],
    'state_var'     => $stateVar,
    // Raw last-reported value. The client gates on `online`; it never
    // paints green from anything but a fresh reading.
    'relay'         => ($stateVar !== null && isset($vals[$stateVar]))
                         ? (int)round($vals[$stateVar]) : null,
    'rssi'          => isset($vals['rssi'])      ? (int)round($vals['rssi'])      : null,
    'heartbeat'     => isset($vals['heartbeat']) ? (int)round($vals['heartbeat']) : null,
    'age_seconds'   => $age,
    'online'        => $online,
    'offline_after' => $offlineAfter,
    'can_command'   => (bool)$device['enabled'] && (bool)$device['commandable']
                         && $owns && $stateVar !== null,
  ];
}

// ---- state feed (polled every 2s) ----
if (isset($_GET['data'])) {
  header('Cache-Control: no-store, no-cache, must-revalidate');
  json_out(pump_payload($device, $stateVar, $owns));
}

// ---- per-device PWA manifest, so "add to home screen" lands on THIS pump ----
if (isset($_GET['manifest'])) {
  $start = 'pump.php?device=' . rawurlencode($device['slug'])
         . ($pinUrlOk ? '&pin=' . rawurlencode(ADMIN_PIN) : '');
  header('Content-Type: application/manifest+json');
  echo json_encode([
    'id'               => '/pump-' . $device['slug'],
    'name'             => 'OpenRanch Pump — ' . $device['name'],
    'short_name'       => 'Pump',
    'description'      => 'One-button pump control for ' . $device['name'] . '.',
    'start_url'        => $start,
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#0b0c0e',
    'theme_color'      => '#0b0c0e',
    'icons'            => [
      ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
      ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
      ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
      ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$boot     = pump_payload($device, $stateVar, $owns);
$qsDevice = rawurlencode($device['slug']);
// Only ever echoed back when the PIN arrived in the URL to begin with. A
// session-unlocked page keeps it out of every link it renders.
$qsPin    = $pinUrlOk ? rawurlencode(ADMIN_PIN) : '';

// ---------------------------------------------------------------
// Scene backgrounds. Purely cosmetic: if either new plate is missing
// from /assets the page silently falls back to the original wolf for
// BOTH scenes, so the crossfade becomes a no-op and nothing breaks.
// ---------------------------------------------------------------

const BG_HERO = '/assets/hero_background.png';

$bgRoot  = __DIR__ . '/assets/';
$bgOk    = is_file($bgRoot . 'bg_fire.png') && is_file($bgRoot . 'bg_rain.png');
$fireSrc = $bgOk ? '/assets/bg_fire.png' : BG_HERO;
$rainSrc = $bgOk ? '/assets/bg_rain.png' : BG_HERO;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($device['name']) ?> — Pump Control</title>

<link rel="manifest" crossorigin="use-credentials"
      href="pump.php?manifest=1&amp;device=<?= $qsDevice ?><?= $qsPin ? '&amp;pin=' . $qsPin : '' ?>">
<meta name="theme-color" content="#0b0c0e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Pump">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="preload" as="image" href="<?= $fireSrc ?>">
<link rel="preload" as="image" href="<?= $rainSrc ?>">
<link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

<style>
  :root {
    --orange: #E8952F;
    --ink:    #0b0c0e;
    --paper:  #e9edf2;
    --dim:    #9aa3ad;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  html, body { height: 100%; }

  body {
    background: var(--ink);
    color: var(--paper);
    font-family: 'Big Shoulders Display', 'Arial Narrow', system-ui, sans-serif;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    overflow: hidden;
    padding: max(14px, env(safe-area-inset-top)) 16px max(14px, env(safe-area-inset-bottom));
    -webkit-user-select: none; user-select: none;
    -webkit-tap-highlight-color: transparent;
  }

  /* ---------- scene layers ----------
     Fixed elements rather than background-attachment:fixed, which iOS
     Safari renders badly. Everything below z-index 10 is decorative and
     pointer-transparent; the button, label and status line live at 10+
     and therefore always paint above every effect layer.

     Stacking order, bottom to top:
       1 #bgFire   fire plate (always opaque -- the base of the crossfade)
       2 #bgRain   rain plate, opacity 0<->1 over 1.5s = the crossfade
       3 #fxGlow   breathing fire glow      / #fxTint  cool blue wash
       4 #vignette the original readability gradient (unchanged)
       5 #fxFire   ember drift              / #fxRain  tracers + drips
       6 #dim      command-in-flight dim
     Only opacity/transform ever animate, so the compositor does all of it. */
  .layer { position: fixed; inset: 0; pointer-events: none; }

  #bgFire, #bgRain { background: center center / cover no-repeat; }
  #bgFire { z-index: 1; background-image: url('<?= $fireSrc ?>'); }
  #bgRain { z-index: 2; background-image: url('<?= $rainSrc ?>'); opacity: 0; }

  #fxGlow, #fxTint { z-index: 3; }
  #fxGlow { mix-blend-mode: screen; }
  #vignette        { z-index: 4; }
  #fxFire, #fxRain { z-index: 5; }
  #dim             { z-index: 6; }

  /* crossfade: every scene-owned layer moves together on the same curve */
  #bgRain, #fxGlow, #fxTint, #fxFire, #fxRain {
    transition: opacity 1.5s ease;
  }
  body[data-scene="fire"] #bgRain,
  body[data-scene="fire"] #fxTint,
  body[data-scene="fire"] #fxRain { opacity: 0; }
  body[data-scene="rain"] #bgRain { opacity: 1; }
  body[data-scene="rain"] #fxGlow,
  body[data-scene="rain"] #fxFire { opacity: 0; }

  /* the original readability vignette, moved onto its own layer so the
     effects can sit above it while the copy still sits above them */
  #vignette {
    background:
      radial-gradient(ellipse at 50% 42%, rgba(0,0,0,.10) 0%, rgba(0,0,0,.68) 78%),
      linear-gradient(to bottom, rgba(0,0,0,.72) 0%, rgba(0,0,0,.28) 26%,
                                 rgba(0,0,0,.42) 66%, rgba(0,0,0,.88) 100%);
  }

  /* ---------- OFF: fire glow pulse + ember drift ---------- */
  /* Two elements: the outer layer owns the scene crossfade opacity, the
     inner one owns the 6s breathing opacity. Nesting them keeps the two
     from fighting over the same property. */
  .glowPulse {
    position: absolute; inset: 0;
    background:
      radial-gradient(ellipse 78% 46% at 50% 31%,
        rgba(255,178,68,.95) 0%, rgba(255,98,18,.55) 42%, rgba(190,30,0,0) 74%),
      radial-gradient(ellipse 120% 38% at 50% 6%,
        rgba(255,124,22,.45) 0%, rgba(255,60,0,0) 70%);
    opacity: .15;
    animation: glowPulse 6s ease-in-out infinite;
    will-change: opacity;
  }
  @keyframes glowPulse {
    0%, 100% { opacity: .15; }
    50%      { opacity: .45; }
  }

  .ember {
    position: absolute; bottom: -4%;
    border-radius: 50%;
    background: radial-gradient(circle,
      #fff3cc 0%, #ffb347 38%, rgba(255,108,18,.55) 60%, rgba(255,80,0,0) 74%);
    opacity: 0;
    animation-name: emberRise;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
    will-change: transform, opacity;
  }
  @keyframes emberRise {
    0%   { transform: translate3d(0, 0, 0) scale(.55); opacity: 0; }
    14%  { opacity: var(--eo, .7); }
    72%  { opacity: var(--eo, .7); }
    100% { transform: translate3d(var(--dx, 0px), -88vh, 0) scale(1.1); opacity: 0; }
  }

  /* ---------- ON: rain tracers, glass drips, cool wash ---------- */
  #fxTint {
    opacity: 0;
    background: linear-gradient(180deg,
      rgba(52,112,180,.32) 0%, rgba(26,62,110,.24) 55%, rgba(10,26,52,.36) 100%);
  }
  body[data-scene="rain"] #fxTint { opacity: 1; }

  /* The tilt box is oversized so the rotation never exposes a corner. */
  .rainTilt { position: absolute; inset: -30%; transform: rotate(13deg); overflow: hidden; }

  /* Each layer is twice the tilt box tall and carries every drop twice,
     half a layer apart, so translating it by exactly 50% loops seamlessly.
     That is ONE animated transform per depth layer instead of one per
     drop -- the whole storm costs three composited elements. */
  .rainLayer {
    position: absolute; left: 0; right: 0; top: 0; height: 200%;
    animation-name: rainFall;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
    will-change: transform;
  }
  @keyframes rainFall {
    from { transform: translate3d(0, 0, 0); }
    to   { transform: translate3d(0, 50%, 0); }
  }
  /* the tracer: dark tail above, bright head at the leading (lower) end */
  .rainLayer i {
    position: absolute; display: block; border-radius: 2px;
    background: linear-gradient(to bottom,
      rgba(198,228,255,0) 0%, rgba(206,232,255,.32) 52%,
      rgba(232,246,255,.8) 86%, rgba(255,255,255,1) 100%);
  }

  .drip {
    position: absolute; top: -12%;
    width: 2px; height: var(--dh, 42px);
    border-radius: 2px;
    background: linear-gradient(to bottom,
      rgba(214,236,255,0) 0%, rgba(214,236,255,.14) 55%, rgba(238,249,255,.46) 100%);
    filter: blur(.4px);
    opacity: 0;
    animation: dripRun var(--dt, 4s) cubic-bezier(.42,.02,.58,1) forwards;
    will-change: transform, opacity;
  }
  .drip::after {
    content: ''; position: absolute; left: -1px; bottom: -2px;
    width: 4px; height: 5px; border-radius: 50%;
    background: rgba(240,250,255,.5);
    box-shadow: 0 0 6px rgba(200,230,255,.45);
  }
  @keyframes dripRun {
    0%   { transform: translate3d(0, 0, 0) scaleY(.6);   opacity: 0; }
    12%  { opacity: .85; }
    86%  { opacity: .7; }
    100% { transform: translate3d(8px, 122vh, 0) scaleY(1.25); opacity: 0; }
  }

  /* ---------- command in flight: keep the scene, just dim it ---------- */
  #dim { background: rgba(0,0,0,.34); opacity: 0; transition: opacity .35s ease; }
  body.cmd #dim { opacity: 1; }

  /* Nothing animates for the scene that is not on screen, and nothing
     animates at all while the tab is in the background. */
  body[data-scene="rain"] #fxFire *,
  body[data-scene="fire"] #fxRain * { animation-play-state: paused; }
  html[data-hidden="1"] .layer * { animation-play-state: paused; }

  /* the copy always sits above every decorative layer */
  header, .stage, .status { position: relative; z-index: 10; }

  /* ---------- header ---------- */
  header { text-align: center; flex-shrink: 0; }
  h1 {
    font-weight: 900;
    font-size: clamp(28px, 9.4vw, 60px);
    line-height: .92;
    letter-spacing: .045em;
    text-transform: uppercase;
    text-shadow: 0 2px 18px rgba(0,0,0,.85), 0 1px 2px rgba(0,0,0,.9);
  }
  h1 em { font-style: normal; color: var(--orange); }
  /* Which board this button is wired to. An invisible binding was how a
     wrong-device page passed for a frozen one; now it is on screen. */
  .devname {
    margin: 7px auto 0;
    max-width: 86vw;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(9px, 2.7vw, 12px);
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--dim);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow: 0 1px 6px rgba(0,0,0,.95);
  }
  .rule {
    width: min(60vw, 300px); height: 3px; margin: 9px auto 0;
    background: linear-gradient(90deg, transparent, var(--orange) 18%, var(--orange) 82%, transparent);
    box-shadow: 0 0 14px rgba(255,121,0,.65);
  }

  /* ---------- the button ---------- */
  .stage {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 0;
  }

  .pump {
    /* Portrait phones always land on the 78vw term, comfortably past the
       60vw minimum; the vh term only kicks in on short/landscape screens
       so the button can never overflow the viewport. */
    --size: min(78vw, 52vh, 420px);
    position: relative;
    width: var(--size); height: var(--size);
    border: 0; padding: 0; background: none;
    border-radius: 50%;
    cursor: pointer;
    touch-action: manipulation;
    font-family: inherit;
    -webkit-appearance: none;
    /* flex (not grid) so the label centres reliably inside a <button> on
       older iOS Safari too. Every other layer is absolutely positioned,
       so the label is the only in-flow child. */
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .pump:disabled { cursor: not-allowed; }
  .pump:focus-visible { outline: 3px solid var(--orange); outline-offset: 10px; }

  /* colour per state */
  .pump[data-state="off"]     { --hi:#ff6f62; --mid:#c8161a; --lo:#460406; --glow:255,48,42;  }
  .pump[data-state="on"]      { --hi:#93ffb6; --mid:#16b84c; --lo:#03411b; --glow:44,232,112; }
  .pump[data-state="busy"]    { --hi:#ffdf9b; --mid:#ff9500; --lo:#532b00; --glow:255,160,20; }
  .pump[data-state="offline"] { --hi:#a4abb5; --mid:#5b626c; --lo:#1d2126; --glow:150,160,175;}

  .glow {
    position: absolute; inset: -15%; border-radius: 50%; z-index: 0;
    background: radial-gradient(circle, rgba(var(--glow), .78) 0%, rgba(var(--glow), 0) 62%);
    filter: blur(4px);
    opacity: .4;
    transition: opacity .35s ease;
    pointer-events: none;
  }
  .pump[data-state="off"]     .glow { animation: breathe 2.7s ease-in-out infinite; }
  .pump[data-state="busy"]    .glow { animation: breathe .85s ease-in-out infinite; }
  .pump[data-state="on"]      .glow { opacity: .92; animation: none; }
  .pump[data-state="offline"] .glow { opacity: 0; animation: none; }
  @keyframes breathe {
    0%, 100% { opacity: .26; transform: scale(.94); }
    50%      { opacity: .80; transform: scale(1.05); }
  }

  /* machined metal bezel ring */
  .bezel {
    position: absolute; inset: 0; border-radius: 50%; z-index: 1;
    background: conic-gradient(from 208deg,
      #f4f6f9 0deg, #a8b0ba 34deg, #5b626c 82deg, #d6dce3 132deg,
      #848c96 178deg, #3a4048 232deg, #c3cad2 296deg, #f4f6f9 360deg);
    box-shadow:
      0 28px 62px rgba(0,0,0,.75),
      0 8px 16px rgba(0,0,0,.6),
      inset 0 2px 3px rgba(255,255,255,.6),
      inset 0 -4px 8px rgba(0,0,0,.65);
    transition: box-shadow .12s ease;
  }
  /* dark recess the dome sits down inside */
  .well {
    position: absolute; inset: 7.2%; border-radius: 50%; z-index: 2;
    background: radial-gradient(circle at 50% 42%, #202329 0%, #08090b 72%);
    box-shadow: inset 0 8px 16px rgba(0,0,0,.95), inset 0 -2px 5px rgba(255,255,255,.07);
  }
  .dome {
    position: absolute; inset: 11.4%; border-radius: 50%; z-index: 3;
    background: radial-gradient(circle at 36% 27%,
                  var(--hi) 0%, var(--mid) 45%, var(--lo) 100%);
    box-shadow:
      inset 0 -14px 26px rgba(0,0,0,.5),
      inset 0 10px 20px rgba(255,255,255,.2),
      0 0 42px rgba(var(--glow), .5);
    transition: transform .12s cubic-bezier(.2,.7,.3,1),
                box-shadow .22s ease, background .35s ease;
  }
  /* specular highlight across the top of the dome */
  .dome::before {
    content: ''; position: absolute; left: 12%; right: 12%; top: 6%; height: 38%;
    border-radius: 50%;
    background: linear-gradient(to bottom, rgba(255,255,255,.34), rgba(255,255,255,0));
    pointer-events: none;
  }

  .label {
    position: relative; z-index: 4;
    display: block;
    width: 78%;
    text-align: center;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    line-height: .96;
    font-size: clamp(14px, calc(var(--size) * .108), 34px);
    color: #fff;
    text-shadow: 0 2px 5px rgba(0,0,0,.62), 0 0 22px rgba(0,0,0,.4);
  }
  .pump[data-state="offline"] .label { color: #dfe3e8; }

  /* press-down */
  .pump.pressed .dome {
    transform: translateY(1.6%) scale(.962);
    box-shadow:
      inset 0 -5px 12px rgba(0,0,0,.62),
      inset 0 5px 16px rgba(255,255,255,.12),
      0 0 26px rgba(var(--glow), .55);
  }
  .pump.pressed .bezel {
    box-shadow:
      0 14px 32px rgba(0,0,0,.72),
      0 4px 9px rgba(0,0,0,.55),
      inset 0 2px 3px rgba(255,255,255,.5),
      inset 0 -4px 8px rgba(0,0,0,.65);
  }

  /* ---------- status line ---------- */
  .status {
    flex-shrink: 0;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(10px, 3.05vw, 13px);
    letter-spacing: .02em;
    color: var(--dim);
    text-align: center;
    padding-top: 14px;
    text-shadow: 0 1px 6px rgba(0,0,0,.95);
    white-space: nowrap;
  }
  .status .dot {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: #6b7280; margin-right: 6px; vertical-align: middle;
    position: relative; top: -1px;
  }
  .status.live .dot  { background: #3fb950; box-shadow: 0 0 8px #3fb950; }
  .status.live b     { color: #d6dee7; }
  .status .sep       { opacity: .42; margin: 0 6px; }
  .status.err        { color: #ff8a80; }

  @media (prefers-reduced-motion: reduce) {
    .glow { animation: none !important; }
    .dome { transition: none; }
    /* Static plates + the crossfade only: no pulse, no embers, no rain. */
    #fxFire, #fxRain { display: none !important; }
    .glowPulse { animation: none !important; opacity: .26; }
  }
</style>
</head>
<body data-scene="<?= ($boot['online'] && $boot['relay'] === 1) ? 'rain' : 'fire' ?>">

<!-- Decorative scene layers. All pointer-events:none, all below z-index 10,
     all aria-hidden: nothing here is reachable by touch or by a screen reader. -->
<div class="layer" id="bgFire" aria-hidden="true"></div>
<div class="layer" id="bgRain" aria-hidden="true"></div>
<div class="layer" id="fxGlow" aria-hidden="true"><span class="glowPulse"></span></div>
<div class="layer" id="fxTint" aria-hidden="true"></div>
<div class="layer" id="vignette" aria-hidden="true"></div>
<div class="layer" id="fxFire" aria-hidden="true"></div>
<div class="layer" id="fxRain" aria-hidden="true">
  <div class="rainTilt" id="rainTilt"></div>
  <div id="drips"></div>
</div>
<div class="layer" id="dim" aria-hidden="true"></div>

<header>
  <h1>Open<em>Ranch</em></h1>
  <div class="rule"></div>
  <div class="devname"><?= htmlspecialchars($device['name'], ENT_QUOTES) ?></div>
</header>

<div class="stage">
  <button id="pump" class="pump" data-state="offline" disabled aria-live="polite">
    <span class="glow"></span>
    <span class="bezel"></span>
    <span class="well"></span>
    <span class="dome"></span>
    <span class="label" id="label">OFFLINE</span>
  </button>
</div>

<div class="status" id="status"><span class="dot"></span>connecting…</div>

<script>
(function () {
  'use strict';

  var BOOT     = <?= json_encode($boot, JSON_UNESCAPED_SLASHES) ?>;
  var SLUG     = <?= json_encode($device['slug']) ?>;
  // PIN is the credential cmd.php expects in its POST body -- that contract
  // is untouched, so it is present however this page was unlocked.
  var PIN      = <?= $pinOk ? json_encode(ADMIN_PIN) : 'null' ?>;
  // URL_PIN is only ever set when the PIN arrived in the URL. Unlock by
  // session and ?data=1 authenticates with the or_sess cookie instead, so
  // the code stays out of every address this page builds.
  var URL_PIN  = <?= $pinUrlOk ? json_encode(ADMIN_PIN) : 'null' ?>;
  var DATA_URL = 'pump.php?data=1&device=' + encodeURIComponent(SLUG) +
                 (URL_PIN ? '&pin=' + encodeURIComponent(URL_PIN) : '');

  var POLL_MS      = 1000;
  // If the board never confirms, fall back to the real reported state rather
  // than leaving a fake "STARTING…" on camera forever.
  var INFLIGHT_MS  = 20000;

  var btn    = document.getElementById('pump');
  var label  = document.getElementById('label');
  var status = document.getElementById('status');

  var live      = BOOT;     // last payload from the server
  var inFlight  = null;     // { want: 0|1, at: ms }
  var pollFails = 0;        // consecutive failed polls
  var errUntil = 0;
  var errText  = '';

  /* ---------- press feedback ---------- */
  function press()   { if (!btn.disabled) btn.classList.add('pressed'); }
  function release() { btn.classList.remove('pressed'); }
  btn.addEventListener('pointerdown', press);
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (e) {
    btn.addEventListener(e, release);
  });

  /* ---------- scene visuals (decorative; touches no state) ----------
     The scene follows the *reported* relay state only. A command in
     flight and an offline board both leave the current scene alone --
     the background never guesses ahead of the board. */

  var REDUCED = !!(window.matchMedia &&
                   matchMedia('(prefers-reduced-motion: reduce)').matches);
  var scene     = document.body.dataset.scene || 'fire';
  var dripTimer = null;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function setScene(next) {
    if (next === scene) return;
    scene = next;
    document.body.dataset.scene = next;   // CSS owns the 1.5s crossfade
    if (next !== 'rain') document.getElementById('drips').textContent = '';
    scheduleDrip();
  }

  // 18 embers drifting up out of the fire. One composited element each,
  // animating transform + opacity only, staggered by negative delays so
  // the field is already in motion on the first frame.
  function buildEmbers() {
    var html = '';
    for (var i = 0; i < 18; i++) {
      var sz = rnd(2, 5).toFixed(1);
      html += '<i class="ember" style="left:' + rnd(2, 98).toFixed(2) + '%;' +
              'width:' + sz + 'px;height:' + sz + 'px;' +
              '--dx:' + rnd(-46, 46).toFixed(0) + 'px;' +
              '--eo:' + rnd(.35, .9).toFixed(2) + ';' +
              'animation-duration:' + rnd(9, 19).toFixed(1) + 's;' +
              'animation-delay:-' + rnd(0, 19).toFixed(1) + 's"></i>';
    }
    document.getElementById('fxFire').innerHTML = html;
  }

  // Three depth layers of tracers. Every drop is emitted twice, half a
  // layer apart, so a single translate of 50% loops seamlessly -- three
  // animated transforms in total, however many drops are on screen.
  function buildRain() {
    var host  = document.getElementById('rainTilt');
    var specs = [
      { n: 26, w: 1,   h: [18, 34], dur: 3.4, op: [.16, .34] },  // far
      { n: 18, w: 1.5, h: [34, 58], dur: 2.3, op: [.30, .55] },  // mid
      { n: 10, w: 2.2, h: [58, 96], dur: 1.5, op: [.45, .80] }   // near
    ];

    function drop(left, top, w, h, op) {
      return '<i style="left:' + left + '%;top:' + top.toFixed(2) + '%;' +
             'width:' + w + 'px;height:' + h + 'px;opacity:' + op + '"></i>';
    }

    specs.forEach(function (sp) {
      var layer = document.createElement('div');
      layer.className = 'rainLayer';
      layer.style.animationDuration = sp.dur + 's';
      var html = '';
      for (var i = 0; i < sp.n; i++) {
        var left = rnd(-4, 104).toFixed(2);
        var top  = rnd(0, 50);
        var h    = rnd(sp.h[0], sp.h[1]).toFixed(1);
        var op   = rnd(sp.op[0], sp.op[1]).toFixed(3);
        html += drop(left, top, sp.w, h, op) + drop(left, top + 50, sp.w, h, op);
      }
      layer.innerHTML = html;
      host.appendChild(layer);
    });
  }

  // One bead of water running down the glass every few seconds. Created
  // on demand and removed when it finishes, so nothing accumulates.
  function spawnDrip() {
    var d = document.createElement('i');
    d.className = 'drip';
    d.style.left = rnd(4, 94).toFixed(2) + '%';
    d.style.setProperty('--dh', rnd(26, 72).toFixed(0) + 'px');
    d.style.setProperty('--dt', rnd(3.2, 5.8).toFixed(2) + 's');
    d.addEventListener('animationend', function () { d.remove(); });
    document.getElementById('drips').appendChild(d);
  }

  function scheduleDrip() {
    if (dripTimer) { clearTimeout(dripTimer); dripTimer = null; }
    if (REDUCED || scene !== 'rain' || document.hidden) return;
    dripTimer = setTimeout(function () {
      dripTimer = null;
      if (scene === 'rain' && !document.hidden) spawnDrip();
      scheduleDrip();
    }, rnd(2200, 5400));
  }

  if (!REDUCED) { buildEmbers(); buildRain(); }

  /* ---------- rendering ---------- */
  function ago(s) {
    if (s === null || s === undefined) return 'never';
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    return Math.floor(s / 3600) + 'h';
  }

  function render() {
    var offline = !live.online;
    var state, text;

    if (inFlight && !offline) {
      state = 'busy';
      text  = inFlight.want ? 'STARTING…' : 'STOPPING…';
    } else if (offline) {
      state = 'offline';
      text  = 'OFFLINE';
    } else if (live.relay === 1) {
      state = 'on';
      text  = 'PUMP RUNNING — PUSH TO STOP';
    } else {
      state = 'off';
      text  = 'PUSH TO START PUMP';
    }

    btn.dataset.state = state;
    btn.disabled = offline || !live.can_command;
    if (label.textContent !== text) label.textContent = text;

    // Scene follows the confirmed state; 'busy' and 'offline' hold the
    // one already on screen. 'busy' additionally dims it.
    if (state === 'on')       setScene('rain');
    else if (state === 'off') setScene('fire');
    document.body.classList.toggle('cmd', state === 'busy');

    // status line
    if (Date.now() < errUntil) {
      status.className = 'status err';
      status.innerHTML = '<span class="dot"></span>' + errText;
      return;
    }
    status.className = 'status' + (live.online ? ' live' : '');
    status.innerHTML =
      '<span class="dot"></span><b>' + (live.online ? 'LIVE' : 'OFFLINE') + '</b>' +
      '<span class="sep">·</span>seen ' + ago(live.age_seconds) +
      '<span class="sep">·</span>rssi ' +
        (live.rssi === null ? '--' : live.rssi + 'dBm') +
      '<span class="sep">·</span>hb ' +
        (live.heartbeat === null ? '--' : live.heartbeat);
  }

  function flashError(msg) {
    // Server strings only ever reach the status line as text.
    errText  = String(msg).replace(/[<>&]/g, '');
    errUntil = Date.now() + 3500;
    render();
  }

  /* ---------- polling: the reported relay_state is the only truth ---------- */
  async function poll() {
    try {
      var r = await fetch(DATA_URL, { cache: 'no-store', credentials: 'same-origin' });
      var j = await r.json();
      if (j.error) { pollFails = 0; flashError(j.error); return; }
      pollFails = 0;
      live = j;

      if (inFlight) {
        if (live.relay === inFlight.want) inFlight = null;        // board confirmed
        else if (!live.online) inFlight = null;                   // board dropped out
        else if (Date.now() - inFlight.at > INFLIGHT_MS) {
          inFlight = null;
          flashError('no response from board');
        }
      }
      render();
    } catch (e) {
      // Network hiccup: age keeps climbing locally and the page falls to
      // OFFLINE on its own. Never invent a state here -- but do say so.
      // A silently-dying poll is exactly what makes a stuck page look like
      // a rendering bug, so it gets a console line and, once it is clearly
      // not a one-off, a line on screen.
      pollFails++;
      if (window.console && console.warn) {
        console.warn('pump: poll failed (' + pollFails + ')', e);
      }
      if (pollFails >= 3) flashError('link lost - retrying');
    }
  }

  // Tick the age between polls so "seen 1s / 2s / 3s" moves smoothly on camera,
  // and so a dead network still crosses the offline threshold.
  function tick() {
    if (live.age_seconds !== null && live.age_seconds !== undefined) {
      live.age_seconds += 1;
      if (live.age_seconds > live.offline_after) live.online = false;
    }
    render();
  }

  /* ---------- command: identical to what the dashboard card POSTs ---------- */
  btn.addEventListener('click', async function () {
    if (btn.disabled || !live.can_command || !live.online) return;

    var want = live.relay === 1 ? 0 : 1;
    inFlight = { want: want, at: Date.now() };
    render();                                    // optimistic amber only

    var body = new URLSearchParams({ slug: SLUG, cmd: String(want) });
    if (PIN) body.set('pin', PIN);

    try {
      var r = await fetch('cmd.php', {
        method: 'POST', body: body, credentials: 'same-origin'
      });
      var j = await r.json();
      if (j.error) { inFlight = null; flashError(j.error); }
    } catch (e) {
      inFlight = null;
      flashError('command failed');
    }
    render();
    poll();
  });

  render();
  poll();
  setInterval(poll, POLL_MS);
  setInterval(tick, 1000);

  // Catch up immediately when the phone comes back to the page, and park
  // every effect animation while it is away (CSS pauses off [data-hidden]).
  document.addEventListener('visibilitychange', function () {
    document.documentElement.dataset.hidden = document.hidden ? '1' : '0';
    scheduleDrip();
    if (!document.hidden) poll();
  });
  scheduleDrip();

  // Same service worker the dashboard registers — makes this installable
  // as its own home-screen app without touching pwa.js or sw.js.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  }
})();
</script>
</body>
</html>
