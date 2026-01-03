-- Migration: Create product_knowledge table
-- Date: 2026-01-02
-- Description: Таблица для хранения информации о товарах для AI
--              Синхронизируется с WB Content API

SET NAMES utf8mb4;

-- Создать таблицу базы знаний о товарах
CREATE TABLE IF NOT EXISTS `product_knowledge` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL COMMENT 'ID пользователя-владельца',
    `marketplace` VARCHAR(20) NOT NULL DEFAULT 'wildberries',
    `marketplace_product_id` VARCHAR(100) NOT NULL COMMENT 'nmId на WB',
    `imt_id` VARCHAR(100) NULL COMMENT 'imtId на WB',
    `supplier_article` VARCHAR(100) NULL COMMENT 'Артикул продавца (vendorCode)',
    `product_name` VARCHAR(500) NULL COMMENT 'Название товара',
    `brand` VARCHAR(100) NULL COMMENT 'Бренд',
    `product_description` TEXT NULL COMMENT 'Описание из карточки WB',
    `product_composition` TEXT NULL COMMENT 'Состав/комплектация',
    `characteristics` JSON NULL COMMENT 'Характеристики товара JSON',
    `dimensions` VARCHAR(255) NULL COMMENT 'Размеры (ДxШxВ)',
    `custom_notes` TEXT NULL COMMENT 'Заметки продавца для AI (заполняется вручную)',
    `is_active` TINYINT(1) DEFAULT 1,
    `last_synced_at` DATETIME NULL COMMENT 'Когда последний раз синхронизировали с WB',
    `wb_updated_at` DATETIME NULL COMMENT 'Дата обновления карточки на WB',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_marketplace_product` (`marketplace`, `marketplace_product_id`),
    INDEX `idx_supplier_article` (`supplier_article`),
    INDEX `idx_user_marketplace` (`user_id`, `marketplace`),
    INDEX `idx_last_synced` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Проверка результата
DESCRIBE product_knowledge;
