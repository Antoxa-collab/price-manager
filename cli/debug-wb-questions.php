<?php
/**
 * Debug script to check WB questions API response
 */

require_once dirname(__DIR__) . '/app/config.php';

echo "=== Debug WB Questions API ===\n\n";

try {
    $db = Database::getInstance();

    // Get user ID
    $user = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 LIMIT 1");
    if (!$user) {
        echo "ERROR: No active users\n";
        exit(1);
    }
    $userId = $user['id'];
    echo "User ID: {$userId}\n";

    // Initialize WB API
    require_once APP_PATH . '/classes/WildberriesAPI.php';
    $wbApi = new WildberriesAPI($userId);

    if (!$wbApi->isConfigured()) {
        echo "ERROR: WB API not configured\n";
        exit(1);
    }

    echo "Fetching questions from WB API...\n\n";

    // Get just a few questions to debug
    $result = $wbApi->getQuestions(3, 0, false);

    if (!$result['success']) {
        echo "ERROR: " . ($result['error'] ?? 'Unknown') . "\n";
        exit(1);
    }

    echo "Got " . count($result['questions']) . " questions:\n\n";

    foreach ($result['questions'] as $idx => $q) {
        echo "--- Question #{$idx} ---\n";
        echo "ID: {$q['id']}\n";
        echo "nmId: " . var_export($q['nmId'], true) . "\n";
        echo "Product Name: {$q['productName']}\n";
        echo "Supplier Article: {$q['supplierArticle']}\n";
        echo "Text: " . mb_substr($q['text'], 0, 100) . "\n";
        echo "\n";
    }

    // Make direct API call to see raw response
    echo "=== Raw API Response ===\n";

    $settings = $db->fetchOne(
        "SELECT api_key FROM api_settings WHERE user_id = ? AND platform = 'wildberries' AND is_active = 1",
        [$userId]
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://feedbacks-api.wildberries.ru/api/v1/questions?take=1&skip=0&isAnswered=false',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $settings['api_key'],
            'Content-Type: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: {$httpCode}\n";
    echo "Response (first 2000 chars):\n";
    echo substr($response, 0, 2000) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
