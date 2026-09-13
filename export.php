<?php
// OpenRanch — CSV export of one device's readings over a date range.
//
//   export.php?device=<slug>&from=YYYY-MM-DD&to=YYYY-MM-DD[&metric=]
//
// Streamed rather than buffered: a pro account asking for 90 days of a
// ten-variable board is a few hundred thousand rows, and building that in
// memory first would exhaust the PHP limit.
require __DIR__ . '/config.php';
require_once __DIR__ . '/claim_lib.php';
or_boot_session();

$customer = current_customer();
if (!$customer) { header('Location: login.php'); exit; }
$cid = (int)$customer['id'];
$db  = db();

$slug   = (string)($_GET['device'] ?? '');
$metric = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['metric'] ?? ''));

$q = $db->prepare('SELECT id, slug, name, variables FROM devices WHERE slug = ? AND customer_id = ?');
$q->execute([$slug, $cid]);
$d = $q->fetch(PDO::FETCH_ASSOC);
if (!$d) { http_response_code(404); echo 'Unknown device.'; exit; }

// Never offer more than the plan actually retains: the rows beyond it are gone,
// and a CSV that silently stops is more confusing than a stated window.
$plan   = function_exists('claim_plan') ? claim_plan($db, $cid) : 'free';
$retain = $plan === 'pro'
        ? (defined('PRO_RETENTION_DAYS') ? (int)PRO_RETENTION_DAYS : 90)
        : (defined('FREE_RETENTION_DAYS') ? (int)FREE_RETENTION_DAYS : 7);

$from = ($_GET['from'] ?? '') !== '' ? substr((string)$_GET['from'], 0, 10) : date('Y-m-d', time() - 7 * 86400);
$to   = ($_GET['to']   ?? '') !== '' ? substr((string)$_GET['to'],   0, 10) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  http_response_code(400); echo 'Bad date.'; exit;
}
$earliest = date('Y-m-d', time() - $retain * 86400);
if ($from < $earliest) $from = $earliest;
if ($to < $from) { http_response_code(400); echo 'The end date is before the start date.'; exit; }

$name = preg_replace('/[^A-Za-z0-9_-]/', '-', $d['slug']);
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"openranch-$name-$from-to-$to.csv\"");
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, ['device_slug', 'device_name', 'metric', 'value', 'recorded_utc']);

$sql = 'SELECT variable, value, created FROM readings
         WHERE device_id = ? AND created >= ? AND created < (? + INTERVAL 1 DAY)';
$args = [$d['id'], $from . ' 00:00:00', $to];
if ($metric !== '') { $sql .= ' AND variable = ?'; $args[] = $metric; }
$sql .= ' ORDER BY created, variable';

$st = $db->prepare($sql);
// Unbuffered: rows go to the browser as MySQL yields them instead of all
// landing in PHP's memory first.
$st->setFetchMode(PDO::FETCH_ASSOC);
$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$st->execute($args);
$n = 0;
while ($r = $st->fetch()) {
  fputcsv($out, [$d['slug'], $d['name'], $r['variable'], $r['value'], $r['created']]);
  if ((++$n % 2000) === 0) { flush(); }
}
fclose($out);
