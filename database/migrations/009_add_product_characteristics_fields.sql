-- Migration: Add weight and other key characteristics fields
-- Date: 2026-01-02
-- Description: Добавляет поля для быстрого доступа к ключевым характеристикам товара

SET NAMES utf8mb4;

-- Добавить поля для ключевых характеристик
ALTER TABLE product_knowledge
    ADD COLUMN weight VARCHAR(50) NULL COMMENT 'Вес товара' AFTER dimensions,
    ADD COLUMN material VARCHAR(100) NULL COMMENT 'Материал' AFTER weight,
    ADD COLUMN quantity_in_pack VARCHAR(50) NULL COMMENT 'Количество в упаковке' AFTER material;

-- Проверка результата
DESCRIBE product_knowledge;
