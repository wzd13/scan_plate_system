-- Smart Parking Management System
-- MySQL 8+ / MariaDB 10.5+
-- Import via phpMyAdmin or: mysql -u root < schema.sql

CREATE DATABASE IF NOT EXISTS parking_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE parking_system;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parking_logs (
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
  INDEX idx_entry_time (entry_time),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enforces at most one ACTIVE session per plate (app also uses transactions)
CREATE TABLE IF NOT EXISTS active_plates (
  plate_number VARCHAR(20) NOT NULL PRIMARY KEY,
  parking_log_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_active_parking_log
    FOREIGN KEY (parking_log_id) REFERENCES parking_logs(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_recognition_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin created by install.php (admin / admin123)
-- Settings defaults (API key left empty — set in Admin → AI Settings)
INSERT INTO settings (setting_key, setting_value) VALUES
('pricing_mode', 'hourly'),
('flat_rate', '5.00'),
('hourly_rate', '2.00'),
('grace_period', '15'),
('round_up_hours', '1'),
('currency_symbol', 'RM'),
('ai_provider', 'Agnes AI'),
('ai_model', 'agnes-2.5-flash'),
('ai_fallback_models', 'agnes-2.0-flash'),
('ai_api_url', 'https://apihub.agnes-ai.com/v1'),
('ai_api_key', ''),
('ai_confidence_auto', 'HIGH'),
('ai_confidence_verify', 'MEDIUM'),
('scan_interval_seconds', '4')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
