-- Migration: Add wb_discount column to products table
-- Date: 2026-01-03

DELIMITER //

DROP PROCEDURE IF EXISTS add_wb_discount_column//

CREATE PROCEDURE add_wb_discount_column()
BEGIN
    DECLARE column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO column_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'wb_discount';

    IF column_exists = 0 THEN
        ALTER TABLE products
        ADD COLUMN wb_discount DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Скидка WB (%)' AFTER markup_your_price;
    END IF;
END//

DELIMITER ;

CALL add_wb_discount_column();
DROP PROCEDURE IF EXISTS add_wb_discount_column;
