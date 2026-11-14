-- مخطط البداية لمنصة Zeraaty AI. لا يحتوي على بيانات حقيقية.
-- ينشئ جداول جديدة ولا يحذف قواعد أو جداول موجودة.
CREATE DATABASE IF NOT EXISTS zeraaty_ai
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE zeraaty_ai;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('farmer', 'admin') NOT NULL DEFAULT 'farmer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name ENUM('wheat', 'tomato') NOT NULL,
    field_name VARCHAR(150) NOT NULL,
    area_m2 DECIMAL(12,2) NOT NULL,
    planted_on DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crops_user (user_id),
    CONSTRAINT fk_crops_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_crops_area_positive CHECK (area_m2 > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS irrigation_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    crop_id BIGINT UNSIGNED NOT NULL,
    irrigated_at DATETIME NOT NULL,
    water_liters DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_irrigation_crop_date (crop_id, irrigated_at),
    CONSTRAINT fk_irrigation_crop FOREIGN KEY (crop_id) REFERENCES crops (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_irrigation_water_positive CHECK (water_liters > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
