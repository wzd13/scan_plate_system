<?php
declare(strict_types=1);

/**
 * Offline unit checks (no database required).
 * Run: php tests/unit_smoke.php
 */

require_once dirname(__DIR__) . '/config/helpers.php';

$failed = 0;
function assert_true(bool $cond, string $name): void
{
    global $failed;
    if ($cond) {
        echo "OK  $name\n";
    } else {
        echo "FAIL $name\n";
        $failed++;
    }
}

assert_true(normalize_plate('abc 1234') === 'ABC1234', 'normalize spaces');
assert_true(normalize_plate('ab-c-12') === 'ABC12', 'normalize hyphens');
assert_true(normalize_plate('a@b#c') === 'ABC', 'normalize strip symbols');
assert_true(is_valid_plate('ABC1234') === true, 'valid plate');
assert_true(is_valid_plate('AB') === false, 'too short plate');
assert_true(is_valid_plate('') === false, 'empty plate');

$entry = new DateTimeImmutable('2026-01-01 10:00:00');
$exit = new DateTimeImmutable('2026-01-01 12:10:00'); // 130 minutes
$settings = [
    'pricing_mode' => 'hourly',
    'flat_rate' => '5.00',
    'hourly_rate' => '2.00',
    'grace_period' => '15',
    'round_up_hours' => '1',
    'currency_symbol' => 'RM',
];
$bill = calculate_parking_fee($entry, $exit, $settings);
// 130 - 15 = 115 min → ceil to 2 hours × 2 = 4
assert_true($bill['duration_minutes'] === 130, 'duration minutes');
assert_true($bill['billable_minutes'] === 115, 'billable after grace');
assert_true(($bill['billable_hours'] ?? null) === 2, 'rounded hours');
assert_true(abs($bill['total_amount'] - 4.0) < 0.001, 'hourly total RM4');

$flat = calculate_parking_fee($entry, $exit, array_merge($settings, ['pricing_mode' => 'flat']));
assert_true(abs($flat['total_amount'] - 5.0) < 0.001, 'flat total RM5');

$parsed = parse_ai_plate_response(json_encode([
    'candidates' => [[
        'content' => ['parts' => [['text' => '{"plate":"xyz 999","confidence":"HIGH"}']]],
    ]],
]));
assert_true(is_array($parsed) && $parsed['plate'] === 'xyz 999', 'parse AI json plate');
assert_true(is_array($parsed) && $parsed['confidence'] === 'HIGH', 'parse AI confidence');

$bad = parse_ai_plate_response('{"candidates":[{"content":{"parts":[{"text":"no json here"}]}}]}');
assert_true($bad === null, 'reject bad AI text');

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
