-- Migration: Add generation_meta column for AI diagnostics
-- Date: 2026-01-02
-- Description: Добавляет поле для хранения метаданных генерации AI

SET NAMES utf8mb4;

-- Добавить поле generation_meta в ai_reviews
ALTER TABLE ai_reviews
    ADD COLUMN generation_meta JSON NULL COMMENT 'Метаданные генерации (промпт, примеры, товар)' AFTER tokens_used;

-- Добавить поле generation_meta в ai_questions
ALTER TABLE ai_questions
    ADD COLUMN generation_meta JSON NULL COMMENT 'Метаданные генерации (промпт, примеры, товар)' AFTER tokens_used;

-- Проверка результата
SELECT 'ai_reviews columns:' as info;
SHOW COLUMNS FROM ai_reviews WHERE Field = 'generation_meta';

SELECT 'ai_questions columns:' as info;
SHOW COLUMNS FROM ai_questions WHERE Field = 'generation_meta';
