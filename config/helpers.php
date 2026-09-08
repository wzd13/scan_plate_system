<?php
declare(strict_types=1);

/**
 * Shared helpers: JSON, validation, plates, billing, settings, AI, logging, CSRF.
 */

function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($uri, '/api/')
        || str_contains($accept, 'application/json')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

function json_response(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload = [
        'success' => $success,
        'message' => $message,
    ];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(false, 'Invalid JSON body.', null, 400);
    }
    return $decoded;
}

function require_method(string ...$methods): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        json_response(false, 'Method not allowed.', null, 405);
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_log(string $level, string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/app.log';
    $safeContext = redact_secrets($context);
    $line = sprintf(
        "[%s] %s %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $safeContext !== [] ? json_encode($safeContext, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function redact_secrets(array $context): array
{
    $keys = ['api_key', 'ai_api_key', 'password', 'token', 'secret', 'authorization'];
    foreach ($context as $k => $v) {
        $lk = strtolower((string) $k);
        foreach ($keys as $secret) {
            if (str_contains($lk, $secret)) {
                $context[$k] = '[REDACTED]';
                continue 2;
            }
        }
        if (is_array($v)) {
            $context[$k] = redact_secrets($v);
        }
    }
    return $context;
}

/** Normalize license plate for all entry/exit/search paths. */
function normalize_plate(?string $plate): string
{
    $plate = strtoupper(trim((string) $plate));
    $plate = preg_replace('/[\s\-]+/', '', $plate) ?? '';
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate) ?? '';
    if (strlen($plate) > 15) {
        $plate = substr($plate, 0, 15);
    }
    return $plate;
}

function is_valid_plate(string $plate): bool
{
    $len = strlen($plate);
    return $len >= 3 && $len <= 15 && (bool) preg_match('/^[A-Z0-9]+$/', $plate);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    if ($token === null || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf_from_request(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if ($token === null) {
        $body = read_json_body_once();
        $token = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }
    if (!verify_csrf(is_string($token) ? $token : null)) {
        json_response(false, 'Invalid CSRF token.', null, 403);
    }
}

/** Cache parsed JSON body for CSRF + handlers in same request. */
function read_json_body_once(): array
{
    static $cached = null;
    static $done = false;
    if ($done) {
        return $cached ?? [];
    }
    $done = true;
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        $cached = [];
        return $cached;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(false, 'Invalid JSON body.', null, 400);
    }
    $cached = $decoded;
    return $cached;
}

function &settings_cache(): array
{
    static $cache = [];
    return $cache;
}

function get_setting(string $key, ?string $default = null): ?string
{
    $cache = &settings_cache();
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    $cache[$key] = $row ? (string) $row['setting_value'] : $default;
    return $cache[$key];
}

function get_settings(array $keys): array
{
    if ($keys === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = db()->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute(array_values($keys));
    $out = array_fill_keys($keys, null);
    while ($row = $stmt->fetch()) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

function set_setting(string $key, string $value): void
{
    if (db_is_sqlite()) {
        $stmt = db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
        );
    } else {
        $stmt = db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
    }
    $stmt->execute(['k' => $key, 'v' => $value]);
    $cache = &settings_cache();
    $cache[$key] = $value;
}

function clear_settings_cache(): void
{
    $cache = &settings_cache();
    $cache = [];
}

function mask_api_key(?string $key): string
{
    $key = (string) $key;
    if ($key === '') {
        return '';
    }
    if (strlen($key) <= 8) {
        return str_repeat('*', strlen($key));
    }
    return substr($key, 0, 4) . str_repeat('*', max(8, strlen($key) - 8)) . substr($key, -4);
}

/**
 * Calculate parking bill server-side.
 *
 * @return array{pricing_type:string,rate_applied:float,duration_minutes:int,billable_minutes:int,total_amount:float,currency:string}
 */
function calculate_parking_fee(DateTimeInterface $entry, ?DateTimeInterface $exit = null, ?array $settings = null): array
{
    $exit = $exit ?? new DateTimeImmutable('now');
    $settings = $settings ?? get_settings([
        'pricing_mode', 'flat_rate', 'hourly_rate', 'grace_period', 'round_up_hours', 'currency_symbol',
    ]);

    $mode = strtolower((string) ($settings['pricing_mode'] ?? 'hourly'));
    if (!in_array($mode, ['flat', 'hourly'], true)) {
        $mode = 'hourly';
    }

    $flat = max(0.0, (float) ($settings['flat_rate'] ?? 0));
    $hourly = max(0.0, (float) ($settings['hourly_rate'] ?? 0));
    $grace = max(0, (int) ($settings['grace_period'] ?? 0));
    $roundUp = ((string) ($settings['round_up_hours'] ?? '1')) === '1'
        || strtolower((string) ($settings['round_up_hours'] ?? '')) === 'yes'
        || strtolower((string) ($settings['round_up_hours'] ?? '')) === 'true';
    $currency = (string) ($settings['currency_symbol'] ?? 'RM');

    $entryTs = $entry->getTimestamp();
    $exitTs = $exit->getTimestamp();
    if ($exitTs < $entryTs) {
        $exitTs = $entryTs;
    }
    $durationMinutes = (int) ceil(($exitTs - $entryTs) / 60);
    if ($durationMinutes < 0) {
        $durationMinutes = 0;
    }

    if ($mode === 'flat') {
        return [
            'pricing_type' => 'flat',
            'rate_applied' => round($flat, 2),
            'duration_minutes' => $durationMinutes,
            'billable_minutes' => $durationMinutes,
            'total_amount' => round($flat, 2),
            'currency' => $currency,
        ];
    }

    $billable = max(0, $durationMinutes - $grace);
    if ($billable <= 0) {
        $hours = 0;
    } elseif ($roundUp) {
        $hours = (int) ceil($billable / 60);
    } else {
        $hours = (int) floor($billable / 60);
    }

    $total = round($hours * $hourly, 2);

    return [
        'pricing_type' => 'hourly',
        'rate_applied' => round($hourly, 2),
        'duration_minutes' => $durationMinutes,
        'billable_minutes' => $billable,
        'billable_hours' => $hours,
        'total_amount' => $total,
        'currency' => $currency,
        'grace_period' => $grace,
        'round_up_hours' => $roundUp,
    ];
}

function format_duration(int $minutes): string
{
    $minutes = max(0, $minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0) {
        return sprintf('%dh %dm', $h, $m);
    }
    return sprintf('%dm', $m);
}

function format_money(float $amount, string $currency = 'RM'): string
{
    return $currency . ' ' . number_format($amount, 2);
}

function new_request_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Detect API style from provider/url settings.
 */
function ai_api_style(?string $provider = null, ?string $apiUrl = null): string
{
    $provider = $provider ?? (get_setting('ai_provider', '') ?? '');
    $apiUrl = $apiUrl ?? (get_setting('ai_api_url', '') ?? '');
    $hay = strtolower($provider . ' ' . $apiUrl);
    if (str_contains($hay, 'gemini') || str_contains($hay, 'generativelanguage.googleapis')) {
        return 'gemini';
    }
    return 'openai';
}

/**
 * Call AI Vision (Agnes OpenAI-compatible or Gemini) with primary then fallback models.
 *
 * @return array{ok:bool,plate:string,confidence:string,model:?string,fallback_used:bool,http_status:?int,error:?string,raw:?string,request_id:string}
 */
function recognize_plate_with_ai(string $imageBase64, string $mimeType = 'image/jpeg'): array
{
    $requestId = new_request_id();
    $provider = get_setting('ai_provider', 'Agnes AI') ?? 'Agnes AI';
    $primary = trim((string) get_setting('ai_model', 'agnes-2.5-flash'));
    $fallbackRaw = (string) get_setting('ai_fallback_models', 'agnes-2.0-flash');
    $fallbacks = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $fallbackRaw) ?: [])));
    $apiUrl = rtrim((string) get_setting('ai_api_url', 'https://apihub.agnes-ai.com/v1'), '/');
    $apiKey = (string) get_setting('ai_api_key', '');
    $style = ai_api_style($provider, $apiUrl);

    $result = [
        'ok' => false,
        'plate' => '',
        'confidence' => 'LOW',
        'model' => null,
        'fallback_used' => false,
        'http_status' => null,
        'error' => null,
        'raw' => null,
        'request_id' => $requestId,
        'provider' => $provider,
    ];

    if ($apiKey === '') {
        $result['error'] = 'AI API key is not configured.';
        log_ai_recognition($result);
        return $result;
    }

    $models = array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
    if ($models === []) {
        $result['error'] = 'No AI models configured.';
        log_ai_recognition($result);
        return $result;
    }

    $imageBase64 = preg_replace('#^data:[^;]+;base64,#', '', $imageBase64) ?? $imageBase64;
    $imageBase64 = preg_replace('/\s+/', '', $imageBase64) ?? $imageBase64;

    if ($imageBase64 === '' || strlen($imageBase64) < 100) {
        $result['error'] = 'Invalid image data.';
        log_ai_recognition($result);
        return $result;
    }

    if (strlen($imageBase64) > 5_500_000) {
        $result['error'] = 'Image is too large.';
        log_ai_recognition($result);
        return $result;
    }

    $prompt = <<<'PROMPT'
You are a license plate recognition system. Look at the vehicle image and extract the license plate number.
Respond with ONLY valid JSON in this exact format:
{"plate":"ABC1234","confidence":"HIGH"}
confidence must be one of: HIGH, MEDIUM, LOW.
If no readable plate exists, respond: {"plate":"","confidence":"LOW"}
Do not include markdown, code fences, or any other text.
PROMPT;

    $lastError = null;
    $lastStatus = null;

    foreach ($models as $index => $model) {
        $usedFallback = $index > 0;
        app_log('info', 'AI request', [
            'request_id' => $requestId,
            'provider' => $provider,
            'style' => $style,
            'model' => $model,
            'fallback_used' => $usedFallback,
        ]);

        if ($style === 'gemini') {
            $endpoint = $apiUrl . '/' . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);
            $payload = [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $imageBase64,
                            ],
                        ],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 128,
                ],
            ];
            $headers = ['Content-Type: application/json'];
        } else {
            // OpenAI-compatible (Agnes AI): https://apihub.agnes-ai.com/v1/chat/completions
            $endpoint = str_ends_with($apiUrl, '/chat/completions')
                ? $apiUrl
                : $apiUrl . '/chat/completions';
            $payload = [
                'model' => $model,
                'temperature' => 0.1,
                'max_tokens' => 128,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . $imageBase64,
                            ],
                        ],
                    ],
                ]],
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];
        }

        $response = http_post_json($endpoint, $payload, 45, $headers);
        $lastStatus = $response['status'];
        $lastError = $response['error'];

        if (!$response['ok']) {
            app_log('warning', 'AI failure', [
                'request_id' => $requestId,
                'model' => $model,
                'http_status' => $lastStatus,
                'error' => $lastError,
            ]);
            continue;
        }

        $parsed = parse_ai_plate_response((string) $response['body']);
        if ($parsed === null) {
            $lastError = 'Unable to parse AI response.';
            app_log('warning', 'AI parse failure', [
                'request_id' => $requestId,
                'model' => $model,
            ]);
            continue;
        }

        $plate = normalize_plate($parsed['plate']);
        $confidence = strtoupper($parsed['confidence']);
        if (!in_array($confidence, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $confidence = 'LOW';
        }

        $result['ok'] = true;
        $result['plate'] = $plate;
        $result['confidence'] = $confidence;
        $result['model'] = $model;
        $result['fallback_used'] = $usedFallback;
        $result['http_status'] = $lastStatus;
        $result['raw'] = substr((string) $response['body'], 0, 2000);
        $result['error'] = null;

        app_log('info', 'AI success', [
            'request_id' => $requestId,
            'model' => $model,
            'fallback_used' => $usedFallback,
            'plate' => $plate,
            'confidence' => $confidence,
        ]);

        log_ai_recognition($result);
        return $result;
    }

    $result['http_status'] = $lastStatus;
    $result['error'] = $lastError ?: 'AI recognition failed.';
    $result['model'] = $models[count($models) - 1] ?? null;
    $result['fallback_used'] = count($models) > 1;
    log_ai_recognition($result);
    return $result;
}

/**
 * @return array{plate:string,confidence:string}|null
 */
function parse_ai_plate_response(string $body): ?array
{
    $decoded = json_decode($body, true);
    $text = '';

    if (is_array($decoded)) {
        // Direct plate object
        if (isset($decoded['plate'])) {
            return [
                'plate' => (string) $decoded['plate'],
                'confidence' => strtoupper((string) ($decoded['confidence'] ?? 'LOW')),
            ];
        }
        // Gemini
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        // OpenAI / Agnes chat completions
        if ($text === '') {
            $text = $decoded['choices'][0]['message']['content'] ?? '';
            if (is_array($text)) {
                $joined = '';
                foreach ($text as $part) {
                    if (is_string($part)) {
                        $joined .= $part;
                    } elseif (is_array($part) && isset($part['text'])) {
                        $joined .= (string) $part['text'];
                    }
                }
                $text = $joined;
            }
        }
    }

    $text = trim((string) $text);
    if ($text === '') {
        return null;
    }

    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $m)) {
        $text = $m[1];
    }

    if (preg_match('/\{[^{}]*"plate"[^{}]*\}/s', $text, $m)) {
        $text = $m[0];
    }

    $json = json_decode($text, true);
    if (!is_array($json) || !array_key_exists('plate', $json)) {
        return null;
    }

    return [
        'plate' => (string) $json['plate'],
        'confidence' => strtoupper((string) ($json['confidence'] ?? 'LOW')),
    ];
}

/**
 * @param array<string,mixed> $result
 */
function log_ai_recognition(array $result): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO ai_recognition_logs
            (request_id, provider, model, fallback_used, http_status, recognized_plate, confidence, result, error_message)
            VALUES (:request_id, :provider, :model, :fallback_used, :http_status, :recognized_plate, :confidence, :result, :error_message)'
        );
        $stmt->execute([
            'request_id' => (string) ($result['request_id'] ?? new_request_id()),
            'provider' => (string) ($result['provider'] ?? 'Google Gemini Vision'),
            'model' => $result['model'] ?? null,
            'fallback_used' => !empty($result['fallback_used']) ? 1 : 0,
            'http_status' => $result['http_status'] ?? null,
            'recognized_plate' => $result['plate'] ?? null,
            'confidence' => $result['confidence'] ?? null,
            'result' => isset($result['raw']) ? substr((string) $result['raw'], 0, 2000) : null,
            'error_message' => $result['error'] ?? null,
        ]);
    } catch (Throwable $e) {
        app_log('error', 'Failed to write AI recognition log', ['error' => $e->getMessage()]);
    }
}

/**
 * @param list<string> $headers
 * @return array{ok:bool,status:?int,body:?string,error:?string}
 */
function http_post_json(string $url, array $payload, int $timeout = 30, array $headers = []): array
{
    $json = json_encode($payload);
    if ($json === false) {
        return ['ok' => false, 'status' => null, 'body' => null, 'error' => 'Failed to encode request.'];
    }

    if ($headers === []) {
        $headers = ['Content-Type: application/json'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => $status ?: null, 'body' => null, 'error' => $err ?: 'HTTP request failed.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => 'AI API HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null];
    }

    $headerLine = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headerLine,
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = null;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'error' => 'HTTP request failed.'];
    }
    if ($status !== null && ($status < 200 || $status >= 300)) {
        return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => 'AI API HTTP ' . $status];
    }
    return ['ok' => true, 'status' => $status ?? 200, 'body' => $body, 'error' => null];
}

function find_active_session(string $plate): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM parking_logs WHERE plate_number = :plate AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['plate' => $plate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // /number_plate_scanner_system/index.php -> /number_plate_scanner_system
    // /number_plate_scanner_system/admin/login.php -> /number_plate_scanner_system
    $dir = str_replace('\\', '/', dirname($script));
    if (str_ends_with($dir, '/admin') || str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = base_path();
    return $base . ($path !== '' ? '/' . $path : '/');
}
