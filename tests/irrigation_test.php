<?php
// Unit tests for the pure scheduling logic in irrigation_lib.php.
//
//   php tests/irrigation_test.php
//
// No database and no network: everything tested here takes values and returns
// values. That is the whole reason the library keeps its pure half separate.

require __DIR__ . '/../irrigation_lib.php';

$pass = 0; $fail = 0; $failures = [];

function ok($cond, $what) {
  global $pass, $fail, $failures;
  if ($cond) { $pass++; return; }
  $fail++; $failures[] = $what;
}
function eq($got, $want, $what) {
  $same = is_float($want) || is_float($got)
        ? (abs((float)$got - (float)$want) < 1e-6) : ($got === $want);
  global $pass, $fail, $failures;
  if ($same) { $pass++; return; }
  $fail++;
  $failures[] = sprintf('%s  (got %s, want %s)', $what,
                        var_export($got, true), var_export($want, true));
}

$TZ = new DateTimeZone('America/Los_Angeles');
function at($s) { global $TZ; return new DateTimeImmutable($s, $TZ); }

// ---- start times -----------------------------------------------------------
eq(irr_parse_times('06:00,18:30'),     ['06:00','18:30'], 'parse two times');
eq(irr_parse_times(' 6:05 , 18:30 '),  ['06:05','18:30'], 'trims and zero-pads');
eq(irr_parse_times('06:00,06:00'),     ['06:00'],         'dedupes');
eq(irr_parse_times('25:00,06:61,x,,'), [],                'drops impossible times');
eq(irr_parse_times('00:00'),           ['00:00'],         'midnight is valid');

// ---- days ------------------------------------------------------------------
eq(irr_parse_dow('0,1,6'),   [0,1,6], 'parse dow');
eq(irr_parse_dow('7,-1,x'),  [],      'drops out-of-range dow');

$dowProg = ['days_mode'=>'dow', 'days_of_week'=>'1,3,5'];       // Mon/Wed/Fri
ok(irr_day_matches($dowProg, at('2026-09-14 06:00')), 'Monday matches');   // Mon
ok(!irr_day_matches($dowProg, at('2026-09-15 06:00')), 'Tuesday does not');
ok(irr_day_matches($dowProg, at('2026-09-16 06:00')), 'Wednesday matches');
ok(!irr_day_matches(['days_mode'=>'dow','days_of_week'=>''], at('2026-09-14 06:00')),
   'empty dow never runs');

$ivl = ['days_mode'=>'interval', 'interval_days'=>3, 'interval_anchor'=>'2026-09-13'];
ok(irr_day_matches($ivl, at('2026-09-13 06:00')),  'interval: anchor day runs');
ok(!irr_day_matches($ivl, at('2026-09-14 06:00')), 'interval: +1 does not');
ok(!irr_day_matches($ivl, at('2026-09-15 06:00')), 'interval: +2 does not');
ok(irr_day_matches($ivl, at('2026-09-16 06:00')),  'interval: +3 runs');
ok(irr_day_matches($ivl, at('2026-10-01 06:00')),  'interval: +18 runs (divisible)');
ok(!irr_day_matches($ivl, at('2026-09-12 06:00')), 'interval: before anchor never runs');
ok(irr_day_matches(['days_mode'=>'interval','interval_days'=>3,'interval_anchor'=>null],
   at('2026-09-14 06:00')), 'interval with no anchor runs daily');
ok(irr_day_matches(['days_mode'=>'interval','interval_days'=>0,'interval_anchor'=>'2026-09-13'],
   at('2026-09-14 06:00')), 'interval 0 is clamped to 1 (daily), not a divide by zero');

// ---- time matching ---------------------------------------------------------
$p = ['start_times'=>'06:00,18:30'];
eq(irr_time_matches($p, at('2026-09-14 06:00:00')), '06:00', 'fires at 06:00');
eq(irr_time_matches($p, at('2026-09-14 06:00:59')), '06:00', 'fires anywhere in the minute');
eq(irr_time_matches($p, at('2026-09-14 06:01:00')), null,    'does not fire a minute late');
eq(irr_time_matches($p, at('2026-09-14 18:30:00')), '18:30', 'fires at the second time');
eq(irr_fire_key(at('2026-09-14 06:00'), '06:00'), '2026-09-14 06:00', 'fire key');

// ---- duration adjustment ---------------------------------------------------
eq(irr_adjust_minutes(10, 100, 1.0),  10.0,  'no adjustment');
eq(irr_adjust_minutes(10, 50,  1.0),  5.0,   'seasonal 50%');
eq(irr_adjust_minutes(10, 150, 1.0),  15.0,  'seasonal 150%');
eq(irr_adjust_minutes(10, 100, 1.2),  12.0,  'weather x1.2');
eq(irr_adjust_minutes(10, 50,  1.2),  6.0,   'seasonal and weather compound');
eq(irr_adjust_minutes(10, 0,   1.0),  0.0,   'seasonal 0% waters nothing');
eq(irr_adjust_minutes(10, -50, 1.0),  0.0,   'negative seasonal floors at zero');

// ---- weather ---------------------------------------------------------------
$s = ['rain_skip_mm'=>6, 'temp_baseline_c'=>21, 'temp_pct_per_c'=>3];

$d = irr_weather_decide(['past_mm'=>8, 'today_mm'=>0, 'today_tmax_c'=>25], $s);
ok($d['skip'], 'skips on rain in the last 24h');
eq($d['reason'], 'rain', 'reason is rain');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>9, 'today_tmax_c'=>25], $s);
ok($d['skip'], 'skips on forecast rain');
eq($d['reason'], 'forecast', 'reason is forecast');

$d = irr_weather_decide(['past_mm'=>6, 'today_mm'=>6, 'today_tmax_c'=>21], $s);
ok(!$d['skip'], 'exactly at the limit does not skip');
eq($d['factor'], 1.0, 'baseline temp means no scaling');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>0, 'today_tmax_c'=>31], $s);
eq($d['factor'], 1.3, '10C above baseline at 3%/C is x1.30');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>0, 'today_tmax_c'=>11], $s);
eq($d['factor'], 0.7, '10C below baseline is x0.70');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>0, 'today_tmax_c'=>60], $s);
eq($d['factor'], 1.5, 'scaling is clamped at 1.5x');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>0, 'today_tmax_c'=>-30], $s);
eq($d['factor'], 0.5, 'scaling is clamped at 0.5x');

$d = irr_weather_decide(['past_mm'=>0, 'today_mm'=>0, 'today_tmax_c'=>null], $s);
eq($d['factor'], 1.0, 'missing temperature means no scaling, not no water');

$w = irr_weather_extract(['daily'=>['time'=>['2026-09-12','2026-09-13'],
       'precipitation_sum'=>[7.5, 0.2], 'temperature_2m_max'=>[24.0, 29.5]]]);
eq($w['past_mm'], 7.5,       'extract: yesterday is index 0');
eq($w['today_mm'], 0.2,      'extract: today is index 1');
eq($w['today_tmax_c'], 29.5, 'extract: today max temp');
eq(irr_weather_extract(['daily'=>[]]), null, 'extract: empty daily is null');
eq(irr_weather_extract(null), null,          'extract: junk is null');

// ---- soil ------------------------------------------------------------------
ok(irr_soil_skip(45, 40),      'skips when soil is at or above the limit');
ok(irr_soil_skip(40, 40),      'skips exactly at the limit');
ok(!irr_soil_skip(39, 40),     'waters below the limit');
ok(!irr_soil_skip(99, null),   'no limit set means never skip');
ok(!irr_soil_skip(null, 40),   'a missing reading waters rather than skipping');

// ---- comparisons -----------------------------------------------------------
ok(irr_compare(5, '>', 4),   'gt');
ok(!irr_compare(4, '>', 4),  'gt is strict');
ok(irr_compare(4, '>=', 4),  'gte');
ok(irr_compare(3, '<', 4),   'lt');
ok(irr_compare(4, '<=', 4),  'lte');
ok(irr_compare(4, '==', 4),  'eq');
ok(irr_compare(4, '!=', 5),  'neq');
ok(!irr_compare(4, '~', 5),  'unknown operator is false, not true');

// ---- sequential vs parallel ------------------------------------------------
$q = [['id'=>3,'seq'=>20], ['id'=>1,'seq'=>10], ['id'=>2,'seq'=>10]];
$got = irr_next_to_start($q, [], true);
eq(count($got), 1, 'sequential starts one zone');
eq($got[0]['id'], 1, 'sequential starts the lowest seq, ties broken by id');
eq(count(irr_next_to_start($q, [['id'=>9]], true)), 0, 'sequential waits while one runs');
eq(count(irr_next_to_start($q, [], false)), 3, 'parallel starts everything');
eq(count(irr_next_to_start($q, [['id'=>9]], false)), 3, 'parallel ignores what is running');
eq(count(irr_next_to_start([], [], true)), 0, 'nothing queued starts nothing');

// ---- gallons ---------------------------------------------------------------
eq(irr_gallons(100.0, 137.5), 37.5, 'gallons differenced from the running total');
eq(irr_gallons(100.0, 100.0), 0.0,  'no flow is zero, not null');
eq(irr_gallons(100.0, 5.0),   null, 'a meter reset mid-run reports unknown, not negative');
eq(irr_gallons(null, 50.0),   null, 'no start total means unknown');
eq(irr_gallons(50.0, null),   null, 'no end total means unknown');

// ---- next occurrence -------------------------------------------------------
$daily = ['days_mode'=>'dow', 'days_of_week'=>'0,1,2,3,4,5,6', 'start_times'=>'06:00,18:30'];
eq(irr_next_occurrence($daily, at('2026-09-14 05:00'))->format('Y-m-d H:i'), '2026-09-14 06:00',
   'next run later the same morning');
eq(irr_next_occurrence($daily, at('2026-09-14 06:00'))->format('Y-m-d H:i'), '2026-09-14 18:30',
   'a start exactly now is past, next is the evening');
eq(irr_next_occurrence($daily, at('2026-09-14 19:00'))->format('Y-m-d H:i'), '2026-09-15 06:00',
   'after the last start, next is tomorrow');

$monOnly = ['days_mode'=>'dow', 'days_of_week'=>'1', 'start_times'=>'07:00'];
eq(irr_next_occurrence($monOnly, at('2026-09-15 09:00'))->format('Y-m-d H:i'), '2026-09-21 07:00',
   'weekly program rolls to the next matching weekday');

eq(irr_next_occurrence(['days_mode'=>'dow','days_of_week'=>'','start_times'=>'06:00'],
   at('2026-09-14 05:00')), null, 'a program that never matches has no next run');
eq(irr_next_occurrence(['days_mode'=>'dow','days_of_week'=>'1','start_times'=>''],
   at('2026-09-14 05:00')), null, 'no valid start times means no next run');

$ivl2 = ['days_mode'=>'interval','interval_days'=>3,'interval_anchor'=>'2026-09-13','start_times'=>'05:00'];
eq(irr_next_occurrence($ivl2, at('2026-09-14 00:00'))->format('Y-m-d H:i'), '2026-09-16 05:00',
   'interval program finds its next cycle day');

// ---- report ----------------------------------------------------------------
printf("\n%d passed, %d failed\n", $pass, $fail);
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($fail === 0 ? 0 : 1);
