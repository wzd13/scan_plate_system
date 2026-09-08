<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('POST');

$body = read_json_body_once();
$image = $body['image'] ?? '';
$mime = (string) ($body['mime_type'] ?? 'image/jpeg');

if (!is_string($image) || $image === '') {
    json_response(false, 'Image is required.', null, 422);
}

$allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
if (!in_array(strtolower($mime), $allowedMime, true)) {
    $mime = 'image/jpeg';
}

try {
    $result = recognize_plate_with_ai($image, $mime);

    if (!$result['ok']) {
        json_response(false, 'AI recognition failed.', [
            'request_id' => $result['request_id'],
            'fallback_used' => $result['fallback_used'],
            'model' => $result['model'],
        ], 502);
    }

    $plate = $result['plate'];
    $confidence = $result['confidence'];

    $autoLevel = strtoupper((string) get_setting('ai_confidence_auto', 'HIGH'));
    $verifyLevel = strtoupper((string) get_setting('ai_confidence_verify', 'MEDIUM'));

    $action = 'reject';
    if ($plate !== '' && is_valid_plate($plate)) {
        if ($confidence === $autoLevel || $confidence === 'HIGH') {
            $action = 'auto';
        } elseif ($confidence === $verifyLevel || $confidence === 'MEDIUM') {
            $action = 'verify';
        } else {
            $action = 'reject';
        }
    }

    $message = 'Plate detected: ' . ($plate !== '' ? $plate : '(none)');
    if ($action === 'reject') {
        $message = 'Plate recognition confidence is low. Please scan again or enter the plate manually.';
    } elseif ($action === 'verify') {
        $message = 'Please verify the detected plate: ' . $plate;
    }

    json_response(true, $message, [
        'plate' => $plate,
        'plate_number' => $plate,
        'confidence' => $confidence,
        'action' => $action,
        'model' => $result['model'],
        'fallback_used' => $result['fallback_used'],
        'request_id' => $result['request_id'],
        'valid_plate' => is_valid_plate($plate),
    ]);
} catch (Throwable $e) {
    app_log('error', 'Recognize plate exception', ['error' => $e->getMessage()]);
    json_response(false, 'AI recognition failed.', null, 502);
}
