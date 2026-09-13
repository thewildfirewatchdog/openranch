<?php
// OpenRanch — shared PWA <head> tags + install-bar styling.
// Included by index.php and login.php so both stay in sync.
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#f4e7c3">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="OpenRanch">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
<style>
  #installbar {
    position:fixed; left:12px; right:12px; bottom:12px; z-index:60; display:none;
    background:#ffffff; border:1px solid #e8dfc9; border-radius:12px;
    padding:12px 14px; gap:12px; align-items:center;
    box-shadow:0 8px 28px rgba(43,42,34,.18); font-size:13px;
  }
  #installbar.show { display:flex; }
  #installbar img { width:34px; height:34px; border-radius:8px; flex-shrink:0; }
  #installbar .txt { flex:1; line-height:1.4; }
  #installbar .txt b { display:block; font-weight:700; margin-bottom:2px; }
  #installbar .txt span { color:#6b6a5a; font-size:12px; }
  #installbar button.go {
    background:#d98a2b; color:#3a2205; border:0; border-radius:8px; padding:8px 14px;
    font-family:inherit; font-weight:700; font-size:13px; cursor:pointer; flex-shrink:0; }
  #installbar button.x {
    background:none; border:0; color:#6b6a5a; font-size:20px; line-height:1;
    cursor:pointer; padding:2px 4px; flex-shrink:0; }
  @media (max-width:420px) {
    #installbar { flex-wrap:wrap; }
    #installbar .txt { flex-basis:100%; order:1; }
    #installbar img { order:0; } #installbar button.x { order:0; margin-left:auto; }
    #installbar button.go { order:2; flex-basis:100%; }
  }
  .pushbtn {
    background:none; border:1px solid #cfc7b0; color:#5f5e4f; border-radius:7px;
    padding:4px 10px; font-family:inherit; font-size:11px; cursor:pointer; margin-left:10px; }
  .pushbtn.on { border-color:#5a7d3a; color:#4e7a34; }
</style>
