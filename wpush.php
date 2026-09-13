<?php
// OpenRanch — minimal Web Push (VAPID / RFC 8292 + aes128gcm / RFC 8291)
//
// Hand-rolled deliberately: this server has no composer and no php-curl, and
// adding either to a live box that field hardware talks to wasn't worth it.
// Everything here uses ext-openssl + ext-hash, both already present.
//
// Definitions only — including this file does nothing on its own.

require_once __DIR__ . '/config.php';

function wp_b64u_encode($b) { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function wp_b64u_decode($s) {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  return base64_decode($s);
}

// openssl_sign gives us a DER SEQUENCE{INTEGER r, INTEGER s}; JWS ES256 wants
// the raw 64-byte r||s concatenation.
function wp_der_to_raw($der) {
  $off = 0;
  if (ord($der[$off++]) !== 0x30) return false;
  $len = ord($der[$off++]);
  if ($len & 0x80) $off += ($len & 0x7f);           // long form length

  $read = function () use ($der, &$off) {
    if (ord($der[$off++]) !== 0x02) return false;
    $l = ord($der[$off++]);
    $v = substr($der, $off, $l);
    $off += $l;
    return ltrim($v, "\x00");                        // drop DER sign padding
  };

  $r = $read(); $s = $read();
  if ($r === false || $s === false) return false;
  return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

// Rebuild a P-256 public key (PEM) from the raw 65-byte uncompressed point the
// browser hands us in subscription.keys.p256dh.
function wp_pubkey_pem_from_raw($raw) {
  if (strlen($raw) !== 65 || $raw[0] !== "\x04") return false;
  // Fixed ASN.1 SubjectPublicKeyInfo prefix for id-ecPublicKey / prime256v1.
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return "-----BEGIN PUBLIC KEY-----\n" .
         chunk_split(base64_encode($der), 64, "\n") .
         "-----END PUBLIC KEY-----\n";
}

// VAPID Authorization header value for one push endpoint.
function wp_vapid_header($endpoint) {
  $u = parse_url($endpoint);
  if (!$u || empty($u['scheme']) || empty($u['host'])) return false;
  $aud = $u['scheme'] . '://' . $u['host'];

  $jwtHeader  = wp_b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $jwtPayload = wp_b64u_encode(json_encode([
    'aud' => $aud,
    'exp' => time() + 12 * 3600,
    'sub' => VAPID_SUBJECT,
  ]));
  $input = $jwtHeader . '.' . $jwtPayload;

  $pk = openssl_pkey_get_private(VAPID_PRIVATE_PEM);
  if (!$pk) return false;
  if (!openssl_sign($input, $der, $pk, OPENSSL_ALGO_SHA256)) return false;
  $raw = wp_der_to_raw($der);
  if ($raw === false) return false;

  return 'vapid t=' . $input . '.' . wp_b64u_encode($raw) . ', k=' . VAPID_PUBLIC;
}

// RFC 8291 aes128gcm body for one subscription.
function wp_encrypt($payload, $p256dh_b64u, $auth_b64u) {
  $uaPublic   = wp_b64u_decode($p256dh_b64u);
  $authSecret = wp_b64u_decode($auth_b64u);
  if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) return false;

  $uaPem = wp_pubkey_pem_from_raw($uaPublic);
  if (!$uaPem) return false;
  $uaKey = openssl_pkey_get_public($uaPem);
  if (!$uaKey) return false;

  // Ephemeral application-server keypair, fresh per message.
  $asKey = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
  ]);
  if (!$asKey) return false;
  $d = openssl_pkey_get_details($asKey)['ec'];
  $asPublic = "\x04" . str_pad($d['x'], 32, "\x00", STR_PAD_LEFT)
                     . str_pad($d['y'], 32, "\x00", STR_PAD_LEFT);

  $shared = openssl_pkey_derive($uaKey, $asKey, 32);
  if ($shared === false) return false;

  // ikm  = HKDF(salt=auth_secret, ikm=ecdh, info="WebPush: info\0"||ua||as)
  $ikm = hash_hkdf('sha256', $shared, 32,
                   "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);

  $salt  = random_bytes(16);
  $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
  $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

  // Single record; 0x02 marks it as the last one.
  $plain = $payload . "\x02";
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  if ($cipher === false) return false;

  // salt | rs | idlen | as_public | ciphertext+tag
  return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

// Send one notification. $sub needs endpoint / p256dh / auth.
// Returns ['ok'=>bool, 'status'=>int, 'gone'=>bool, 'error'=>string].
function wp_send($sub, array $payload, $ttl = 86400) {
  if (!defined('VAPID_PUBLIC') || VAPID_PUBLIC === '') {
    return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'VAPID not configured'];
  }

  $body = wp_encrypt(json_encode($payload), $sub['p256dh'], $sub['auth']);
  if ($body === false) {
    return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'encryption failed'];
  }
  $auth = wp_vapid_header($sub['endpoint']);
  if ($auth === false) {
    return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'vapid signing failed'];
  }

  $ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => implode("\r\n", [
      'Authorization: ' . $auth,
      'Content-Encoding: aes128gcm',
      'Content-Type: application/octet-stream',
      'TTL: ' . (int)$ttl,
    ]),
    'content'       => $body,
    'timeout'       => 10,
    'ignore_errors' => true,          // we want the 4xx body, not a warning
  ]]);

  $res = @file_get_contents($sub['endpoint'], false, $ctx);
  $status = 0;
  if (!empty($http_response_header[0]) &&
      preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
    $status = (int)$m[1];
  }

  return [
    'ok'     => $status >= 200 && $status < 300,
    'status' => $status,
    'gone'   => in_array($status, [404, 410], true),   // subscription expired
    'error'  => ($status >= 200 && $status < 300) ? '' : (is_string($res) ? substr($res, 0, 200) : 'no response'),
  ];
}

// Push to every subscription belonging to one customer, pruning dead ones.
function wp_send_to_customer($customerId, array $payload) {
  $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE customer_id = ?');
  $stmt->execute([$customerId]);
  $sent = 0; $failed = 0;

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
    $r = wp_send($sub, $payload);
    if ($r['ok']) {
      $sent++;
    } else {
      $failed++;
      if ($r['gone']) {
        db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$sub['id']]);
      }
    }
  }
  return ['sent' => $sent, 'failed' => $failed];
}
