<?php
// Runs every tests/*_test.php file and reports one overall result.
//
//   php tests/run.php
//
// Each test file runs in its own PHP process, so one file's helper functions
// and globals can never leak into the next, and a fatal error in one file is
// reported as that file failing rather than stopping the whole run.
// Exit code is 0 only when every file passed — CI relies on that.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("cli only\n"); }

$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
if (!$files) { fwrite(STDERR, "no test files found\n"); exit(1); }

$failed = [];
foreach ($files as $f) {
  $name = basename($f);
  echo "== $name\n";
  passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f), $code);
  if ($code !== 0) $failed[] = "$name (exit $code)";
  echo "\n";
}

printf("%d file(s) run, %d failed\n", count($files), count($failed));
foreach ($failed as $f) echo "  FAILED: $f\n";
exit($failed ? 1 : 0);
