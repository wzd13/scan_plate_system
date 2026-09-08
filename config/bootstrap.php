<?php
declare(strict_types=1);

/**
 * Application bootstrap: PDO (SQLite auto-setup), sessions, helpers.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set(getenv('PARKING_TZ') ?: 'Asia/Kuala_Lumpur');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/database.php';
    $driver = $cfg['driver'] ?? 'sqlite';

    try {
        if ($driver === 'sqlite') {
            $path = (string) ($cfg['path'] ?? (dirname(__DIR__) . '/data/parking.sqlite'));
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'] ?? '127.0.0.1',
                $cfg['port'] ?? '3306',
                $cfg['dbname'] ?? 'parking_system',
                $cfg['charset'] ?? 'utf8mb4'
            );
            $pdo = new PDO($dsn, (string) ($cfg['username'] ?? 'root'), (string) ($cfg['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        ensure_schema($pdo);
    } catch (PDOException $e) {
        app_log('error', 'Database connection failed', ['error' => $e->getMessage()]);
        if (is_api_request()) {
            json_response(false, 'Unable to connect to the database.', null, 500);
        }
        http_response_code(500);
        echo 'Unable to connect to the database.';
        exit;
    }

    return $pdo;
}

function db_is_sqlite(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

    if ($sqlite) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS parking_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plate_number TEXT NOT NULL,
                entry_time TEXT NOT NULL,
                exit_time TEXT NULL,
                duration_minutes INTEGER NULL,
                pricing_type TEXT NULL,
                rate_applied REAL NULL,
                total_amount REAL NULL,
                status TEXT NOT NULL DEFAULT \'ACTIVE\' CHECK(status IN (\'ACTIVE\', \'COMPLETED\')),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_plate_status ON parking_logs (plate_number, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_status ON parking_logs (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_time ON parking_logs (entry_time)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS active_plates (
                plate_number TEXT NOT NULL PRIMARY KEY,
                parking_log_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parking_log_id) REFERENCES parking_logs(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_recognition_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                model TEXT NULL,
                fallback_used INTEGER NOT NULL DEFAULT 0,
                http_status INTEGER NULL,
                recognized_plate TEXT NULL,
                confidence TEXT NULL,
                result TEXT NULL,
                error_message TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_request_id ON ai_recognition_logs (request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_created_at ON ai_recognition_logs (created_at)');
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              username VARCHAR(50) NOT NULL UNIQUE,
              password VARCHAR(255) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS settings (
              setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
              setting_value TEXT NULL,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS parking_logs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              plate_number VARCHAR(20) NOT NULL,
              entry_time DATETIME NOT NULL,
              exit_time DATETIME NULL,
              duration_minutes INT UNSIGNED NULL,
              pricing_type VARCHAR(20) NULL,
              rate_applied DECIMAL(10,2) NULL,
              total_amount DECIMAL(10,2) NULL,
              status ENUM('ACTIVE', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_plate_status (plate_number, status),
              INDEX idx_status (status),
              INDEX idx_entry_time (entry_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS active_plates (
              plate_number VARCHAR(20) NOT NULL PRIMARY KEY,
              parking_log_id BIGINT UNSIGNED NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_active_parking_log FOREIGN KEY (parking_log_id) REFERENCES parking_logs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ai_recognition_logs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id VARCHAR(64) NOT NULL,
              provider VARCHAR(50) NOT NULL,
              model VARCHAR(100) NULL,
              fallback_used TINYINT(1) NOT NULL DEFAULT 0,
              http_status INT NULL,
              recognized_plate VARCHAR(20) NULL,
              confidence VARCHAR(10) NULL,
              result TEXT NULL,
              error_message TEXT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_request_id (request_id),
              INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    seed_defaults($pdo);
}

function seed_defaults(PDO $pdo): void
{
    $defaults = [
        'pricing_mode' => 'hourly',
        'flat_rate' => '5.00',
        'hourly_rate' => '2.00',
        'grace_period' => '15',
        'round_up_hours' => '1',
        'currency_symbol' => 'RM',
        'ai_provider' => 'Agnes AI',
        'ai_model' => 'agnes-2.5-flash',
        'ai_fallback_models' => 'agnes-2.0-flash',
        'ai_api_url' => 'https://apihub.agnes-ai.com/v1',
        'ai_api_key' => '',
        'ai_confidence_auto' => 'HIGH',
        'ai_confidence_verify' => 'MEDIUM',
        'scan_interval_seconds' => '4',
    ];

    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT(setting_key) DO NOTHING'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_key = setting_key'
        );
    }
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // One-time switch from old Gemini defaults to Agnes (keeps existing API key)
    $urlStmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $urlStmt->execute(['ai_api_url']);
    $currentUrl = (string) ($urlStmt->fetchColumn() ?: '');
    if ($currentUrl === '' || str_contains($currentUrl, 'generativelanguage.googleapis.com')) {
        $upd = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $upd->execute(['Agnes AI', 'ai_provider']);
        $upd->execute(['agnes-2.5-flash', 'ai_model']);
        $upd->execute(['agnes-2.0-flash', 'ai_fallback_models']);
        $upd->execute(['https://apihub.agnes-ai.com/v1', 'ai_api_url']);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
        $ins->execute(['admin', $hash]);
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('PARKINGSESSID');
    session_start();
}

start_app_session();
