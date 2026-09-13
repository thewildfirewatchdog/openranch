<?php
// OpenRanch Dashboard v2 — customer logout
// Clears the session and its cookie, then back to the login page.

require 'config.php';
or_session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $p['path'],
    'domain'   => $p['domain'],
    'secure'   => $p['secure'],
    'httponly' => $p['httponly'],
    'samesite' => $p['samesite'] ?? 'Lax',
  ]);
}

session_destroy();

header('Location: login.php');
exit;
