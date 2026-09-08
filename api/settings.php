<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

require_method('GET', 'POST');
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $keys = [
        'pricing_mode', 'flat_rate', 'hourly_rate', 'grace_period', 'round_up_hours', 'currency_symbol',
        'ai_provider', 'ai_model', 'ai_fallback_models', 'ai_api_url', 'ai_api_key',
        'ai_confidence_auto', 'ai_confidence_verify', 'scan_interval_seconds',
    ];
    $settings = get_settings($keys);
    $settings['ai_api_key_masked'] = mask_api_key($settings['ai_api_key'] ?? '');
    $settings['ai_api_key_set'] = ($settings['ai_api_key'] ?? '') !== '';
    unset($settings['ai_api_key']); // never return full key
    json_response(true, 'Settings loaded.', $settings);
}

require_csrf_from_request();
$body = read_json_body_once();
$section = (string) ($body['section'] ?? 'all');

try {
    if ($section === 'billing' || $section === 'all') {
        $mode = strtolower(trim((string) ($body['pricing_mode'] ?? get_setting('pricing_mode', 'hourly'))));
        if (!in_array($mode, ['flat', 'hourly'], true)) {
            json_response(false, 'Invalid pricing mode.', null, 422);
        }
        $flat = (float) ($body['flat_rate'] ?? get_setting('flat_rate', '0'));
        $hourly = (float) ($body['hourly_rate'] ?? get_setting('hourly_rate', '0'));
        $grace = (int) ($body['grace_period'] ?? get_setting('grace_period', '0'));
        $roundUp = $body['round_up_hours'] ?? get_setting('round_up_hours', '1');
        $roundUpVal = in_array((string) $roundUp, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';

        if ($flat < 0 || $hourly < 0 || $grace < 0) {
            json_response(false, 'Rates and grace period must be zero or positive.', null, 422);
        }

        set_setting('pricing_mode', $mode);
        set_setting('flat_rate', number_format($flat, 2, '.', ''));
        set_setting('hourly_rate', number_format($hourly, 2, '.', ''));
        set_setting('grace_period', (string) $grace);
        set_setting('round_up_hours', $roundUpVal);

        if (isset($body['currency_symbol'])) {
            $sym = substr(trim((string) $body['currency_symbol']), 0, 10);
            if ($sym !== '') {
                set_setting('currency_symbol', $sym);
            }
        }
    }

    if ($section === 'ai' || $section === 'all') {
        if (isset($body['ai_provider'])) {
            set_setting('ai_provider', substr(trim((string) $body['ai_provider']), 0, 100));
        }
        if (isset($body['ai_model'])) {
            $model = trim((string) $body['ai_model']);
            if ($model === '') {
                json_response(false, 'Primary AI model is required.', null, 422);
            }
            set_setting('ai_model', substr($model, 0, 100));
        }
        if (isset($body['ai_fallback_models'])) {
            set_setting('ai_fallback_models', substr(trim((string) $body['ai_fallback_models']), 0, 255));
        }
        if (isset($body['ai_api_url'])) {
            $apiUrl = trim((string) $body['ai_api_url']);
            if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                json_response(false, 'Invalid AI API URL.', null, 422);
            }
            set_setting('ai_api_url', substr($apiUrl, 0, 500));
        }
        if (array_key_exists('ai_api_key', $body)) {
            $key = trim((string) $body['ai_api_key']);
            // Empty or masked placeholder means keep existing
            if ($key !== '' && !preg_match('/^\*+$/', $key) && !str_contains($key, '****')) {
                set_setting('ai_api_key', $key);
            }
        }
        if (isset($body['ai_confidence_auto'])) {
            $v = strtoupper(trim((string) $body['ai_confidence_auto']));
            if (in_array($v, ['HIGH', 'MEDIUM', 'LOW'], true)) {
                set_setting('ai_confidence_auto', $v);
            }
        }
        if (isset($body['ai_confidence_verify'])) {
            $v = strtoupper(trim((string) $body['ai_confidence_verify']));
            if (in_array($v, ['HIGH', 'MEDIUM', 'LOW'], true)) {
                set_setting('ai_confidence_verify', $v);
            }
        }
        if (isset($body['scan_interval_seconds'])) {
            $interval = (int) $body['scan_interval_seconds'];
            if ($interval < 2 || $interval > 60) {
                json_response(false, 'Scan interval must be between 2 and 60 seconds.', null, 422);
            }
            set_setting('scan_interval_seconds', (string) $interval);
        }
    }

    app_log('info', 'Settings updated', ['section' => $section, 'admin' => current_admin_username()]);
    json_response(true, 'Settings saved successfully.');
} catch (Throwable $e) {
    app_log('error', 'Settings update failed', ['error' => $e->getMessage()]);
    json_response(false, 'Unable to save settings.', null, 500);
}
