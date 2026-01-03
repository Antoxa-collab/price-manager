-- Migration: AI Tables for Reviews and Questions automation
-- Date: 2026-01-02

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table: user_api_keys (API ключи пользователей для внешних сервисов)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `user_api_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `service` VARCHAR(50) NOT NULL COMMENT 'claude, openai, etc.',
    `api_key` VARCHAR(500) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_api_keys_service` (`service`),
    KEY `idx_user_api_keys_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_settings (настройки AI для каждого маркетплейса)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `marketplace` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'ozon, wildberries, yandex, all',
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ai_settings` (`marketplace`, `setting_key`),
    KEY `idx_ai_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_prompts (промпты для генерации ответов)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_prompts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `marketplace` VARCHAR(20) NOT NULL DEFAULT 'ozon' COMMENT 'ozon, wildberries, yandex',
    `type` ENUM('review', 'question') NOT NULL COMMENT 'Тип: отзыв или вопрос',
    `sentiment` ENUM('positive', 'negative', 'neutral') NULL COMMENT 'Тональность (для отзывов)',
    `name` VARCHAR(255) NOT NULL COMMENT 'Название промпта',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Использовать по умолчанию',
    `system_prompt` TEXT NOT NULL COMMENT 'Системный промпт',
    `user_prompt_template` TEXT NOT NULL COMMENT 'Шаблон пользовательского промпта',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_prompts_marketplace` (`marketplace`),
    KEY `idx_ai_prompts_type` (`type`),
    KEY `idx_ai_prompts_sentiment` (`sentiment`),
    KEY `idx_ai_prompts_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_examples (примеры для few-shot learning)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_examples` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prompt_id` INT UNSIGNED NOT NULL,
    `input_text` TEXT NOT NULL COMMENT 'Пример входного текста',
    `output_text` TEXT NOT NULL COMMENT 'Пример выходного текста',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_examples_prompt` (`prompt_id`),
    CONSTRAINT `fk_ai_examples_prompt` FOREIGN KEY (`prompt_id`) REFERENCES `ai_prompts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_product_knowledge (база знаний о товарах)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_product_knowledge` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NULL COMMENT 'Наш товар (NULL для общих знаний)',
    `marketplace_product_id` VARCHAR(100) NULL COMMENT 'ID товара на маркетплейсе',
    `knowledge_type` ENUM('description', 'specs', 'faq', 'note') NOT NULL DEFAULT 'note',
    `title` VARCHAR(255) NULL COMMENT 'Заголовок',
    `content` TEXT NOT NULL COMMENT 'Содержимое',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_knowledge_product` (`product_id`),
    KEY `idx_ai_knowledge_mp_product` (`marketplace_product_id`),
    KEY `idx_ai_knowledge_type` (`knowledge_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_reviews (отзывы с маркетплейсов)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_reviews` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `marketplace` VARCHAR(20) NOT NULL COMMENT 'ozon, wildberries, yandex',
    `marketplace_review_id` VARCHAR(100) NOT NULL COMMENT 'ID отзыва на маркетплейсе',
    `marketplace_product_id` VARCHAR(100) NULL COMMENT 'ID товара на маркетплейсе',
    `product_id` INT UNSIGNED NULL COMMENT 'Наш товар',
    `author_name` VARCHAR(255) NULL COMMENT 'Имя автора',
    `rating` TINYINT UNSIGNED NULL COMMENT 'Оценка 1-5',
    `review_text` TEXT NULL COMMENT 'Текст отзыва',
    `review_pros` TEXT NULL COMMENT 'Достоинства',
    `review_cons` TEXT NULL COMMENT 'Недостатки',
    `review_date` DATETIME NULL COMMENT 'Дата отзыва',
    `status` ENUM('new', 'generating', 'generated', 'approved', 'sent', 'skipped', 'error') NOT NULL DEFAULT 'new',
    `generated_response` TEXT NULL COMMENT 'Сгенерированный ответ',
    `edited_response` TEXT NULL COMMENT 'Отредактированный ответ',
    `sent_response` TEXT NULL COMMENT 'Отправленный ответ',
    `sent_at` DATETIME NULL COMMENT 'Дата отправки',
    `prompt_id` INT UNSIGNED NULL COMMENT 'Использованный промпт',
    `tokens_used` INT UNSIGNED NULL COMMENT 'Использовано токенов',
    `error_message` TEXT NULL COMMENT 'Сообщение об ошибке',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ai_reviews` (`marketplace`, `marketplace_review_id`),
    KEY `idx_ai_reviews_marketplace` (`marketplace`),
    KEY `idx_ai_reviews_product` (`product_id`),
    KEY `idx_ai_reviews_status` (`status`),
    KEY `idx_ai_reviews_date` (`review_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_questions (вопросы с маркетплейсов)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_questions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `marketplace` VARCHAR(20) NOT NULL COMMENT 'ozon, wildberries, yandex',
    `marketplace_question_id` VARCHAR(100) NOT NULL COMMENT 'ID вопроса на маркетплейсе',
    `marketplace_product_id` VARCHAR(100) NULL COMMENT 'ID товара на маркетплейсе',
    `product_id` INT UNSIGNED NULL COMMENT 'Наш товар',
    `author_name` VARCHAR(255) NULL COMMENT 'Имя автора',
    `question_text` TEXT NOT NULL COMMENT 'Текст вопроса',
    `question_date` DATETIME NULL COMMENT 'Дата вопроса',
    `status` ENUM('new', 'generating', 'generated', 'approved', 'sent', 'skipped', 'error') NOT NULL DEFAULT 'new',
    `generated_response` TEXT NULL COMMENT 'Сгенерированный ответ',
    `edited_response` TEXT NULL COMMENT 'Отредактированный ответ',
    `sent_response` TEXT NULL COMMENT 'Отправленный ответ',
    `sent_at` DATETIME NULL COMMENT 'Дата отправки',
    `prompt_id` INT UNSIGNED NULL COMMENT 'Использованный промпт',
    `tokens_used` INT UNSIGNED NULL COMMENT 'Использовано токенов',
    `error_message` TEXT NULL COMMENT 'Сообщение об ошибке',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ai_questions` (`marketplace`, `marketplace_question_id`),
    KEY `idx_ai_questions_marketplace` (`marketplace`),
    KEY `idx_ai_questions_product` (`product_id`),
    KEY `idx_ai_questions_status` (`status`),
    KEY `idx_ai_questions_date` (`question_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: ai_generation_log (логи генерации)
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_generation_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('review', 'question') NOT NULL,
    `item_id` INT UNSIGNED NOT NULL COMMENT 'ID отзыва или вопроса',
    `prompt_id` INT UNSIGNED NULL,
    `model` VARCHAR(100) NULL COMMENT 'Использованная модель',
    `input_tokens` INT UNSIGNED NULL,
    `output_tokens` INT UNSIGNED NULL,
    `total_tokens` INT UNSIGNED NULL,
    `generation_time_ms` INT UNSIGNED NULL COMMENT 'Время генерации в мс',
    `status` ENUM('success', 'error') NOT NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_gen_log_type` (`type`),
    KEY `idx_ai_gen_log_item` (`item_id`),
    KEY `idx_ai_gen_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Insert default prompts for OZON
-- ----------------------------
INSERT INTO `ai_prompts` (`marketplace`, `type`, `sentiment`, `name`, `is_default`, `system_prompt`, `user_prompt_template`, `is_active`) VALUES
('ozon', 'review', 'positive', 'Ответ на положительный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать благодарственный ответ на положительный отзыв покупателя.

Правила:
- Поблагодари за покупку и отзыв
- Упомяни конкретные плюсы, которые отметил покупатель
- Пожелай приятного использования
- Пригласи за новыми покупками
- Ответ должен быть 2-4 предложения
- Не используй восклицательные знаки слишком часто
- Будь искренним, не используй шаблонные фразы',
'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('ozon', 'review', 'negative', 'Ответ на негативный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать конструктивный ответ на негативный отзыв покупателя.

Правила:
- Извинись за неудобства
- Прояви понимание проблемы покупателя
- Предложи решение или попроси связаться для решения
- НЕ оправдывайся и не спорь
- Ответ должен быть 3-5 предложений
- Будь вежливым и профессиональным',
'Напиши ответ на негативный отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('ozon', 'review', 'neutral', 'Ответ на нейтральный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать ответ на нейтральный отзыв покупателя.

Правила:
- Поблагодари за обратную связь
- Отметь положительные моменты, если есть
- Если есть критика — учти её и предложи помощь
- Ответ должен быть 2-4 предложения',
'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('ozon', 'question', NULL, 'Ответ на вопрос покупателя', 1,
'Ты — консультант интернет-магазина. Твоя задача — дать полезный и точный ответ на вопрос покупателя о товаре.

Правила:
- Отвечай по существу вопроса
- Используй информацию о товаре, если она предоставлена
- Если не знаешь точного ответа — честно скажи и предложи связаться
- Ответ должен быть информативным, но кратким (2-5 предложений)
- Будь дружелюбным и полезным',
'Ответь на вопрос от {{author_name}} о товаре "{{product_name}}":

{{question_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1);

-- ----------------------------
-- Insert default prompts for Wildberries
-- ----------------------------
INSERT INTO `ai_prompts` (`marketplace`, `type`, `sentiment`, `name`, `is_default`, `system_prompt`, `user_prompt_template`, `is_active`) VALUES
('wildberries', 'review', 'positive', 'Ответ на положительный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать благодарственный ответ на положительный отзыв покупателя.

Правила:
- Поблагодари за покупку и отзыв
- Упомяни конкретные плюсы, которые отметил покупатель
- Пожелай приятного использования
- Пригласи за новыми покупками
- Ответ должен быть 2-4 предложения
- Не используй восклицательные знаки слишком часто
- Будь искренним, не используй шаблонные фразы',
'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('wildberries', 'review', 'negative', 'Ответ на негативный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать конструктивный ответ на негативный отзыв покупателя.

Правила:
- Извинись за неудобства
- Прояви понимание проблемы покупателя
- Предложи решение или попроси связаться для решения
- НЕ оправдывайся и не спорь
- Ответ должен быть 3-5 предложений
- Будь вежливым и профессиональным',
'Напиши ответ на негативный отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('wildberries', 'review', 'neutral', 'Ответ на нейтральный отзыв', 1,
'Ты — вежливый представитель интернет-магазина. Твоя задача — написать ответ на нейтральный отзыв покупателя.

Правила:
- Поблагодари за обратную связь
- Отметь положительные моменты, если есть
- Если есть критика — учти её и предложи помощь
- Ответ должен быть 2-4 предложения',
'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1),

('wildberries', 'question', NULL, 'Ответ на вопрос покупателя', 1,
'Ты — консультант интернет-магазина. Твоя задача — дать полезный и точный ответ на вопрос покупателя о товаре.

Правила:
- Отвечай по существу вопроса
- Используй информацию о товаре, если она предоставлена
- Если не знаешь точного ответа — честно скажи и предложи связаться
- Ответ должен быть информативным, но кратким (2-5 предложений)
- Будь дружелюбным и полезным',
'Ответь на вопрос от {{author_name}} о товаре "{{product_name}}":

{{question_text}}
{{knowledge}}

Подпись: {{store_signature}}', 1);

-- ----------------------------
-- Insert default AI settings
-- ----------------------------
INSERT INTO `ai_settings` (`marketplace`, `setting_key`, `setting_value`) VALUES
('all', 'model', 'claude-3-haiku-20240307'),
('all', 'max_tokens', '1024'),
('all', 'temperature', '0.7'),
('all', 'moderation_enabled', '1'),
('all', 'auto_generate', '0'),
('all', 'store_name', 'Наш магазин'),
('all', 'store_signature', 'С уважением, команда магазина');
