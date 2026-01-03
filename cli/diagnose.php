<?php
/**
 * Диагностика проблем с API и DNS
 * Запуск: docker exec price-manager-php php /var/www/html/cli/diagnose.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== ДИАГНОСТИКА PRICE MANAGER ===\n\n";

// 1. Проверка PHP
echo "1. PHP версия: " . PHP_VERSION . "\n";
echo "   Extensions: pdo=" . (extension_loaded('pdo') ? 'OK' : 'NO') .
     ", curl=" . (extension_loaded('curl') ? 'OK' : 'NO') .
     ", json=" . (extension_loaded('json') ? 'OK' : 'NO') . "\n\n";

// 2. Проверка подключения к БД
echo "2. ПРОВЕРКА БАЗЫ ДАННЫХ\n";
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_DATABASE') ?: 'price_manager'
    );
    $pdo = new PDO($dsn,
        getenv('DB_USERNAME') ?: 'price_user',
        getenv('DB_PASSWORD') ?: 'price_password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "   [OK] Подключение к БД успешно\n";

    // Проверка таблиц
    $tables = ['products', 'users', 'api_settings', 'ai_reviews', 'ai_questions', 'product_mappings'];
    foreach ($tables as $table) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            echo "   [OK] Таблица {$table}: {$count} записей\n";
        } catch (Exception $e) {
            echo "   [ERR] Таблица {$table}: " . $e->getMessage() . "\n";
        }
    }

    // Структура products
    echo "\n   Структура таблицы products:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Колонки: " . implode(', ', $cols) . "\n";

} catch (Exception $e) {
    echo "   [ERR] Ошибка БД: " . $e->getMessage() . "\n";
}

// 3. Проверка DNS
echo "\n3. ПРОВЕРКА DNS (Wildberries)\n";
$hosts = [
    'common-api.wildberries.ru',
    'feedbacks-api.wildberries.ru',
    'content-api.wildberries.ru',
    'discounts-prices-api.wildberries.ru'
];

foreach ($hosts as $host) {
    $ip = gethostbyname($host);
    if ($ip === $host) {
        echo "   [ERR] {$host}: DNS не резолвится\n";
    } else {
        echo "   [OK] {$host}: {$ip}\n";
    }
}

// 4. Проверка /etc/hosts
echo "\n4. ПРОВЕРКА /etc/hosts\n";
if (file_exists('/etc/hosts')) {
    $hosts_content = file_get_contents('/etc/hosts');
    if (strpos($hosts_content, 'wildberries') !== false) {
        echo "   [OK] Wildberries hosts записи найдены\n";
        // Показать строки с wildberries
        foreach (explode("\n", $hosts_content) as $line) {
            if (stripos($line, 'wildberries') !== false) {
                echo "   " . trim($line) . "\n";
            }
        }
    } else {
        echo "   [WARN] Wildberries записей в /etc/hosts нет\n";
    }
} else {
    echo "   [ERR] /etc/hosts не найден\n";
}

// 5. Тест HTTP запроса к WB API
echo "\n5. ТЕСТ HTTP ЗАПРОСА К WB API\n";
$ch = curl_init('https://common-api.wildberries.ru/ping');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json']
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "   [ERR] CURL ошибка: {$error}\n";
} else {
    echo "   [OK] HTTP {$httpCode}: " . substr($response, 0, 100) . "\n";
}

// 6. Проверка конфигурации
echo "\n6. ПРОВЕРКА КОНФИГУРАЦИИ\n";
$configFile = '/var/www/html/app/config.php';
if (file_exists($configFile)) {
    echo "   [OK] config.php существует\n";
} else {
    echo "   [ERR] config.php не найден: {$configFile}\n";
}

$indexFile = '/var/www/html/public/index.php';
if (file_exists($indexFile)) {
    $content = file_get_contents($indexFile);
    if (strpos($content, "case '/api/products':") !== false) {
        echo "   [OK] Endpoint /api/products найден в index.php\n";
    } else {
        echo "   [ERR] Endpoint /api/products НЕ найден в index.php!\n";
    }

    if (strpos($content, "case '/api/ai/sync-wb-reviews':") !== false) {
        echo "   [OK] Endpoint /api/ai/sync-wb-reviews найден\n";
    } else {
        echo "   [ERR] Endpoint /api/ai/sync-wb-reviews НЕ найден!\n";
    }
} else {
    echo "   [ERR] index.php не найден\n";
}

echo "\n=== ДИАГНОСТИКА ЗАВЕРШЕНА ===\n";
