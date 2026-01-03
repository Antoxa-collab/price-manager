<?php
/**
 * Тестовый скрипт для диагностики Claude API
 * Запуск: docker exec -it price-manager-php php /var/www/html/cli/test-claude.php
 */

require_once __DIR__ . '/../app/classes/Database.php';
require_once __DIR__ . '/../app/classes/ClaudeAPI.php';

echo "=== Claude API Diagnostic Test ===\n\n";

// Получаем API ключ из базы данных
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT value FROM settings WHERE `key` = 'claude_api_key' LIMIT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || empty($result['value'])) {
        echo "ERROR: Claude API key not found in database\n";
        echo "Please save the API key in Settings first.\n";
        exit(1);
    }

    $apiKey = $result['value'];
    echo "API Key found: " . substr($apiKey, 0, 20) . "...\n";

} catch (Exception $e) {
    echo "ERROR: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Тест 1: Проверка доступных моделей
echo "\n--- Available Models ---\n";
$models = ClaudeAPI::getAvailableModels();
foreach ($models as $id => $name) {
    echo "  - $id: $name\n";
}

// Тест 2: Создание экземпляра ClaudeAPI
echo "\n--- Creating ClaudeAPI Instance ---\n";
try {
    $claude = new ClaudeAPI($apiKey, [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 100,
        'temperature' => 0.7
    ]);
    echo "Instance created successfully\n";
    echo "Current model: " . $claude->getModel() . "\n";
} catch (Exception $e) {
    echo "ERROR: Failed to create instance: " . $e->getMessage() . "\n";
    exit(1);
}

// Тест 3: Валидация API ключа
echo "\n--- Validating API Key ---\n";
$isValid = $claude->validateApiKey();

if ($isValid) {
    echo "SUCCESS: API key is valid!\n";

    $usage = $claude->getLastUsage();
    if ($usage) {
        echo "Tokens used: " . $usage['total_tokens'] . "\n";
    }

    $time = $claude->getLastGenerationTime();
    if ($time) {
        echo "Response time: " . $time . "ms\n";
    }
} else {
    echo "FAILED: API key validation failed\n";
    echo "Error: " . $claude->getLastError() . "\n";
}

// Тест 4: Прямой cURL запрос (для сравнения)
echo "\n--- Direct cURL Test ---\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 50,
        'messages' => [
            ['role' => 'user', 'content' => 'Say "test" and nothing else']
        ]
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}

$data = json_decode($response, true);
if ($httpCode === 200) {
    echo "SUCCESS: Direct API call works!\n";
    if (isset($data['content'][0]['text'])) {
        echo "Response: " . $data['content'][0]['text'] . "\n";
    }
} else {
    echo "FAILED: HTTP $httpCode\n";
    if (isset($data['error'])) {
        echo "Error type: " . ($data['error']['type'] ?? 'unknown') . "\n";
        echo "Error message: " . ($data['error']['message'] ?? 'unknown') . "\n";
    } else {
        echo "Raw response: " . substr($response, 0, 500) . "\n";
    }
}

echo "\n=== Test Complete ===\n";
