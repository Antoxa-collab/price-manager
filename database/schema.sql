-- Price Manager Database Schema
-- MySQL 8.0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table: users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'manager', 'user') NOT NULL DEFAULT 'user',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: materials
-- ----------------------------
DROP TABLE IF EXISTS `materials`;
CREATE TABLE `materials` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `article` VARCHAR(100) NULL DEFAULT NULL,
    `unit` VARCHAR(50) NOT NULL DEFAULT 'шт',
    `price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'RUB',
    `supplier` VARCHAR(255) NULL DEFAULT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL,
    `description` TEXT NULL,
    `min_stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `current_stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_materials_article` (`article`),
    KEY `idx_materials_name` (`name`),
    KEY `idx_materials_category` (`category`),
    KEY `idx_materials_supplier` (`supplier`),
    KEY `idx_materials_is_active` (`is_active`),
    KEY `idx_materials_created_by` (`created_by`),
    CONSTRAINT `fk_materials_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: products
-- ----------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `barcode` VARCHAR(100) NULL DEFAULT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL,
    `base_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `cost_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `markup_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    `final_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'RUB',
    `weight` DECIMAL(10, 3) NULL DEFAULT NULL,
    `dimensions` VARCHAR(100) NULL DEFAULT NULL,
    `wb_article` VARCHAR(100) NULL DEFAULT NULL,
    `ozon_article` VARCHAR(100) NULL DEFAULT NULL,
    `wb_price` DECIMAL(12, 2) NULL DEFAULT NULL,
    `ozon_price` DECIMAL(12, 2) NULL DEFAULT NULL,
    `stock_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_products_sku` (`sku`),
    KEY `idx_products_name` (`name`),
    KEY `idx_products_barcode` (`barcode`),
    KEY `idx_products_category` (`category`),
    KEY `idx_products_wb_article` (`wb_article`),
    KEY `idx_products_ozon_article` (`ozon_article`),
    KEY `idx_products_is_active` (`is_active`),
    KEY `idx_products_created_by` (`created_by`),
    CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: product_materials (связь продуктов с материалами)
-- ----------------------------
DROP TABLE IF EXISTS `product_materials`;
CREATE TABLE `product_materials` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `material_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(10, 4) NOT NULL DEFAULT 1.0000,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_materials` (`product_id`, `material_id`),
    KEY `idx_product_materials_material` (`material_id`),
    CONSTRAINT `fk_pm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pm_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: api_settings
-- ----------------------------
DROP TABLE IF EXISTS `api_settings`;
CREATE TABLE `api_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `platform` ENUM('wildberries', 'ozon', 'yandex_market', 'other') NOT NULL,
    `api_key` VARCHAR(500) NOT NULL,
    `api_secret` VARCHAR(500) NULL DEFAULT NULL,
    `client_id` VARCHAR(255) NULL DEFAULT NULL,
    `shop_id` VARCHAR(255) NULL DEFAULT NULL,
    `warehouse_id` VARCHAR(255) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_sandbox` TINYINT(1) NOT NULL DEFAULT 0,
    `last_sync_at` DATETIME NULL DEFAULT NULL,
    `sync_status` ENUM('idle', 'syncing', 'success', 'error') NOT NULL DEFAULT 'idle',
    `sync_error` TEXT NULL,
    `settings_json` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_settings_user_platform` (`user_id`, `platform`),
    KEY `idx_api_settings_platform` (`platform`),
    KEY `idx_api_settings_is_active` (`is_active`),
    CONSTRAINT `fk_api_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: operations_log (для истории операций)
-- ----------------------------
DROP TABLE IF EXISTS `operations_log`;
CREATE TABLE `operations_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL DEFAULT NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_operations_log_user` (`user_id`),
    KEY `idx_operations_log_action` (`action`),
    KEY `idx_operations_log_entity` (`entity_type`, `entity_id`),
    KEY `idx_operations_log_created` (`created_at`),
    CONSTRAINT `fk_operations_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
