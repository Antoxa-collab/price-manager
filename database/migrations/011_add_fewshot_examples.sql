-- Миграция 011: Добавление few-shot примеров для WB промптов
-- Примеры используются AI для понимания стиля ответов

-- Добавить примеры для "Ответ на положительный отзыв WB"
INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Отличная фанера, ровная, без сучков. Использовал для полок в гараже - идеально подошла. Буду заказывать ещё!',
       'Благодарим за отзыв! Рады, что фанера подошла для ваших полок. Будем рады видеть вас снова!', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на положительный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Хорошее качество за эту цену. Доставили быстро, упаковано надёжно.',
       'Спасибо за оценку! Стараемся поддерживать баланс качества и цены. Надёжная упаковка — наш приоритет при доставке.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на положительный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Супер! Заказываю уже третий раз, всегда отличное качество.',
       'Благодарим за доверие и постоянство! Приятно, что качество соответствует вашим ожиданиям.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на положительный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

-- Добавить примеры для "Ответ на негативный отзыв WB"
INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Пришла фанера с трещиной на углу. Очень расстроен, пришлось отпиливать часть.',
       'Приносим извинения за доставленное неудобство. Повреждение могло произойти при транспортировке. Пожалуйста, свяжитесь с нами — поможем решить вопрос.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на негативный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Размеры не соответствуют заявленным, на 2 см меньше.',
       'Благодарим за обратную связь. Допуск по ГОСТ составляет ±3мм. Если расхождение больше — готовы рассмотреть замену. Напишите нам для уточнения.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на негативный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

-- Добавить примеры для "Ответ на нейтральный отзыв WB"
INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Нормальная фанера, ничего особенного. Для дачи сойдёт.',
       'Спасибо за отзыв! Рады, что товар подошёл для ваших задач.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на нейтральный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Отзыв: Качество среднее, но за такую цену ожидал большего.',
       'Благодарим за обратную связь. Учтём ваше мнение. Если есть конкретные пожелания — будем рады услышать.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на нейтральный отзыв WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

-- Добавить примеры для "Ответ на вопрос WB"
INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Вопрос: Подойдёт ли эта фанера для пола в ванной комнате?',
       'Для ванной рекомендуем влагостойкую фанеру марки ФСФ. Данный товар — марки ФК, подходит для сухих помещений. Посмотрите раздел "Влагостойкая фанера" в нашем каталоге.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на вопрос WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Вопрос: Какой максимальный вес выдерживает полка из этой фанеры?',
       'Несущая способность зависит от толщины и пролёта полки. Фанера 18мм при пролёте 60см выдерживает до 30кг. Для больших нагрузок рекомендуем увеличить толщину или добавить рёбра жёсткости.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на вопрос WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT IGNORE INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
SELECT id, 'Вопрос: Есть ли у вас фанера толщиной 6мм?',
       'Да, фанера 6мм есть в наличии. Найдите её в нашем каталоге по фильтру "толщина". Если нужна помощь с выбором — напишите.', 1, NOW()
FROM ai_prompts WHERE name = 'Ответ на вопрос WB' AND marketplace = 'wildberries'
ON DUPLICATE KEY UPDATE is_active = 1;
