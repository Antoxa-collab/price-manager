-- Migration: Fix column types for pieces_per_sheet and quantity_in_pack
-- Date: 2026-01-05
-- Problem: MySQL error 22003 "Numeric value out of range"
-- Solution: Ensure columns are INT UNSIGNED, not TINYINT or SMALLINT
-- Run: docker exec -i price-manager-mysql mysql -u price_user -pprice_password price_manager < database/migrations/017_fix_column_types.sql

SET NAMES utf8mb4;

-- First, check current column types
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_mappings'
  AND COLUMN_NAME IN ('pieces_per_sheet', 'quantity_in_pack');

-- 1. Fix product_mappings.pieces_per_sheet
-- This will convert TINYINT/SMALLINT to INT UNSIGNED
ALTER TABLE `product_mappings`
    MODIFY COLUMN `pieces_per_sheet` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Сколько кусочков получается из листа';

-- 2. Fix product_mappings.quantity_in_pack
ALTER TABLE `product_mappings`
    MODIFY COLUMN `quantity_in_pack` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Количество единиц в упаковке';

-- 3. Fix cutting_pieces.actual_qty (if table exists)
-- Using procedure to handle case when table doesn't exist
DELIMITER //
CREATE PROCEDURE fix_cutting_pieces_017()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1146 BEGIN END; -- Table doesn't exist

    ALTER TABLE `cutting_pieces`
        MODIFY COLUMN `actual_qty` INT UNSIGNED NOT NULL COMMENT 'Фактическое количество (можно изменить)';

    ALTER TABLE `cutting_pieces`
        MODIFY COLUMN `calculated_qty` INT UNSIGNED DEFAULT NULL COMMENT 'Авто-расчёт количества';
END //
DELIMITER ;

CALL fix_cutting_pieces_017();
DROP PROCEDURE IF EXISTS fix_cutting_pieces_017;

-- 4. Sanitize existing data (ensure values are in valid range 1-10000)
UPDATE `product_mappings`
SET `pieces_per_sheet` = 1
WHERE `pieces_per_sheet` < 1 OR `pieces_per_sheet` IS NULL;

UPDATE `product_mappings`
SET `pieces_per_sheet` = 10000
WHERE `pieces_per_sheet` > 10000;

UPDATE `product_mappings`
SET `quantity_in_pack` = 1
WHERE `quantity_in_pack` < 1 OR `quantity_in_pack` IS NULL;

UPDATE `product_mappings`
SET `quantity_in_pack` = 10000
WHERE `quantity_in_pack` > 10000;

-- 5. Verify fix
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_mappings'
  AND COLUMN_NAME IN ('pieces_per_sheet', 'quantity_in_pack');

SELECT 'Migration 017 completed: column types fixed to INT UNSIGNED' as status;
