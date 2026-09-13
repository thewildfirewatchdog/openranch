<?php
// OpenRanch — session helper shim.
//
// This release prefixes its session helpers or_*; the deployment this code was
// extracted from still prefixes them ww_*. Pages added since the split call
// or_boot_session() and get whichever the local config.php actually defines, so
// one copy of each page runs unmodified in both trees.
//
// Remove this once both sides agree on a prefix -- it exists only to stop a
// page from fataling on "undefined function" depending on which tree it landed
// in, which is exactly the failure it was written to fix.

if (!function_exists('or_boot_session')) {
  function or_boot_session() {
    if (function_exists('or_session_start')) { or_session_start(); return; }
    if (function_exists('ww_session_start')) { ww_session_start(); return; }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // last resort
  }
}

if (!function_exists('or_boot_session_resume')) {
  function or_boot_session_resume() {
    if (function_exists('or_session_resume')) { or_session_resume(); return; }
    if (function_exists('ww_session_resume')) { ww_session_resume(); return; }
    or_boot_session();
  }
}
