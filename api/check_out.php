<?php
declare(strict_types=1);

/**
 * Get bill for an active parking session (does not complete checkout).
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('POST', 'GET');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $plate = normalize_plate($_GET['plate'] ?? ($_GET['plate_number'] ?? ''));
} else {
    $body = read_json_body_once();
    $plate = normalize_plate($body['plate_number'] ?? ($body['plate'] ?? ''));
}

if (!is_valid_plate($plate)) {
    json_response(false, 'Invalid plate number.', null, 422);
}

try {
    $session = find_active_session($plate);
    if (!$session) {
        json_response(false, 'No active parking session found for this vehicle.', [
            'plate_number' => $plate,
        ], 404);
    }

    $entry = new DateTimeImmutable((string) $session['entry_time']);
    $bill = calculate_parking_fee($entry);

    app_log('info', 'Bill calculation', [
        'plate' => $plate,
        'total' => $bill['total_amount'],
        'duration' => $bill['duration_minutes'],
    ]);

    json_response(true, 'Bill calculated successfully.', [
        'id' => (int) $session['id'],
        'plate_number' => $plate,
        'entry_time' => $session['entry_time'],
        'exit_time' => null,
        'duration_minutes' => $bill['duration_minutes'],
        'duration_label' => format_duration($bill['duration_minutes']),
        'pricing_type' => $bill['pricing_type'],
        'rate_applied' => $bill['rate_applied'],
        'total_amount' => $bill['total_amount'],
        'currency' => $bill['currency'],
        'amount_label' => format_money($bill['total_amount'], $bill['currency']),
        'status' => 'ACTIVE',
        'grace_period' => $bill['grace_period'] ?? null,
        'billable_hours' => $bill['billable_hours'] ?? null,
    ]);
} catch (Throwable $e) {
    app_log('error', 'Check-out bill failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to process the parking request. Please try again.', null, 500);
}
