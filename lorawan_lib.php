<?php
// OpenRanch — the pure half of the LoRaWAN receiver (lorawan.php).
//
// Everything here takes a decoded webhook body and returns plain values, so
// tests/lorawan_test.php can run it with no database and no network. The
// endpoint does the auth, the lookups and the writes.
//
// Two network servers are understood, told apart by the shape of the body:
//
//   The Things Stack (TTN v3) webhook, "uplink message" enabled
//     {"end_device_ids":{"device_id":"tank-1","dev_eui":"70B3D57ED0012345"},
//      "uplink_message":{"decoded_payload":{"level_pct":62,"battery_v":3.6},
//                        "rx_metadata":[{"rssi":-97,"snr":7.5}]}}
//
//   ChirpStack v4 HTTP integration (event=up), or v3 with JSON marshaling
//     {"deviceInfo":{"deviceName":"tank-1","devEui":"70b3d57ed0012345"},
//      "object":{"level_pct":62},"rxInfo":[{"rssi":-97,"snr":7.5}]}
//
// The readings come from the network server's payload decoder (TTN "payload
// formatter", ChirpStack "codec"). OpenRanch never decodes raw LoRa bytes
// itself: every sensor model packs them differently, and the network server
// already has a decoder library for most of them.

const LW_MAX_VARS = 40;          // per uplink; a runaway decoder can't flood a template

// 16 hex characters, uppercase, separators dropped. Anything else is null.
function lw_normalise_eui($s) {
  if (!is_string($s)) return null;
  $s = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $s));
  return strlen($s) === 16 ? $s : null;
}

// A variable name ingest.php could also have stored: letters, digits, underscore.
function lw_clean_name($s) {
  $s = preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$s);
  $s = trim($s, '_');
  return substr($s, 0, 40);
}

// Decoded payload -> [name => float]. Numbers and numeric strings are kept,
// booleans become 1/0 (decoders love true/false for floats and valves, and the
// plain ingest drops them), one level of nesting becomes parent_child. Text,
// nulls and deeper structures are skipped rather than guessed at.
function lw_flatten($obj, $prefix = '', $depth = 0) {
  $out = [];
  if (!is_array($obj)) return $out;
  foreach ($obj as $k => $v) {
    $name = lw_clean_name($prefix === '' ? (string)$k : $prefix . '_' . $k);
    if ($name === '') continue;
    if (is_bool($v))               $out[$name] = $v ? 1.0 : 0.0;
    elseif (is_int($v) || is_float($v)) $out[$name] = (float)$v;
    elseif (is_string($v) && is_numeric(trim($v))) $out[$name] = (float)trim($v);
    elseif (is_array($v) && $depth === 0 && !array_is_list($v)) {
      $out += lw_flatten($v, $name, 1);
    }
    if (count($out) >= LW_MAX_VARS) break;
  }
  return array_slice($out, 0, LW_MAX_VARS, true);
}

// The gateway that heard the uplink best. rssi/snr are reported as ordinary
// variables, the same names a WiFi board uses for its own signal.
function lw_best_radio($rx) {
  if (!is_array($rx)) return [];
  $best = null;
  foreach ($rx as $g) {
    if (!is_array($g) || !isset($g['rssi']) || !is_numeric($g['rssi'])) continue;
    if ($best === null || (float)$g['rssi'] > (float)$best['rssi']) $best = $g;
  }
  if ($best === null) return [];
  $out = ['rssi' => (float)$best['rssi']];
  if (isset($best['snr']) && is_numeric($best['snr'])) $out['snr'] = (float)$best['snr'];
  return $out;
}

// Body -> ['kind' => 'uplink'|'other', 'source', 'dev_eui', 'name', 'values'].
// $event is ChirpStack's ?event= query parameter ('' when absent). Anything
// that is not an uplink (joins, acks, status, location) comes back as 'other'
// so the endpoint can answer 200 and move on.
function lw_parse($body, $event = '') {
  $none = ['kind' => 'other', 'source' => null, 'dev_eui' => null, 'name' => '', 'values' => []];
  if (!is_array($body)) return $none;

  // The Things Stack
  if (isset($body['end_device_ids']) && is_array($body['end_device_ids'])) {
    $ids = $body['end_device_ids'];
    $r = ['source' => 'ttn',
          'dev_eui' => lw_normalise_eui($ids['dev_eui'] ?? null),
          'name' => (string)($ids['device_id'] ?? '')];
    $up = $body['uplink_message'] ?? null;
    if (!is_array($up)) return ['kind' => 'other', 'values' => []] + $r;
    $vals = lw_flatten($up['decoded_payload'] ?? []);
    $vals += lw_best_radio($up['rx_metadata'] ?? []);     // decoder's own rssi wins
    return ['kind' => 'uplink', 'values' => $vals] + $r;
  }

  // ChirpStack v4 (deviceInfo) or v3 (devEUI at the top level)
  if (isset($body['deviceInfo']) || isset($body['devEUI'])) {
    $info = is_array($body['deviceInfo'] ?? null) ? $body['deviceInfo'] : [];
    $r = ['source' => 'chirpstack',
          'dev_eui' => lw_normalise_eui($info['devEui'] ?? ($body['devEUI'] ?? null)),
          'name' => (string)($info['deviceName'] ?? ($body['deviceName'] ?? ''))];
    // An uplink is the only event carrying fCnt; joins, acks and status do not.
    $isUp = ($event === 'up') || ($event === '' && array_key_exists('fCnt', $body));
    if (!$isUp) return ['kind' => 'other', 'values' => []] + $r;
    $vals = lw_flatten($body['object'] ?? []);
    $vals += lw_best_radio($body['rxInfo'] ?? []);
    return ['kind' => 'uplink', 'values' => $vals] + $r;
  }

  return $none;
}

// Slug for a device that announced itself: network-server name + last 5 of the
// DevEUI, the same pattern register.php uses with the MAC.
function lw_slug_base($name, $eui) {
  $base = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$name)), '_');
  if ($base === '') $base = 'lora';
  $suffix = strtolower(substr($eui, -5));
  return substr($base, 0, 50 - 1 - strlen($suffix) - 3) . '_' . $suffix;  // room for "_NN"
}

// Template variables after an uplink: the existing list plus anything new, in
// first-seen order. Only used while a device is still a template.
function lw_merge_vars($existing, array $names) {
  $vars = array_values(array_filter(array_map('trim', explode(',', (string)$existing)), 'strlen'));
  foreach ($names as $n) if (!in_array($n, $vars, true)) $vars[] = $n;
  return implode(',', $vars);
}
