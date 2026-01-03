<?php
/**
 * Тестовый скрипт для генерации ответа на отзыв
 * Запуск: docker exec price-manager-php php /var/www/html/cli/test-review-generation.php [review_id]
 */

// Подключение конфигурации
require_once dirname(__DIR__) . '/app/config.php';

$reviewId = $argv[1] ?? null;

if (!$reviewId) {
    echo "Usage: php test-review-generation.php <review_id>\n";
    exit(1);
}

echo "=== AI Review Generation Test ===\n";
echo "Review ID: $reviewId\n\n";

try {
    $db = Database::getInstance();

    // Получаем API ключ из user_api_keys
    $apiKey = $db->fetchColumn("SELECT api_key FROM user_api_keys WHERE service = 'claude' AND is_active = 1 LIMIT 1");
    if (!$apiKey) {
        echo "ERROR: Claude API key not found in user_api_keys\n";
        exit(1);
    }

    // Получаем модель из ai_settings
    $model = $db->fetchColumn("SELECT setting_value FROM ai_settings WHERE setting_key = 'model'") ?: 'claude-3-haiku-20240307';

    // Получаем marketplace отзыва
    $review = $db->fetchOne("SELECT marketplace FROM ai_reviews WHERE id = ?", [$reviewId]);
    if (!$review) {
        echo "ERROR: Review #$reviewId not found\n";
        exit(1);
    }

    echo "Marketplace: {$review['marketplace']}\n";
    echo "Model: $model\n";
    echo "API Key: " . substr($apiKey, 0, 20) . "...\n\n";

    // Создаем AIAssistant - он сам получит API ключ из базы
    $ai = new AIAssistant($review['marketplace']);
    if (!$ai->initClaude()) {
        echo "ERROR: Failed to initialize Claude API\n";
        exit(1);
    }

    echo "Generating response...\n";
    echo "Check logs with: docker logs price-manager-php --tail=100\n\n";

    $result = $ai->generateReviewResponse((int)$reviewId);

    if ($result['success']) {
        echo "SUCCESS!\n";
        echo "---\n";
        echo $result['response'] . "\n";
        echo "---\n";

        if (isset($result['tokens'])) {
            echo "Tokens used: " . $result['tokens']['total_tokens'] . "\n";
        }

        if (isset($result['generation_meta'])) {
            echo "\nGeneration Meta:\n";
            print_r($result['generation_meta']);
        }
    } else {
        echo "FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
