-- Скрипт для проверки и создания таблицы cutting_reference
-- Запустить через phpMyAdmin или командную строку MySQL

-- Проверить, существует ли таблица
SELECT COUNT(*) AS table_exists 
FROM information_schema.tables 
WHERE table_schema = 'price_manager' 
AND table_name = 'cutting_reference';

-- Создать таблицу (если не существует)
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
    INDEX idx_piece_size (piece_width, piece_height)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Проверить структуру таблицы
DESCRIBE cutting_reference;
