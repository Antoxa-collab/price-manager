-- Migration: Add cached_price and cached_stock to product_mappings
-- Date: 2026-01-12
-- Description: Cache calculated prices and stock values for faster loading
-- Run: docker exec -i price-manager-mysql mysql -u price_user -pprice_password price_manager < database/migrations/019_add_cached_price_stock.sql

SET NAMES utf8mb4;

-- 1. Добавить cached_price (кэшированная расчётная цена)
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_mappings'
    AND COLUMN_NAME = 'cached_price'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE product_mappings ADD COLUMN cached_price DECIMAL(10,2) DEFAULT NULL COMMENT "Кэшированная расчётная цена"',
    'SELECT "Column cached_price already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Добавить cached_stock (кэшированные остатки)
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_mappings'
    AND COLUMN_NAME = 'cached_stock'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE product_mappings ADD COLUMN cached_stock INT DEFAULT NULL COMMENT "Кэшированные остатки"',
    'SELECT "Column cached_stock already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Проверка результата
SELECT 'Migration 019: product_mappings new columns' as step;
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_mappings'
  AND COLUMN_NAME IN ('cached_price', 'cached_stock');

SELECT 'Migration 019 completed successfully' as status;
