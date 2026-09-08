<?php
declare(strict_types=1);

/**
 * Confirm payment and complete parking session (transaction + row lock).
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('POST');
require_csrf_from_request();

$body = read_json_body_once();
$plate = normalize_plate($body['plate_number'] ?? ($body['plate'] ?? ''));
$sessionId = isset($body['session_id']) ? (int) $body['session_id'] : (isset($body['id']) ? (int) $body['id'] : 0);

if (!is_valid_plate($plate) && $sessionId <= 0) {
    json_response(false, 'Invalid plate number.', null, 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $lock = db_is_sqlite($pdo) ? '' : ' FOR UPDATE';

    if ($sessionId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM parking_logs WHERE id = :id' . $lock);
        $stmt->execute(['id' => $sessionId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM parking_logs WHERE plate_number = :plate AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1" . $lock
        );
        $stmt->execute(['plate' => $plate]);
    }

    $session = $stmt->fetch();
    if (!$session) {
        $pdo->rollBack();
        json_response(false, 'No active parking session found for this vehicle.', null, 404);
    }

    if (($session['status'] ?? '') !== 'ACTIVE') {
        $pdo->rollBack();
        json_response(false, 'This parking session is already completed.', null, 409);
    }

    $plate = (string) $session['plate_number'];
    $entry = new DateTimeImmutable((string) $session['entry_time']);
    $exit = new DateTimeImmutable('now');
    $bill = calculate_parking_fee($entry, $exit);

    if ($bill['total_amount'] < 0) {
        $pdo->rollBack();
        json_response(false, 'Invalid billing amount.', null, 500);
    }

    $update = $pdo->prepare(
        "UPDATE parking_logs SET
            exit_time = :exit_time,
            duration_minutes = :duration_minutes,
            pricing_type = :pricing_type,
            rate_applied = :rate_applied,
            total_amount = :total_amount,
            status = 'COMPLETED'
         WHERE id = :id AND status = 'ACTIVE'"
    );
    $update->execute([
        'exit_time' => $exit->format('Y-m-d H:i:s'),
        'duration_minutes' => $bill['duration_minutes'],
        'pricing_type' => $bill['pricing_type'],
        'rate_applied' => $bill['rate_applied'],
        'total_amount' => $bill['total_amount'],
        'id' => (int) $session['id'],
    ]);

    if ($update->rowCount() === 0) {
        $pdo->rollBack();
        json_response(false, 'This parking session is already completed.', null, 409);
    }

    $del = $pdo->prepare('DELETE FROM active_plates WHERE plate_number = :plate');
    $del->execute(['plate' => $plate]);

    $pdo->commit();

    app_log('info', 'Payment confirmation / checkout', [
        'plate' => $plate,
        'log_id' => (int) $session['id'],
        'total' => $bill['total_amount'],
    ]);

    json_response(true, 'Payment confirmed. Vehicle checked out successfully.', [
        'id' => (int) $session['id'],
        'plate_number' => $plate,
        'entry_time' => $session['entry_time'],
        'exit_time' => $exit->format('Y-m-d H:i:s'),
        'duration_minutes' => $bill['duration_minutes'],
        'duration_label' => format_duration($bill['duration_minutes']),
        'pricing_type' => $bill['pricing_type'],
        'rate_applied' => $bill['rate_applied'],
        'total_amount' => $bill['total_amount'],
        'currency' => $bill['currency'],
        'amount_label' => format_money($bill['total_amount'], $bill['currency']),
        'status' => 'COMPLETED',
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    app_log('error', 'Confirm payment failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to process the parking request. Please try again.', null, 500);
}
