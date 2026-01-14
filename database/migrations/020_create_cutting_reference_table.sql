-- Миграция: Создание таблицы справочника раскроя для пользовательских данных
-- Дата: 2026-01-13
-- Описание: Таблица для сохранения и загрузки пользовательского справочника раскроя

CREATE TABLE IF NOT EXISTS cutting_reference (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'ID пользователя',
    sheet_name VARCHAR(100) NOT NULL COMMENT 'Название листа (Другой 1400×1030, etc)',
    sheet_width INT UNSIGNED NOT NULL COMMENT 'Ширина листа (мм)',
    sheet_height INT UNSIGNED NOT NULL COMMENT 'Высота листа (мм)',
    piece_width INT UNSIGNED NOT NULL COMMENT 'Ширина детали (мм)',
    piece_height INT UNSIGNED NOT NULL COMMENT 'Высота детали (мм)',
    pieces_count INT UNSIGNED NOT NULL COMMENT 'Количество деталей из листа',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Уникальный ключ: один пользователь + один лист + одна деталь = одна запись
    UNIQUE KEY uk_user_sheet_piece (user_id, sheet_name, piece_width, piece_height),

    -- Индексы для поиска
    INDEX idx_user_sheet (user_id, sheet_name),
    INDEX idx_piece_size (piece_width, piece_height),

    -- Foreign key на пользователя (если таблица существует)
    CONSTRAINT fk_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Справочник раскроя листов (сохранённые пользователем данные)';
