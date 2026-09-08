<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('POST');

$body = read_json_body_once();
$plate = normalize_plate($body['plate_number'] ?? ($body['plate'] ?? ''));

if (!is_valid_plate($plate)) {
    json_response(false, 'Invalid plate number.', null, 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Lock any existing active row for this plate
    $lock = db_is_sqlite($pdo) ? '' : ' FOR UPDATE';
    $stmt = $pdo->prepare(
        "SELECT id FROM parking_logs WHERE plate_number = :plate AND status = 'ACTIVE'" . $lock
    );
    $stmt->execute(['plate' => $plate]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->rollBack();
        app_log('info', 'Duplicate check-in blocked', ['plate' => $plate]);
        json_response(false, "Vehicle {$plate} is already parked.", [
            'plate_number' => $plate,
            'already_active' => true,
        ], 409);
    }

    $entryTime = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        "INSERT INTO parking_logs (plate_number, entry_time, status) VALUES (:plate, :entry, 'ACTIVE')"
    );
    $insert->execute([
        'plate' => $plate,
        'entry' => $entryTime,
    ]);
    $logId = (int) $pdo->lastInsertId();

    $active = $pdo->prepare(
        'INSERT INTO active_plates (plate_number, parking_log_id) VALUES (:plate, :log_id)'
    );
    $active->execute([
        'plate' => $plate,
        'log_id' => $logId,
    ]);

    $pdo->commit();

    app_log('info', 'Vehicle check-in', ['plate' => $plate, 'log_id' => $logId]);

    json_response(true, 'Vehicle checked in successfully.', [
        'id' => $logId,
        'plate_number' => $plate,
        'entry_time' => $entryTime,
        'status' => 'ACTIVE',
    ], 200);
} catch (PDOException $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    // Unique constraint on active_plates
    if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
        app_log('info', 'Duplicate check-in (constraint)', ['plate' => $plate]);
        json_response(false, "Vehicle {$plate} is already parked.", [
            'plate_number' => $plate,
            'already_active' => true,
        ], 409);
    }
    app_log('error', 'Check-in failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to process the parking request. Please try again.', null, 500);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    app_log('error', 'Check-in exception', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to process the parking request. Please try again.', null, 500);
}
