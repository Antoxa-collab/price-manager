-- Миграция: Создание таблицы системных логов
-- Дата: 2026-01-14
-- Описание: Таблица для хранения логов калькулятора, API операций и системных событий

CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    level ENUM('ERROR', 'WARN', 'INFO', 'DEBUG', 'OK') NOT NULL DEFAULT 'INFO',
    category VARCHAR(20) NOT NULL DEFAULT 'SYS',
    message TEXT NOT NULL,
    context JSON,
    source VARCHAR(100) COMMENT 'Файл:строка источника',
    url VARCHAR(500) COMMENT 'URL запроса',
    user_id INT UNSIGNED,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    duration_ms INT UNSIGNED COMMENT 'Длительность операции в мс',
    request_id VARCHAR(36) COMMENT 'UUID для группировки связанных логов',

    INDEX idx_created_at (created_at),
    INDEX idx_level (level),
    INDEX idx_category (category),
    INDEX idx_user_id (user_id),
    INDEX idx_request_id (request_id),
    INDEX idx_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вставить начальную запись
INSERT INTO system_logs (level, category, message, context) VALUES
('INFO', 'SYS', 'Система логирования инициализирована', '{"version": "1.0"}');
