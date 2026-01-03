<?php
/**
 * Тестовый скрипт для диагностики Claude API и добавления примеров
 * URL: http://192.168.0.213:8080/test-api.php
 * УДАЛИТЬ ПОСЛЕ ДИАГНОСТИКИ!
 */

// Диагностика настроек магазина
if (isset($_GET['settings'])) {
    require_once dirname(__DIR__) . '/app/config.php';
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = Database::getInstance();

        // 1. Все настройки в ai_settings
        $allSettings = $db->fetchAll("SELECT * FROM ai_settings ORDER BY marketplace, setting_key");

        // 2. Настройки store_name и store_signature
        $storeSettings = $db->fetchAll(
            "SELECT * FROM ai_settings WHERE setting_key IN ('store_name', 'store_signature')"
        );

        // 3. Проверить через AIAssistant
        $ai = new AIAssistant('ozon');
        $settingsViaClass = $ai->getSettings();

        echo json_encode([
            'success' => true,
            'all_settings' => $allSettings,
            'store_settings' => $storeSettings,
            'via_ai_assistant' => [
                'store_name' => $settingsViaClass['store_name'] ?? 'NOT FOUND',
                'store_signature' => $settingsViaClass['store_signature'] ?? 'NOT FOUND'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Тест сохранения настройки
if (isset($_GET['test-save'])) {
    require_once dirname(__DIR__) . '/app/config.php';
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = Database::getInstance();
        $testValue = 'ТЕСТ_' . date('H:i:s');

        // Сохраняем через AIAssistant
        $ai = new AIAssistant('ozon');
        $result = $ai->saveSetting('store_signature', $testValue, 'all');

        // Читаем обратно
        $saved = $db->fetchOne(
            "SELECT * FROM ai_settings WHERE setting_key = 'store_signature' AND marketplace = 'all'"
        );

        // Читаем через getSettings
        $settingsAfter = $ai->getSettings();

        echo json_encode([
            'success' => true,
            'test_value' => $testValue,
            'save_result' => $result,
            'saved_in_db' => $saved,
            'via_get_settings' => $settingsAfter['store_signature'] ?? 'NOT FOUND',
            'match' => ($settingsAfter['store_signature'] ?? '') === $testValue
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Диагностика примеров
if (isset($_GET['diagnose'])) {
    require_once dirname(__DIR__) . '/app/config.php';
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = Database::getInstance();

        // 1. Структура таблицы
        $structure = $db->fetchAll("DESCRIBE ai_examples");

        // 2. Все примеры с промптами
        $examples = $db->fetchAll(
            "SELECT e.id, e.prompt_id, p.name as prompt_name, p.marketplace,
                    LEFT(e.input_text, 50) as input_preview, e.is_active
             FROM ai_examples e
             LEFT JOIN ai_prompts p ON e.prompt_id = p.id
             ORDER BY p.marketplace, e.prompt_id, e.id"
        );

        // 3. Количество по маркетплейсам
        $byMarketplace = $db->fetchAll(
            "SELECT p.marketplace, COUNT(e.id) as example_count
             FROM ai_examples e
             JOIN ai_prompts p ON e.prompt_id = p.id
             WHERE e.is_active = 1
             GROUP BY p.marketplace"
        );

        // 4. Проверка связей
        $orphanedExamples = $db->fetchAll(
            "SELECT e.* FROM ai_examples e
             LEFT JOIN ai_prompts p ON e.prompt_id = p.id
             WHERE p.id IS NULL"
        );

        echo json_encode([
            'success' => true,
            'table_structure' => $structure,
            'examples' => $examples,
            'by_marketplace' => $byMarketplace,
            'orphaned_examples' => $orphanedExamples,
            'total_examples' => count($examples)
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Если запрос с параметром add-examples, добавляем примеры
if (isset($_GET['add-examples'])) {
    require_once dirname(__DIR__) . '/app/config.php';
    header('Content-Type: application/json; charset=utf-8');

    try {
        $db = Database::getInstance();

        $prompts = $db->fetchAll(
            "SELECT id, name FROM ai_prompts WHERE marketplace = 'wildberries' AND is_active = 1"
        );

        $examplesData = [
            'Ответ на положительный отзыв WB' => [
                ['input' => 'Отзыв: Отличная фанера, ровная, без сучков. Использовал для полок в гараже - идеально подошла. Буду заказывать ещё!', 'output' => 'Благодарим за отзыв! Рады, что фанера подошла для ваших полок. Будем рады видеть вас снова!'],
                ['input' => 'Отзыв: Хорошее качество за эту цену. Доставили быстро, упаковано надёжно.', 'output' => 'Спасибо за оценку! Стараемся поддерживать баланс качества и цены. Надёжная упаковка — наш приоритет при доставке.'],
                ['input' => 'Отзыв: Супер! Заказываю уже третий раз, всегда отличное качество.', 'output' => 'Благодарим за доверие и постоянство! Приятно, что качество соответствует вашим ожиданиям.']
            ],
            'Ответ на негативный отзыв WB' => [
                ['input' => 'Отзыв: Пришла фанера с трещиной на углу. Очень расстроен, пришлось отпиливать часть.', 'output' => 'Приносим извинения за доставленное неудобство. Повреждение могло произойти при транспортировке. Пожалуйста, свяжитесь с нами — поможем решить вопрос.'],
                ['input' => 'Отзыв: Размеры не соответствуют заявленным, на 2 см меньше.', 'output' => 'Благодарим за обратную связь. Допуск по ГОСТ составляет ±3мм. Если расхождение больше — готовы рассмотреть замену. Напишите нам для уточнения.']
            ],
            'Ответ на нейтральный отзыв WB' => [
                ['input' => 'Отзыв: Нормальная фанера, ничего особенного. Для дачи сойдёт.', 'output' => 'Спасибо за отзыв! Рады, что товар подошёл для ваших задач.'],
                ['input' => 'Отзыв: Качество среднее, но за такую цену ожидал большего.', 'output' => 'Благодарим за обратную связь. Учтём ваше мнение. Если есть конкретные пожелания — будем рады услышать.']
            ],
            'Ответ на вопрос WB' => [
                ['input' => 'Вопрос: Подойдёт ли эта фанера для пола в ванной комнате?', 'output' => 'Для ванной рекомендуем влагостойкую фанеру марки ФСФ. Данный товар — марки ФК, подходит для сухих помещений. Посмотрите раздел "Влагостойкая фанера" в нашем каталоге.'],
                ['input' => 'Вопрос: Какой максимальный вес выдерживает полка из этой фанеры?', 'output' => 'Несущая способность зависит от толщины и пролёта полки. Фанера 18мм при пролёте 60см выдерживает до 30кг. Для больших нагрузок рекомендуем увеличить толщину или добавить рёбра жёсткости.'],
                ['input' => 'Вопрос: Есть ли у вас фанера толщиной 6мм?', 'output' => 'Да, фанера 6мм есть в наличии. Найдите её в нашем каталоге по фильтру "толщина". Если нужна помощь с выбором — напишите.']
            ]
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($prompts as $prompt) {
            $promptName = $prompt['name'];
            $promptId = $prompt['id'];

            if (!isset($examplesData[$promptName])) {
                continue;
            }

            foreach ($examplesData[$promptName] as $example) {
                $existing = $db->fetchOne(
                    "SELECT id FROM ai_examples WHERE prompt_id = ? AND input_text = ?",
                    [$promptId, $example['input']]
                );

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $db->execute(
                    "INSERT INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
                     VALUES (?, ?, ?, 1, NOW())",
                    [$promptId, $example['input'], $example['output']]
                );
                $inserted++;
            }
        }

        $count = $db->fetchColumn("SELECT COUNT(*) FROM ai_examples WHERE is_active = 1");

        $stats = $db->fetchAll(
            "SELECT p.name, COUNT(e.id) as cnt
             FROM ai_prompts p
             LEFT JOIN ai_examples e ON e.prompt_id = p.id AND e.is_active = 1
             WHERE p.marketplace = 'wildberries'
             GROUP BY p.id, p.name"
        );

        echo json_encode([
            'success' => true,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'total_examples' => $count,
            'stats' => $stats
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== Claude API Direct Test ===\n\n";

// API ключ (тот же что в настройках)
$apiKey = 'sk-ant-api03-ki2rO5ZRPJnFlVM0z6Ri9-dCjezuzHFsHkaGiUNbF1elAP03e4e20l';

// Прокси из переменной окружения
$proxyUrl = getenv('CLAUDE_PROXY');

echo "API Key: " . substr($apiKey, 0, 20) . "...\n";
echo "Model: claude-3-haiku-20240307\n";
echo "Proxy: " . ($proxyUrl ?: 'NOT SET') . "\n\n";

// Формируем JSON вручную чтобы точно знать что отправляем
$jsonData = '{"model":"claude-3-haiku-20240307","max_tokens":50,"messages":[{"role":"user","content":"Say OK"}]}';

echo "Request JSON:\n$jsonData\n\n";

// cURL запрос
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
    'anthropic-version: 2023-06-01'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

// Использовать прокси если установлен
if (!empty($proxyUrl)) {
    curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    echo ">>> Using SOCKS5 proxy: $proxyUrl\n\n";
}

// Для отладки - показать что отправляем
curl_setopt($ch, CURLOPT_VERBOSE, true);
$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlInfo = curl_getinfo($ch);

// Получаем verbose лог
rewind($verbose);
$verboseLog = stream_get_contents($verbose);
fclose($verbose);

curl_close($ch);

echo "=== RESULTS ===\n\n";
echo "HTTP Code: $httpCode\n";

if ($curlError) {
    echo "cURL Error: $curlError\n";
}

echo "\nResponse:\n$response\n";

echo "\n=== Connection Info ===\n";
echo "Total time: " . $curlInfo['total_time'] . "s\n";
echo "Connect time: " . $curlInfo['connect_time'] . "s\n";
echo "Primary IP: " . $curlInfo['primary_ip'] . "\n";

echo "\n=== Verbose Log ===\n";
echo $verboseLog;

echo "\n\n=== DIAGNOSIS ===\n";
if ($httpCode === 200) {
    echo "SUCCESS! Claude API is working" . ($proxyUrl ? " through proxy" : "") . ".\n";
    $data = json_decode($response, true);
    if (isset($data['content'][0]['text'])) {
        echo "Claude response: " . $data['content'][0]['text'] . "\n";
    }
} elseif ($httpCode === 403) {
    echo "FORBIDDEN (403)\n";
    if (empty($proxyUrl)) {
        echo "Proxy is NOT configured!\n";
        echo "Check CLAUDE_PROXY in docker-compose.yml\n";
    } else {
        echo "Proxy might not be working correctly.\n";
        echo "Check: docker-compose logs shadowsocks\n";
    }
} elseif ($httpCode === 401) {
    echo "UNAUTHORIZED (401) - Invalid API key\n";
} elseif ($httpCode === 400) {
    echo "BAD REQUEST (400) - Check JSON or model name\n";
} elseif ($httpCode === 0) {
    echo "CONNECTION FAILED\n";
    if (!empty($proxyUrl)) {
        echo "Cannot connect through proxy.\n";
        echo "Check shadowsocks: docker-compose logs shadowsocks\n";
        echo "Should show: listening at 0.0.0.0:1080\n";
    } else {
        echo "Cannot reach api.anthropic.com\n";
    }
} else {
    echo "UNKNOWN ERROR ($httpCode)\n";
}

echo "\n=== END OF TEST ===\n";
