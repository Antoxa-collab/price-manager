-- Migration: Add product info fields to ai_reviews and ai_questions
-- Date: 2026-01-02
-- Description: Добавляет поля для хранения информации о товаре (название, артикул)
--              для более контекстных ответов AI

SET NAMES utf8mb4;

-- Добавить поля в ai_reviews
ALTER TABLE `ai_reviews`
    ADD COLUMN `product_name` VARCHAR(500) NULL COMMENT 'Название товара' AFTER `marketplace_product_id`,
    ADD COLUMN `product_article` VARCHAR(100) NULL COMMENT 'Артикул продавца' AFTER `product_name`;

-- Добавить поля в ai_questions
ALTER TABLE `ai_questions`
    ADD COLUMN `product_name` VARCHAR(500) NULL COMMENT 'Название товара' AFTER `marketplace_product_id`,
    ADD COLUMN `product_article` VARCHAR(100) NULL COMMENT 'Артикул продавца' AFTER `product_name`;

-- Добавить индексы для поиска по артикулу
ALTER TABLE `ai_reviews` ADD INDEX `idx_ai_reviews_article` (`product_article`);
ALTER TABLE `ai_questions` ADD INDEX `idx_ai_questions_article` (`product_article`);

-- Проверка результата
DESCRIBE ai_reviews;
DESCRIBE ai_questions;
