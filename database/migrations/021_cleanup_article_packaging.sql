-- Миграция: Очистка битых данных в article_packaging
-- Дата: 2026-01-14
-- Описание: Удаляет записи с некорректной кодировкой (кракозябры вместо кириллицы)

-- Удалить записи с некорректными символами в article_id
DELETE FROM article_packaging
WHERE article_id LIKE '%?%'
   OR article_id LIKE '%�%'
   OR article_id REGEXP '[^\x00-\x7F\xC0-\xFF].*[\x80-\xBF][\x80-\xBF]';

-- Показать оставшиеся записи
SELECT
    id,
    user_id,
    article_id,
    pieces_per_sheet,
    pack_quantity,
    created_at
FROM article_packaging
ORDER BY updated_at DESC
LIMIT 20;
