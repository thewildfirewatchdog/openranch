<?php
// OpenRanch Dashboard v1 — command poll endpoint
// Boards GET here instead of a hosted IoT platform's command endpoint:
//
//   GET /poll.php
//   Header:  Device-Token: <token>
//   Returns: {"cmd":1}  or  {"cmd":0}  or  {"cmd":-1} if no command ever sent
//
// Response deliberately contains "value": too, so the existing
// pollCmd() JSON parsing in the ESP sketches works unmodified.

require 'config.php';

$token = $_SERVER['HTTP_DEVICE_TOKEN'] ?? '';
if ($token === '') json_out(['error' => 'missing Device-Token header'], 401);

$stmt = db()->prepare('SELECT * FROM devices WHERE token = ?');
$stmt->execute([$token]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) json_out(['error' => 'unknown token'], 401);
if (!$device['enabled']) json_out(['error' => 'device not enabled'], 403);

$stmt = db()->prepare('SELECT cmd FROM commands WHERE device_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$device['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$cmd = $row ? (int)$row['cmd'] : -1;

json_out(['cmd' => $cmd, 'value' => $cmd]);
