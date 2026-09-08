<?php
declare(strict_types=1);

/**
 * Public read-only feed for recent parking records.
 * ?status=ACTIVE|COMPLETED|ALL  ?limit=10
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('GET');

$limit = (int) ($_GET['limit'] ?? 10);
if ($limit < 1) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

$status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
if (!in_array($status, ['ALL', 'ACTIVE', 'COMPLETED'], true)) {
    $status = 'ALL';
}

try {
    $pdo = db();
    $active = (int) $pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'ACTIVE'")->fetchColumn();
    $completedToday = 0;
    if (db_is_sqlite($pdo)) {
        $completedToday = (int) $pdo->query(
            "SELECT COUNT(*) FROM parking_logs
             WHERE status = 'COMPLETED' AND date(exit_time) = date('now', 'localtime')"
        )->fetchColumn();
    } else {
        $completedToday = (int) $pdo->query(
            "SELECT COUNT(*) FROM parking_logs
             WHERE status = 'COMPLETED' AND DATE(exit_time) = CURDATE()"
        )->fetchColumn();
    }

    if ($status === 'ALL') {
        $stmt = $pdo->query(
            "SELECT id, plate_number, entry_time, exit_time, duration_minutes, total_amount, status
             FROM parking_logs ORDER BY id DESC LIMIT {$limit}"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, plate_number, entry_time, exit_time, duration_minutes, total_amount, status
             FROM parking_logs WHERE status = :status ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute(['status' => $status]);
    }

    $rows = $stmt->fetchAll();
    $currency = get_setting('currency_symbol', 'RM') ?? 'RM';

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'id' => (int) $row['id'],
            'plate_number' => $row['plate_number'],
            'entry_time' => $row['entry_time'],
            'exit_time' => $row['exit_time'],
            'duration_label' => $row['duration_minutes'] !== null
                ? format_duration((int) $row['duration_minutes'])
                : null,
            'amount_label' => $row['total_amount'] !== null
                ? format_money((float) $row['total_amount'], $currency)
                : null,
            'status' => $row['status'],
        ];
    }

    json_response(true, 'OK', [
        'active_count' => $active,
        'completed_today' => $completedToday,
        'records' => $data,
    ]);
} catch (Throwable $e) {
    app_log('error', 'Recent records failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to load records.', null, 500);
}
