-- Миграция 020: Справочник упаковки по артикулам
-- Сохраняет pieces_per_sheet и pack_quantity для каждого артикула

CREATE TABLE IF NOT EXISTS article_packaging (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    article_id VARCHAR(255) NOT NULL COMMENT 'ID артикула (vendor_code, offer_id, sku)',
    article_name VARCHAR(500) DEFAULT NULL COMMENT 'Название артикула для информации',
    pieces_per_sheet INT NOT NULL DEFAULT 1 COMMENT 'Кусочков с листа',
    pack_quantity INT NOT NULL DEFAULT 1 COMMENT 'Количество в упаковке',
    sheet_name VARCHAR(255) DEFAULT NULL COMMENT 'Название листа (для справки)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_article (user_id, article_id),
    INDEX idx_user_id (user_id),
    INDEX idx_article_id (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
