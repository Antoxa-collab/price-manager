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
-- Table: products (наши товары: материал + сорт + толщина)
-- ----------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Название товара',
    `sku` VARCHAR(100) NOT NULL COMMENT 'Артикул (наш внутренний)',
    `barcode` VARCHAR(100) NULL DEFAULT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Категория (Фанера, OSB, МДФ и т.д.)',
    `material_type` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Тип материала (ФК, ФСФ и т.д.)',
    `grade` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Сорт (1/2, 2/2, 2/4 и т.д.)',
    `thickness` DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Толщина в мм',
    `base_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT 'Закупочная цена',
    `cost_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `markup_percent` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Базовая наценка (%)',
    `markup_min_price` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Наценка для минимальной цены (%)',
    `markup_your_price` DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Доп.наценка для вашей цены (%)',
    `final_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'RUB',
    `weight` DECIMAL(10, 3) NULL DEFAULT NULL,
    `dimensions` VARCHAR(100) NULL DEFAULT NULL,
    `wb_article` VARCHAR(100) NULL DEFAULT NULL,
    `ozon_article` VARCHAR(100) NULL DEFAULT NULL,
    `wb_price` DECIMAL(12, 2) NULL DEFAULT NULL,
    `ozon_price` DECIMAL(12, 2) NULL DEFAULT NULL,
    `stock_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Остаток на складе',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_products_sku` (`sku`),
    KEY `idx_products_name` (`name`),
    KEY `idx_products_barcode` (`barcode`),
    KEY `idx_products_category` (`category`),
    KEY `idx_products_material_type` (`material_type`),
    KEY `idx_products_grade` (`grade`),
    KEY `idx_products_thickness` (`thickness`),
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

-- ----------------------------
-- Table: product_mappings (сопоставление наших товаров с артикулами маркетплейсов)
-- ----------------------------
DROP TABLE IF EXISTS `product_mappings`;
CREATE TABLE `product_mappings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL COMMENT 'Наш товар (материал+сорт+толщина)',
    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
    `marketplace_product_id` VARCHAR(100) NOT NULL COMMENT 'product_id на маркетплейсе',
    `marketplace_sku` VARCHAR(100) NULL COMMENT 'SKU на маркетплейсе',
    `marketplace_offer_id` VARCHAR(100) NULL COMMENT 'offer_id (артикул продавца)',
    `marketplace_name` VARCHAR(500) NULL COMMENT 'Название на маркетплейсе',
    `quantity_in_pack` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Количество единиц в упаковке',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mapping` (`product_id`, `marketplace`, `marketplace_product_id`),
    KEY `idx_marketplace` (`marketplace`),
    KEY `idx_product` (`product_id`),
    KEY `idx_marketplace_product_id` (`marketplace_product_id`),
    CONSTRAINT `fk_mapping_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: marketplace_products_cache (кэш товаров с маркетплейса)
-- ----------------------------
DROP TABLE IF EXISTS `marketplace_products_cache`;
CREATE TABLE `marketplace_products_cache` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL,
    `product_id` VARCHAR(100) NOT NULL COMMENT 'product_id на маркетплейсе',
    `sku` VARCHAR(100) NULL COMMENT 'SKU на маркетплейсе',
    `offer_id` VARCHAR(100) NULL COMMENT 'offer_id (артикул продавца)',
    `name` VARCHAR(500) NOT NULL COMMENT 'Название товара',
    `price` DECIMAL(12,2) NULL COMMENT 'Текущая цена',
    `min_price` DECIMAL(12,2) NULL COMMENT 'Минимальная цена',
    `old_price` DECIMAL(12,2) NULL COMMENT 'Старая цена (зачёркнутая)',
    `stock` INT NULL COMMENT 'Остаток',
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Видимость на площадке',
    `raw_data` JSON NULL COMMENT 'Полные данные с API',
    `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата синхронизации',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cache` (`marketplace`, `product_id`),
    KEY `idx_name` (`name`(100)),
    KEY `idx_offer_id` (`offer_id`),
    KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: price_upload_history (история загрузки цен)
-- ----------------------------
DROP TABLE IF EXISTS `price_upload_history`;
CREATE TABLE `price_upload_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL,
    `product_id` INT UNSIGNED NULL COMMENT 'Наш товар',
    `mapping_id` INT UNSIGNED NULL COMMENT 'Ссылка на сопоставление',
    `marketplace_product_id` VARCHAR(100) NOT NULL,
    `old_price` DECIMAL(12,2) NULL,
    `new_price` DECIMAL(12,2) NULL,
    `old_min_price` DECIMAL(12,2) NULL,
    `new_min_price` DECIMAL(12,2) NULL,
    `status` ENUM('pending', 'success', 'error') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_product` (`product_id`),
    KEY `idx_marketplace` (`marketplace`),
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: error_logs (логи ошибок)
-- ----------------------------
DROP TABLE IF EXISTS `error_logs`;
CREATE TABLE `error_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `level` VARCHAR(20) NOT NULL DEFAULT 'ERROR' COMMENT 'DEBUG, INFO, WARNING, ERROR, API_ERROR, API_OK, DB_ERROR',
    `message` TEXT NOT NULL COMMENT 'Текст ошибки',
    `context` JSON NULL COMMENT 'Дополнительные данные (stack trace, параметры)',
    `url` VARCHAR(500) NULL COMMENT 'URL запроса',
    `method` VARCHAR(10) NULL COMMENT 'HTTP метод',
    `user_id` INT UNSIGNED NULL COMMENT 'ID пользователя',
    `ip_address` VARCHAR(45) NULL COMMENT 'IP адрес',
    `user_agent` VARCHAR(500) NULL COMMENT 'User-Agent',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_error_logs_level` (`level`),
    KEY `idx_error_logs_created` (`created_at`),
    KEY `idx_error_logs_user` (`user_id`),
    KEY `idx_error_logs_url` (`url`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
