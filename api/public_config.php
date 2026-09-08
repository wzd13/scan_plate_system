<?php
declare(strict_types=1);

/**
 * Public config for gate pages (no secrets).
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('GET');

$interval = (int) (get_setting('scan_interval_seconds', '4') ?? 4);
if ($interval < 2) {
    $interval = 2;
}
if ($interval > 60) {
    $interval = 60;
}

$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$hostOnly = strtolower(explode(':', $host)[0]);
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
$httpsOff = empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off';

json_response(true, 'OK', [
    'scan_interval_seconds' => $interval,
    'currency_symbol' => get_setting('currency_symbol', 'RM'),
    'csrf_token' => csrf_token(),
    'https_required_for_camera' => $httpsOff && !$isLocal,
]);
