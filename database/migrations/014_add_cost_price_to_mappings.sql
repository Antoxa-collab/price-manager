-- Migration: Add cost_price column to product_mappings table
-- Date: 2026-01-03

-- Add cost_price column if it doesn't exist
-- Note: MySQL doesn't support IF NOT EXISTS for ADD COLUMN, so we use a procedure

DELIMITER //

DROP PROCEDURE IF EXISTS add_cost_price_column//

CREATE PROCEDURE add_cost_price_column()
BEGIN
    DECLARE column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO column_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_mappings'
      AND COLUMN_NAME = 'cost_price';

    IF column_exists = 0 THEN
        ALTER TABLE product_mappings
        ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Себестоимость';
    END IF;
END//

DELIMITER ;

CALL add_cost_price_column();
DROP PROCEDURE IF EXISTS add_cost_price_column;
