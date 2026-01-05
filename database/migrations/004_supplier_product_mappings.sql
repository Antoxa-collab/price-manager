-- Migration: Таблица сопоставлений товаров поставщиков
-- Для автоматического сопоставления кодов из PDF-накладных с товарами системы
--
-- Запуск: docker exec -i price-manager-mysql mysql -u price_user -pprice_password price_manager < /docker-entrypoint-initdb.d/migrations/004_supplier_product_mappings.sql

SET NAMES utf8mb4;

-- Таблица сопоставлений товаров поставщиков
CREATE TABLE IF NOT EXISTS `supplier_product_mappings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ID пользователя',
    `supplier_code` VARCHAR(50) NOT NULL COMMENT 'Код товара у поставщика (из PDF)',
    `supplier_name` VARCHAR(255) DEFAULT NULL COMMENT 'Название товара у поставщика (для справки)',
    `product_id` INT UNSIGNED NOT NULL COMMENT 'ID товара в нашей системе (products.id)',
    `supplier_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID поставщика (если будет справочник)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_user_supplier_code` (`user_id`, `supplier_code`),
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_supplier_code` (`supplier_code`),

    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Сопоставление кодов поставщиков с товарами системы';

SELECT 'Table supplier_product_mappings created successfully!' as status;
