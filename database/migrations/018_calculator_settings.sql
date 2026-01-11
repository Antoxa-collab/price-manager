-- Migration: Calculator settings and custom min price
-- Date: 2026-01-09
-- Description: Save calculator markup settings and custom min prices per mapping
-- Run: docker exec -i price-manager-mysql mysql -u price_user -pprice_password price_manager < database/migrations/018_calculator_settings.sql

SET NAMES utf8mb4;

-- 1. Таблица настроек калькулятора (наценки, скидки)
CREATE TABLE IF NOT EXISTS calculator_settings (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    marketplace ENUM('ozon', 'wildberries') NOT NULL,
    markup_min DECIMAL(10,2) DEFAULT 0 COMMENT 'Минимальная наценка (руб или %)',
    markup_extra DECIMAL(5,2) DEFAULT 0 COMMENT 'Дополнительная наценка (%)',
    discount DECIMAL(5,2) DEFAULT 0 COMMENT 'Скидка (%)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_marketplace (user_id, marketplace),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Настройки калькулятора цен по маркетплейсам';

-- 2. Добавить поля для кастомной минимальной цены в product_mappings
-- custom_min_price - ручная минимальная цена
-- is_min_price_edited - флаг что цена была отредактирована вручную

-- Проверяем и добавляем custom_min_price
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_mappings'
    AND COLUMN_NAME = 'custom_min_price'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE product_mappings ADD COLUMN custom_min_price DECIMAL(10,2) DEFAULT NULL COMMENT "Ручная минимальная цена"',
    'SELECT "Column custom_min_price already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем is_min_price_edited
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_mappings'
    AND COLUMN_NAME = 'is_min_price_edited'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE product_mappings ADD COLUMN is_min_price_edited TINYINT(1) DEFAULT 0 COMMENT "Флаг ручного редактирования мин. цены"',
    'SELECT "Column is_min_price_edited already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Проверка результата
SELECT 'Migration 018: calculator_settings table' as step;
SHOW CREATE TABLE calculator_settings\G

SELECT 'Migration 018: product_mappings new columns' as step;
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_mappings'
  AND COLUMN_NAME IN ('custom_min_price', 'is_min_price_edited');

SELECT 'Migration 018 completed successfully' as status;
