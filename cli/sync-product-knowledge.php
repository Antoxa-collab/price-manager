<?php
/**
 * CLI скрипт для синхронизации базы знаний о товарах с WB
 * Запуск: docker exec price-manager-php php /var/www/html/cli/sync-product-knowledge.php
 */

require_once dirname(__DIR__) . '/app/config.php';

echo "=== Синхронизация базы знаний о товарах ===\n\n";

try {
    $db = Database::getInstance();

    // Получаем user_id (первого активного пользователя)
    $user = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 LIMIT 1");
    if (!$user) {
        echo "ERROR: Нет активных пользователей\n";
        exit(1);
    }
    $userId = $user['id'];
    echo "User ID: {$userId}\n";

    // Инициализируем WB API
    require_once APP_PATH . '/classes/WildberriesAPI.php';
    $wbApi = new WildberriesAPI($userId);

    if (!$wbApi->isConfigured()) {
        echo "ERROR: API токен Wildberries не настроен\n";
        exit(1);
    }

    echo "WB API настроен\n\n";

    // Инициализируем ProductKnowledge
    require_once APP_PATH . '/classes/ProductKnowledge.php';
    $productKnowledge = new ProductKnowledge();
    $productKnowledge->setWildberriesAPI($wbApi);

    echo "Начинаем синхронизацию карточек товаров...\n";

    $result = $productKnowledge->syncAllFromWildberries($userId);

    echo "\n=== РЕЗУЛЬТАТ ===\n";
    echo "Всего карточек: {$result['total']}\n";
    echo "Новых добавлено: {$result['synced']}\n";
    echo "Обновлено: {$result['updated']}\n";
    echo "Ошибок: {$result['errors']}\n";

    // Проверяем что сохранилось
    $count = $db->fetchColumn("SELECT COUNT(*) FROM product_knowledge");
    echo "\nЗаписей в product_knowledge: {$count}\n";

    // Показываем пример
    $sample = $db->fetchOne("SELECT marketplace_product_id, product_name, weight, dimensions FROM product_knowledge LIMIT 1");
    if ($sample) {
        echo "\nПример карточки:\n";
        echo "  nmId: {$sample['marketplace_product_id']}\n";
        echo "  Название: {$sample['product_name']}\n";
        echo "  Вес: " . ($sample['weight'] ?: 'нет') . "\n";
        echo "  Размеры: " . ($sample['dimensions'] ?: 'нет') . "\n";
    }

    echo "\n=== Синхронизация завершена ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
