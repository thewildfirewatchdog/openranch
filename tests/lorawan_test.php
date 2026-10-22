<?php
// Unit tests for the pure half of the LoRaWAN receiver, lorawan_lib.php.
//
//   php tests/lorawan_test.php
//
// No database and no network: every function here takes a decoded webhook body
// and returns values.

require __DIR__ . '/../lorawan_lib.php';

$pass = 0; $fail = 0; $failures = [];

function ok($cond, $what) {
  global $pass, $fail, $failures;
  if ($cond) { $pass++; return; }
  $fail++; $failures[] = $what;
}
function eq($got, $want, $what) {
  global $pass, $fail, $failures;
  if ($got === $want) { $pass++; return; }
  $fail++;
  $failures[] = sprintf('%s  (got %s, want %s)', $what,
                        var_export($got, true), var_export($want, true));
}

// ---- DevEUI ----------------------------------------------------------------
eq(lw_normalise_eui('70b3d57ed00012ab'),        '70B3D57ED00012AB', 'uppercases');
eq(lw_normalise_eui('70-B3-D5-7E-D0-00-12-AB'), '70B3D57ED00012AB', 'drops dashes');
eq(lw_normalise_eui('70:b3:d5:7e:d0:00:12:ab'), '70B3D57ED00012AB', 'drops colons');
eq(lw_normalise_eui('70B3D57ED00012'),          null,               'too short');
eq(lw_normalise_eui('70B3D57ED00012AB00'),      null,               'too long');
eq(lw_normalise_eui(null),                      null,               'missing');

// ---- decoded payload -------------------------------------------------------
eq(lw_flatten(['level_pct' => 62, 'BatV' => 3.6]), ['level_pct' => 62.0, 'BatV' => 3.6], 'numbers kept');
eq(lw_flatten(['full' => true, 'dry' => false]),   ['full' => 1.0, 'dry' => 0.0],       'booleans become 1/0');
eq(lw_flatten(['v' => '3.6', 'mode' => 'LoRa']),   ['v' => 3.6],                        'numeric strings kept, text dropped');
eq(lw_flatten(['bat' => ['v' => 3.6]]),            ['bat_v' => 3.6],                    'one level of nesting');
eq(lw_flatten(['a' => ['b' => ['c' => 1]]]),       [],                                  'deeper nesting skipped');
eq(lw_flatten(['list' => [1, 2, 3]]),              [],                                  'lists skipped');
eq(lw_flatten(['Temp C' => 20, 'x' => null]),      ['Temp_C' => 20.0],                  'names cleaned, nulls dropped');
eq(count(lw_flatten(array_fill_keys(array_map(fn($i) => "v$i", range(1, 100)), 1))), LW_MAX_VARS, 'capped');
eq(lw_flatten('not an object'),                    [],                                  'non-array payload');

// ---- radio -----------------------------------------------------------------
eq(lw_best_radio([['rssi' => -120, 'snr' => -3], ['rssi' => -101, 'snr' => 4]]),
   ['rssi' => -101.0, 'snr' => 4.0], 'strongest gateway wins');
eq(lw_best_radio([['rssi' => -99]]), ['rssi' => -99.0], 'snr optional');
eq(lw_best_radio([]), [], 'no gateways');

// ---- The Things Stack ------------------------------------------------------
$ttn = ['end_device_ids' => ['device_id' => 'tank-1', 'dev_eui' => '70b3d57ed0012345'],
        'uplink_message' => ['decoded_payload' => ['level_pct' => 62, 'rssi' => -50],
                             'rx_metadata' => [['rssi' => -97, 'snr' => 7.5]]]];
$r = lw_parse($ttn);
eq($r['kind'], 'uplink', 'ttn uplink');
eq($r['source'], 'ttn', 'ttn source');
eq($r['dev_eui'], '70B3D57ED0012345', 'ttn eui');
eq($r['name'], 'tank-1', 'ttn name');
eq($r['values'], ['level_pct' => 62.0, 'rssi' => -50.0, 'snr' => 7.5], "decoder's own rssi wins over the gateway's");
eq(lw_parse(['end_device_ids' => ['dev_eui' => '70B3D57ED0012345'], 'join_accept' => []])['kind'],
   'other', 'ttn join is not an uplink');

// ---- ChirpStack ------------------------------------------------------------
$cs = ['deviceInfo' => ['deviceName' => 'relay', 'devEui' => 'a840410000000001'], 'fCnt' => 3,
       'object' => ['RO1' => true], 'rxInfo' => [['rssi' => -110, 'snr' => 1]]];
$r = lw_parse($cs, 'up');
eq($r['kind'], 'uplink', 'chirpstack uplink');
eq($r['dev_eui'], 'A840410000000001', 'chirpstack eui');
eq($r['values'], ['RO1' => 1.0, 'rssi' => -110.0, 'snr' => 1.0], 'chirpstack values');
eq(lw_parse($cs, '')['kind'], 'uplink', 'no event param: fCnt marks an uplink');
eq(lw_parse($cs, 'status')['kind'], 'other', 'status event ignored');
$noCnt = $cs; unset($noCnt['fCnt']);
eq(lw_parse($noCnt, '')['kind'], 'other', 'no event and no fCnt is not an uplink');
eq(lw_parse(['devEUI' => '70B3D57ED0012345', 'fCnt' => 1, 'object' => ['t' => 1]])['dev_eui'],
   '70B3D57ED0012345', 'chirpstack v3 top-level devEUI');

// ---- unknown ---------------------------------------------------------------
eq(lw_parse(['hello' => 1])['source'], null, 'unknown body');
eq(lw_parse(null)['source'], null, 'null body');

// ---- slugs and template variables ------------------------------------------
eq(lw_slug_base('Tank Float', '70B3D57ED00012AB'), 'tank_float_012ab', 'slug from name + eui');
eq(lw_slug_base('', '70B3D57ED00012AB'), 'lora_012ab', 'slug without a name');
ok(strlen(lw_slug_base(str_repeat('x', 200), '70B3D57ED00012AB')) <= 47, 'slug leaves room for a counter');
eq(lw_merge_vars('a,b', ['b', 'c']), 'a,b,c', 'merge adds only new names');
eq(lw_merge_vars('', ['a']), 'a', 'merge into empty');

// ---- report ----------------------------------------------------------------
printf("\n%d passed, %d failed\n", $pass, $fail);
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($fail === 0 ? 0 : 1);
