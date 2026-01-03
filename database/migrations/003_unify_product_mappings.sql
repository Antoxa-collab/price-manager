-- Migration: Унификация таблиц сопоставления товаров
-- Объединяет отдельные таблицы ozon_product_mapping и wb_product_mapping
-- в единую таблицу product_mappings
--
-- Запуск: docker exec price-manager-mysql mysql -u price_user -pprice_password price_manager < /docker-entrypoint-initdb.d/migrations/003_unify_product_mappings.sql

SET NAMES utf8mb4;

-- 1. Добавляем новые колонки в product_mappings (если их нет)
ALTER TABLE `product_mappings`
    ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED NULL COMMENT 'ID пользователя' AFTER `id`,
    ADD COLUMN IF NOT EXISTS `pieces_per_sheet` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Сколько кусочков из листа' AFTER `quantity_in_pack`,
    ADD COLUMN IF NOT EXISTS `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Себестоимость' AFTER `pieces_per_sheet`;

-- 2. Добавляем индексы (если их нет)
-- Сначала удаляем старый unique key и создаём новый с user_id
-- Нельзя использовать IF NOT EXISTS для индексов в MySQL, поэтому используем PROCEDURE

DELIMITER //

CREATE PROCEDURE migrate_product_mappings()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END; -- Duplicate key name
    DECLARE CONTINUE HANDLER FOR 1091 BEGIN END; -- Can't DROP - doesn't exist

    -- Удаляем старый unique key без user_id
    ALTER TABLE `product_mappings` DROP INDEX `uk_mapping`;

    -- Создаём новый unique key с user_id
    ALTER TABLE `product_mappings` ADD UNIQUE KEY `uk_mapping` (`user_id`, `product_id`, `marketplace`, `marketplace_product_id`);

    -- Добавляем индекс на user_id
    ALTER TABLE `product_mappings` ADD KEY `idx_user` (`user_id`);

    -- Добавляем foreign key на users (если не существует)
    -- Сначала проверим есть ли такой constraint
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'product_mappings'
        AND CONSTRAINT_NAME = 'fk_mapping_user'
    ) THEN
        ALTER TABLE `product_mappings`
        ADD CONSTRAINT `fk_mapping_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
    END IF;
END //

DELIMITER ;

CALL migrate_product_mappings();
DROP PROCEDURE IF EXISTS migrate_product_mappings;

-- 3. Миграция данных из wb_product_mapping (если таблица существует)
INSERT IGNORE INTO `product_mappings`
    (`user_id`, `product_id`, `marketplace`, `marketplace_product_id`, `marketplace_sku`,
     `marketplace_offer_id`, `quantity_in_pack`, `pieces_per_sheet`, `cost_price`, `is_active`, `created_at`)
SELECT
    wpm.user_id,
    wpm.product_id,
    'wildberries' as marketplace,
    CAST(wpm.nm_id AS CHAR) as marketplace_product_id,
    CAST(wpm.chrt_id AS CHAR) as marketplace_sku,
    wpc.vendor_code as marketplace_offer_id,
    wpm.pieces_in_pack as quantity_in_pack,
    wpm.pieces_per_sheet,
    wpm.cost_price,
    1 as is_active,
    wpm.created_at
FROM `wb_product_mapping` wpm
LEFT JOIN `wb_products_cache` wpc ON wpc.nm_id = wpm.nm_id AND wpc.user_id = wpm.user_id
WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wb_product_mapping');

-- 4. Обновляем marketplace_name из кэша WB
UPDATE `product_mappings` pm
JOIN `wb_products_cache` wpc
    ON wpc.nm_id = CAST(pm.marketplace_product_id AS UNSIGNED)
    AND wpc.user_id = pm.user_id
SET pm.marketplace_name = wpc.title,
    pm.marketplace_offer_id = COALESCE(pm.marketplace_offer_id, wpc.vendor_code)
WHERE pm.marketplace = 'wildberries'
  AND (pm.marketplace_name IS NULL OR pm.marketplace_name = '');

-- 5. Если user_id пустой - устанавливаем значение 1 (первый пользователь)
UPDATE `product_mappings` SET `user_id` = 1 WHERE `user_id` IS NULL;

-- 6. Делаем user_id NOT NULL
ALTER TABLE `product_mappings` MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL COMMENT 'ID пользователя';

-- Готово! Старые таблицы wb_product_mapping можно удалить после проверки:
-- DROP TABLE IF EXISTS `wb_product_mapping`;
-- DROP TABLE IF EXISTS `ozon_product_mapping`;

SELECT 'Migration completed successfully!' as status;
