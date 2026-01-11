<?php
/**
 * Скрипт для исправления настроек Яндекс.Маркет
 *
 * Использование:
 *   php cli/fix-ym-settings.php
 *
 * Или через Docker:
 *   docker exec price-manager-app php /var/www/html/cli/fix-ym-settings.php
 */

require_once __DIR__ . '/../app/classes/Database.php';

echo "=== Исправление настроек Яндекс.Маркет ===\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // 1. Проверяем текущие настройки
    echo "1. Текущие настройки YM:\n";
    $settings = $db->fetchOne(
        "SELECT * FROM api_settings WHERE platform = 'yandex_market'"
    );

    if ($settings) {
        echo "   - Business ID (client_id): {$settings['client_id']}\n";
        echo "   - Campaign ID (shop_id): {$settings['shop_id']}\n";
        echo "   - Warehouse ID: {$settings['warehouse_id']}\n";
    } else {
        echo "   Настройки не найдены!\n";
    }

    // 2. Исправляем Campaign ID если он равен Business ID
    echo "\n2. Исправление Campaign ID:\n";
    if ($settings && $settings['shop_id'] === $settings['client_id']) {
        // Campaign ID совпадает с Business ID - это ошибка
        $correctCampaignId = '23641355'; // Правильный Campaign ID из debug

        $db->execute(
            "UPDATE api_settings SET shop_id = ? WHERE platform = 'yandex_market'",
            [$correctCampaignId]
        );

        echo "   Campaign ID исправлен: {$settings['shop_id']} -> {$correctCampaignId}\n";
    } else {
        echo "   Campaign ID уже отличается от Business ID, пропускаем\n";
    }

    // 3. Проверяем/создаём таблицу ym_products_cache
    echo "\n3. Проверка таблицы ym_products_cache:\n";

    $tableExists = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'ym_products_cache'"
    );

    if ((int)$tableExists['cnt'] > 0) {
        echo "   Таблица существует\n";

        // Считаем записи
        $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM ym_products_cache");
        echo "   Записей в таблице: {$count['cnt']}\n";
    } else {
        echo "   Таблица не существует, создаём...\n";

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ym_products_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL DEFAULT 1,
                offer_id VARCHAR(255) NOT NULL COMMENT 'SKU товара (offerId)',
                shop_sku VARCHAR(255) COMMENT 'Альтернативный SKU',
                name VARCHAR(500) COMMENT 'Название товара',
                category_id INT COMMENT 'ID категории ЯМ',
                category_name VARCHAR(255) COMMENT 'Название категории',
                vendor VARCHAR(255) COMMENT 'Бренд/производитель',
                barcode VARCHAR(100) COMMENT 'Штрихкод',
                description TEXT COMMENT 'Описание',
                price DECIMAL(12,2) DEFAULT 0 COMMENT 'Текущая цена',
                old_price DECIMAL(12,2) DEFAULT 0 COMMENT 'Зачёркнутая цена',
                vat INT DEFAULT NULL COMMENT 'НДС (2,5,6,7)',
                market_sku BIGINT COMMENT 'SKU на Маркете',
                card_status VARCHAR(50) COMMENT 'Статус карточки',
                availability VARCHAR(50) COMMENT 'Доступность',
                stock INT DEFAULT 0 COMMENT 'Остаток',
                photo_url VARCHAR(500) COMMENT 'URL фото',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_offer (user_id, offer_id),
                INDEX idx_shop_sku (shop_sku),
                INDEX idx_name (name(100)),
                INDEX idx_category (category_id),
                INDEX idx_barcode (barcode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        echo "   Таблица создана!\n";
    }

    // 4. Проверяем обновлённые настройки
    echo "\n4. Обновлённые настройки:\n";
    $updatedSettings = $db->fetchOne(
        "SELECT * FROM api_settings WHERE platform = 'yandex_market'"
    );

    if ($updatedSettings) {
        echo "   - Business ID: {$updatedSettings['client_id']}\n";
        echo "   - Campaign ID: {$updatedSettings['shop_id']}\n";
        echo "   - Warehouse ID: {$updatedSettings['warehouse_id']}\n";
    }

    echo "\n=== Готово! ===\n";
    echo "\nТеперь можно:\n";
    echo "1. Проверить /api/yandex/debug - endpoints должны работать\n";
    echo "2. Синхронизировать товары в интерфейсе\n";
    echo "3. Открыть калькулятор - не должно быть ошибки 500\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
