-- Migration: Fix Claude model in ai_settings
-- Date: 2026-01-02
-- Description: Заменяет недоступную модель claude-3-5-sonnet на claude-3-haiku

SET NAMES utf8mb4;

-- Обновить модель
UPDATE ai_settings
SET setting_value = 'claude-3-haiku-20240307',
    updated_at = NOW()
WHERE setting_key = 'model';

-- Проверка результата
SELECT id, setting_key, setting_value, marketplace FROM ai_settings WHERE setting_key = 'model';
