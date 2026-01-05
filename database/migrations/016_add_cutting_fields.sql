-- Миграция: Добавление полей для раскроя листов
-- Дата: 2026-01-04
-- Описание: Добавляет поля для хранения размеров листа и кусочков в product_mappings

ALTER TABLE product_mappings
    ADD COLUMN sheet_width INT UNSIGNED DEFAULT NULL COMMENT 'Ширина исходного листа (мм)' AFTER quantity_in_pack,
    ADD COLUMN sheet_height INT UNSIGNED DEFAULT NULL COMMENT 'Высота исходного листа (мм)' AFTER sheet_width,
    ADD COLUMN piece_width INT UNSIGNED DEFAULT NULL COMMENT 'Ширина кусочка (мм)' AFTER sheet_height,
    ADD COLUMN piece_height INT UNSIGNED DEFAULT NULL COMMENT 'Высота кусочка (мм)' AFTER piece_width;

-- Индекс для быстрого поиска по размерам листа
CREATE INDEX idx_product_mappings_sheet_size ON product_mappings (sheet_width, sheet_height);
