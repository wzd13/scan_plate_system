<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('POST');
require_admin_api();
require_csrf_from_request();

/**
 * Build a small valid JPEG for connection testing.
 */
function test_jpeg_base64(): string
{
    $candidates = [
        dirname(__DIR__) . '/assets/img/ai_test.jpg',
        dirname(__DIR__) . '/data/test_plate.jpg',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $bytes = file_get_contents($path);
            if (is_string($bytes) && strlen($bytes) > 500) {
                return base64_encode($bytes);
            }
        }
    }

    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(320, 180);
        $bg = imagecolorallocate($im, 30, 30, 30);
        $fg = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 319, 179, $bg);
        imagestring($im, 5, 90, 80, 'TEST IMG', $fg);
        ob_start();
        imagejpeg($im, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($im);
        if (is_string($jpeg) && $jpeg !== '') {
            return base64_encode($jpeg);
        }
    }

    throw new RuntimeException('No valid test image available.');
}

try {
    $result = recognize_plate_with_ai(test_jpeg_base64(), 'image/jpeg');

    if (($result['error'] ?? '') === 'AI API key is not configured.') {
        json_response(false, 'AI API key is not configured.', [
            'connection' => 'failed',
            'fallback_used' => false,
        ], 422);
    }

    if ($result['ok']) {
        json_response(true, 'Connection successful.', [
            'connection' => 'successful',
            'provider' => $result['provider'] ?? get_setting('ai_provider'),
            'model' => $result['model'],
            'fallback_used' => $result['fallback_used'],
            'request_id' => $result['request_id'],
            'plate' => $result['plate'],
            'confidence' => $result['confidence'],
            'note' => 'API responded; test image may not contain a plate.',
        ]);
    }

    if ($result['http_status'] === 401 || $result['http_status'] === 403) {
        json_response(false, 'AI authentication failed. Check your API key.', [
            'connection' => 'failed',
            'http_status' => $result['http_status'],
            'fallback_used' => $result['fallback_used'],
            'model' => $result['model'],
        ], 502);
    }

    json_response(false, 'AI recognition failed.', [
        'connection' => 'failed',
        'model' => $result['model'],
        'fallback_used' => $result['fallback_used'],
        'http_status' => $result['http_status'],
        'request_id' => $result['request_id'],
        'error' => $result['error'] ?? null,
    ], 502);
} catch (Throwable $e) {
    app_log('error', 'Test AI connection failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to test AI connection.', null, 500);
}
