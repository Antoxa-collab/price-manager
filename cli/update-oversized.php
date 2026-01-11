<?php
/**
 * Скрипт для обновления флага is_oversized для КГТ-товаров
 * Запуск: docker exec -it price-manager-php php /var/www/html/cli/update-oversized.php
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/classes/Database.php';

echo "=== Обновление флага КГТ (is_oversized) ===\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Сначала сбросим все флаги
    $pdo->exec("UPDATE wb_products_cache SET is_oversized = 0");
    echo "Сброшены все флаги is_oversized\n";

    // Размеры, которые точно > 1200мм (КГТ)
    $oversizedPatterns = [
        '%1350%',   // 1350мм > 1200
        '%1250%',   // 1250мм > 1200
        '%2800%',   // 2800мм
        '%2745%',   // 2745мм
        '%2070%',   // 2070мм
        '%1700%',   // 1700мм
        '%1525%',   // 1525мм
        '%1830%',   // 1830мм
        '%2440%',   // 2440мм
        '%2500%',   // 2500мм
        '%3050%',   // 3050мм
        '%1220%',   // 1220мм > 1200
    ];

    $conditions = array_map(fn($p) => "vendor_code LIKE '$p'", $oversizedPatterns);
    $whereClause = implode(' OR ', $conditions);

    $sql = "UPDATE wb_products_cache SET is_oversized = 1 WHERE $whereClause";
    $updated = $pdo->exec($sql);

    echo "Обновлено записей: $updated\n\n";

    // Покажем статистику
    $stats = $pdo->query("
        SELECT
            is_oversized,
            COUNT(*) as cnt
        FROM wb_products_cache
        GROUP BY is_oversized
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "Статистика:\n";
    foreach ($stats as $row) {
        $label = $row['is_oversized'] ? 'КГТ (oversized)' : 'Обычные';
        echo "  $label: {$row['cnt']} товаров\n";
    }

    // Покажем примеры КГТ-товаров
    echo "\nПримеры КГТ-товаров:\n";
    $examples = $pdo->query("
        SELECT nm_id, vendor_code, title
        FROM wb_products_cache
        WHERE is_oversized = 1
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($examples as $row) {
        echo "  - {$row['vendor_code']}: " . mb_substr($row['title'], 0, 50) . "...\n";
    }

    echo "\n✅ Готово!\n";

} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
