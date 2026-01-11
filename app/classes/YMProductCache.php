<?php

/**
 * Класс для работы с кэшем товаров Яндекс.Маркет
 */
class YMProductCache
{
    private Database $db;
    private int $userId;
    private ?YandexMarketAPI $api = null;

    public function __construct(int $userId)
    {
        error_log("[YMProductCache] Constructor called with userId={$userId}");
        $this->userId = $userId;
        $this->db = Database::getInstance();
        error_log("[YMProductCache] Database instance obtained");
        $this->ensureTables();
        error_log("[YMProductCache] Constructor complete");
    }

    /**
     * Ленивая загрузка API (создаётся только когда нужен)
     */
    private function getApi(): YandexMarketAPI
    {
        if ($this->api === null) {
            $this->api = new YandexMarketAPI($this->userId);
        }
        return $this->api;
    }

    /**
     * Создание таблиц для кэша ЯМ
     */
    private function ensureTables(): void
    {
        try {
            $pdo = $this->db->getConnection();

            // Основная таблица кэша товаров
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
        } catch (Exception $e) {
            error_log("[YMProductCache] ensureTables error: " . $e->getMessage());
            // Игнорируем ошибку создания таблицы — она может уже существовать
        }
    }

    /**
     * Синхронизация всех товаров с Яндекс.Маркетом
     */
    public function syncAllProducts(): array
    {
        error_log("=== YMProductCache::syncAllProducts START ===");

        if (!$this->getApi()->isConfigured()) {
            error_log("[YMProductCache] API not configured!");
            return ['success' => false, 'error' => 'API Яндекс.Маркет не настроен'];
        }

        error_log("[YMProductCache] API configured, calling getAllOffers...");

        $result = $this->getApi()->getAllOffers();

        error_log("[YMProductCache] getAllOffers returned success=" . ($result['success'] ? 'true' : 'false'));

        if (!$result['success']) {
            error_log("[YMProductCache] getAllOffers failed: " . ($result['error'] ?? 'unknown'));
            return $result;
        }

        $offers = $result['data'];
        error_log("[YMProductCache] Got " . count($offers) . " offers to process");

        if (empty($offers)) {
            error_log("[YMProductCache] No offers received!");
            return [
                'success' => true,
                'total' => 0,
                'saved' => 0,
                'errors' => [],
                'message' => 'API вернул пустой список товаров'
            ];
        }

        // Показать структуру первого товара
        if (!empty($offers)) {
            $first = $offers[0];
            error_log("[YMProductCache] First offer structure: " . json_encode($first, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $saved = 0;
        $errors = [];

        foreach ($offers as $index => $offerMapping) {
            // Проверяем структуру - может быть offer внутри offerMapping, или сразу offer
            $offer = $offerMapping['offer'] ?? $offerMapping;
            $mapping = $offerMapping['mapping'] ?? [];

            if ($index < 3) {
                error_log("[YMProductCache] Processing item {$index}: " . json_encode($offer, JSON_UNESCAPED_UNICODE));
            }

            try {
                $this->saveOffer($offer, $mapping);
                $saved++;
            } catch (Exception $e) {
                $offerId = $offer['offerId'] ?? $offer['shopSku'] ?? 'unknown';
                $errors[] = "{$offerId}: " . $e->getMessage();
                error_log("[YMProductCache] Error saving {$offerId}: " . $e->getMessage());
            }
        }

        error_log("=== YMProductCache::syncAllProducts COMPLETE: saved={$saved}, errors=" . count($errors) . " ===");

        return [
            'success' => true,
            'total' => count($offers),
            'saved' => $saved,
            'errors' => array_slice($errors, 0, 10), // Первые 10 ошибок
            'message' => "Синхронизировано: {$saved} товаров"
        ];
    }

    /**
     * Сохранение оффера в кэш
     */
    public function saveOffer(array $offer, array $mapping = []): void
    {
        // Попробовать разные варианты получения ID
        $offerId = $offer['offerId'] ?? $offer['shopSku'] ?? $offer['sku'] ?? '';

        if (empty($offerId)) {
            error_log("[YMProductCache::saveOffer] EMPTY offerId! Offer keys: " . implode(', ', array_keys($offer)));
            return;
        }

        // Извлекаем данные из структуры ЯМ
        $name = $offer['name'] ?? '';
        $shopSku = $offer['shopSku'] ?? $offerId;
        $categoryName = $offer['category'] ?? null; // API возвращает category как строку!
        $vendor = $offer['vendor'] ?? '';

        // Штрихкоды - может быть массив
        $barcodes = $offer['barcodes'] ?? [];
        $barcode = is_array($barcodes) && !empty($barcodes) ? $barcodes[0] : '';

        $description = $offer['description'] ?? '';

        // Цена из basicPrice
        $basicPrice = $offer['basicPrice'] ?? [];
        $price = (float)($basicPrice['value'] ?? 0);
        $oldPrice = (float)($basicPrice['discountBase'] ?? 0);

        // НДС
        $vat = isset($offer['vat']) ? (int)$offer['vat'] : null;

        // Данные маппинга
        $marketSku = $mapping['marketSku'] ?? null;
        $cardStatus = $mapping['cardStatus'] ?? $offer['cardStatus'] ?? null;

        // Фото
        $pictures = $offer['pictures'] ?? [];
        $photoUrl = is_array($pictures) && !empty($pictures) ? $pictures[0] : '';

        error_log("[YMProductCache::saveOffer] Saving: offerId={$offerId}, name=" . mb_substr($name, 0, 50) . ", price={$price}");

        try {
            $this->db->execute("
                INSERT INTO ym_products_cache
                    (user_id, offer_id, shop_sku, name, category_name, vendor, barcode, description,
                     price, old_price, vat, market_sku, card_status, photo_url)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    shop_sku = VALUES(shop_sku),
                    name = VALUES(name),
                    category_name = VALUES(category_name),
                    vendor = VALUES(vendor),
                    barcode = VALUES(barcode),
                    description = VALUES(description),
                    price = VALUES(price),
                    old_price = VALUES(old_price),
                    vat = VALUES(vat),
                    market_sku = VALUES(market_sku),
                    card_status = VALUES(card_status),
                    photo_url = VALUES(photo_url),
                    updated_at = CURRENT_TIMESTAMP
            ", [
                $this->userId,
                $offerId,
                mb_substr($shopSku, 0, 255),
                mb_substr($name, 0, 500),
                $categoryName ? mb_substr($categoryName, 0, 255) : null,
                mb_substr($vendor, 0, 255),
                mb_substr($barcode, 0, 100),
                $description,
                $price,
                $oldPrice,
                $vat,
                $marketSku,
                $cardStatus ? mb_substr($cardStatus, 0, 50) : null,
                $photoUrl ? mb_substr($photoUrl, 0, 500) : null
            ]);

            error_log("[YMProductCache::saveOffer] SAVED: {$offerId}");

        } catch (Exception $e) {
            error_log("[YMProductCache::saveOffer] SQL ERROR for {$offerId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получение товаров из кэша с пагинацией и поиском
     * @param string|null $search Поисковый запрос
     * @param int $limit Лимит записей (0 = без лимита)
     * @param int $offset Смещение
     */
    public function getCachedProducts(?string $search = null, int $limit = 0, int $offset = 0): array
    {
        error_log("[YMProductCache] getCachedProducts: search=" . ($search ?? 'null') . ", limit={$limit}, offset={$offset}, user_id={$this->userId}");

        $sql = "
            SELECT
                ypc.*,
                CASE WHEN pm.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped,
                pm.id as mapping_id,
                pm.product_id,
                pm.quantity_in_pack,
                pm.pieces_per_sheet,
                p.name as our_product_name,
                p.cost_price as our_cost_price,
                p.sku as our_sku
            FROM ym_products_cache ypc
            LEFT JOIN product_mappings pm
                ON pm.marketplace_sku = ypc.offer_id
                AND pm.marketplace = 'yandex'
                AND pm.is_active = 1
                AND pm.user_id = ?
            LEFT JOIN products p ON p.id = pm.product_id
            WHERE ypc.user_id = ?
        ";
        $params = [$this->userId, $this->userId];

        if ($search) {
            $sql .= " AND (
                ypc.name LIKE ?
                OR ypc.offer_id LIKE ?
                OR ypc.shop_sku LIKE ?
                OR ypc.barcode LIKE ?
            )";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY ypc.name ASC";

        // limit=0 означает без лимита (загрузить все)
        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        error_log("[YMProductCache] SQL query prepared, executing...");

        try {
            $result = $this->db->fetchAll($sql, $params);
            error_log("[YMProductCache] Query returned " . count($result) . " rows");
            return $result;
        } catch (Exception $e) {
            error_log("[YMProductCache] SQL ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получение товара по offer_id
     */
    public function getByOfferId(string $offerId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM ym_products_cache WHERE user_id = ? AND offer_id = ?",
            [$this->userId, $offerId]
        );
    }

    /**
     * Подсчёт товаров
     */
    public function countProducts(?string $search = null): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM ym_products_cache WHERE user_id = ?";
        $params = [$this->userId];

        if ($search) {
            $sql .= " AND (name LIKE ? OR offer_id LIKE ? OR barcode LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Получение статистики
     */
    public function getStats(): array
    {
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM ym_products_cache WHERE user_id = ?",
            [$this->userId]
        );

        $mapped = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT pm.marketplace_sku) as cnt
             FROM product_mappings pm
             WHERE pm.marketplace = 'yandex' AND pm.is_active = 1",
            []
        );

        $lastSync = $this->db->fetchOne(
            "SELECT MAX(updated_at) as last_sync FROM ym_products_cache WHERE user_id = ?",
            [$this->userId]
        );

        return [
            'total_products' => (int)($total['cnt'] ?? 0),
            'mapped_count' => (int)($mapped['cnt'] ?? 0),
            'last_sync' => $lastSync['last_sync'] ?? null
        ];
    }

    /**
     * Получение всех сопоставлений ЯМ
     */
    public function getAllMappings(): array
    {
        error_log("[YMProductCache] getAllMappings() called for user_id={$this->userId}");

        $result = $this->db->fetchAll("
            SELECT
                pm.*,
                ypc.offer_id,
                ypc.shop_sku,
                ypc.name as ym_name,
                ypc.barcode,
                ypc.price as ym_price,
                ypc.old_price as ym_old_price,
                ypc.category_name,
                ypc.vendor,
                p.name as product_name,
                p.cost_price,
                p.sku as product_sku
            FROM product_mappings pm
            INNER JOIN ym_products_cache ypc ON ypc.offer_id = pm.marketplace_sku AND ypc.user_id = ?
            INNER JOIN products p ON p.id = pm.product_id
            WHERE pm.marketplace = 'yandex'
              AND pm.is_active = 1
              AND pm.user_id = ?
            ORDER BY p.name, ypc.name
        ", [$this->userId, $this->userId]);

        error_log("[YMProductCache] getAllMappings() returned " . count($result) . " mappings");

        return $result;
    }

    /**
     * Получение сопоставленных товаров с данными для калькулятора
     */
    public function getMappedProducts(): array
    {
        try {
            error_log("[YMProductCache] getMappedProducts() called");

            // Используем только гарантированно существующие колонки
            $result = $this->db->fetchAll("
                SELECT
                    p.id as product_id,
                    p.name as product_name,
                    p.sku as product_sku,
                    p.cost_price,
                    p.category,
                    COUNT(pm.id) as articles_count
                FROM products p
                INNER JOIN product_mappings pm ON pm.product_id = p.id
                    AND pm.marketplace = 'yandex'
                    AND pm.is_active = 1
                WHERE p.is_active = 1
                GROUP BY p.id, p.name, p.sku, p.cost_price, p.category
                ORDER BY p.name
            ", []);

            error_log("[YMProductCache] getMappedProducts() returned " . count($result) . " products");

            return $result;

        } catch (Exception $e) {
            error_log("[YMProductCache] getMappedProducts() ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получение артикулов по товару для калькулятора
     */
    public function getProductArticles(int $productId): array
    {
        return $this->db->fetchAll("
            SELECT
                pm.id as mapping_id,
                pm.marketplace_sku as offer_id,
                pm.marketplace_name as name,
                pm.quantity_in_pack,
                pm.pieces_per_sheet,
                pm.custom_discount,
                pm.cost_price as mapping_cost_price,
                pm.is_active,
                ypc.shop_sku,
                ypc.barcode,
                ypc.price as current_price,
                ypc.old_price,
                ypc.stock,
                ypc.vendor
            FROM product_mappings pm
            LEFT JOIN ym_products_cache ypc ON ypc.offer_id = pm.marketplace_sku AND ypc.user_id = ?
            WHERE pm.product_id = ?
              AND pm.marketplace = 'yandex'
              AND pm.user_id = ?
            ORDER BY pm.marketplace_name
        ", [$this->userId, $productId, $this->userId]);
    }

    /**
     * Создание сопоставления
     */
    public function createMapping(int $productId, string $offerId, array $params = []): array
    {
        error_log("[YMProductCache] createMapping called: productId={$productId}, offerId={$offerId}");

        try {
            // Получаем данные товара ЯМ
            $ymProduct = $this->getByOfferId($offerId);
            error_log("[YMProductCache] ymProduct found: " . ($ymProduct ? 'YES' : 'NO'));

            if (!$ymProduct) {
                return ['success' => false, 'error' => 'Товар ЯМ не найден в кэше. Выполните синхронизацию.'];
            }

            // Проверяем существующий маппинг
            $existing = $this->db->fetchOne(
                "SELECT id FROM product_mappings
                WHERE marketplace = 'yandex' AND marketplace_sku = ?",
                [$offerId]
            );

            error_log("[YMProductCache] existing mapping: " . ($existing ? 'ID=' . $existing['id'] : 'NONE'));

            if ($existing) {
                return ['success' => false, 'error' => 'Этот товар ЯМ уже сопоставлен'];
            }

            // Создаём маппинг
            error_log("[YMProductCache] Inserting mapping: user_id={$this->userId}, product_id={$productId}, marketplace_sku={$offerId}");

            $this->db->execute("
                INSERT INTO product_mappings
                    (user_id, product_id, marketplace, marketplace_sku, marketplace_product_id,
                     marketplace_name, quantity_in_pack, pieces_per_sheet, is_active)
                VALUES
                    (?, ?, 'yandex', ?, ?, ?, ?, ?, 1)
            ", [
                $this->userId,
                $productId,
                $offerId,
                $offerId,
                $ymProduct['name'],
                $params['quantity_in_pack'] ?? 1,
                $params['pieces_per_sheet'] ?? 1
            ]);

            error_log("[YMProductCache] INSERT completed successfully");

            return ['success' => true, 'message' => 'Сопоставление создано'];

        } catch (Exception $e) {
            error_log("[YMProductCache] createMapping error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Удаление сопоставления
     */
    public function deleteMapping(int $mappingId): array
    {
        try {
            $this->db->execute(
                "DELETE FROM product_mappings WHERE id = ? AND marketplace = 'yandex' AND user_id = ?",
                [$mappingId, $this->userId]
            );
            return ['success' => true, 'message' => 'Сопоставление удалено'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Обновление параметров упаковки
     */
    public function updateMappingPack(int $mappingId, int $quantityInPack, int $piecesPerSheet): array
    {
        try {
            $this->db->execute("
                UPDATE product_mappings
                SET quantity_in_pack = ?, pieces_per_sheet = ?, updated_at = NOW()
                WHERE id = ? AND marketplace = 'yandex' AND user_id = ?
            ", [
                max(1, min(10000, $quantityInPack)),
                max(1, min(10000, $piecesPerSheet)),
                $mappingId,
                $this->userId
            ]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Обновление индивидуальной скидки
     */
    public function updateMappingDiscount(int $mappingId, float $discount): array
    {
        try {
            $this->db->execute("
                UPDATE product_mappings
                SET custom_discount = ?, updated_at = NOW()
                WHERE id = ? AND marketplace = 'yandex' AND user_id = ?
            ", [
                max(0, min(99, $discount)),
                $mappingId,
                $this->userId
            ]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Массовое обновление скидки для товара
     */
    public function bulkUpdateDiscount(int $productId, float $discount): array
    {
        try {
            $result = $this->db->execute("
                UPDATE product_mappings
                SET custom_discount = ?, updated_at = NOW()
                WHERE product_id = ? AND marketplace = 'yandex' AND user_id = ?
            ", [
                max(0, min(99, $discount)),
                $productId,
                $this->userId
            ]);

            return ['success' => true, 'updated' => $result->rowCount()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Парсинг артикула для определения pieces_per_sheet и quantity_in_pack
     */
    public function parseArticle(string $offerId, string $name = ''): array
    {
        $piecesPerSheet = 1;
        $quantityInPack = 1;

        $text = $offerId . ' ' . $name;

        // Ищем размер (например, 760x760, 1520x760)
        if (preg_match('/(\d+)[xхХ×](\d+)/iu', $text, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];

            // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
            if ($width >= 50 && $height >= 50 && $width <= 10000 && $height <= 10000) {
                // Базовый лист 1520x1520
                $baseSize = 1520;

                // Считаем сколько кусочков помещается
                $piecesWidth = floor($baseSize / $width);
                $piecesHeight = floor($baseSize / $height);
                // Максимум 10000 кусочков (защита от переполнения)
                $piecesPerSheet = min(10000, max(1, $piecesWidth * $piecesHeight));
            }
        }

        // Ищем количество в упаковке (например, 5шт, 10шт)
        if (preg_match('/(\d+)\s*(шт|штук|листов|лист)/iu', $text, $matches)) {
            $quantityInPack = max(1, min(10000, (int)$matches[1]));
        }

        return [
            'pieces_per_sheet' => $piecesPerSheet,
            'quantity_in_pack' => $quantityInPack
        ];
    }

    /**
     * Автозаполнение pieces_per_sheet для всех артикулов товара
     */
    public function autoFillPiecesPerSheet(int $productId, int $baseWidth = 1520, int $baseHeight = 1520): int
    {
        $articles = $this->getProductArticles($productId);
        $updated = 0;

        foreach ($articles as $article) {
            $parsed = $this->parseArticle($article['offer_id'], $article['name'] ?? '');

            if ($parsed['pieces_per_sheet'] > 1 || $parsed['quantity_in_pack'] > 1) {
                $this->db->execute("
                    UPDATE product_mappings
                    SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $parsed['pieces_per_sheet'],
                    $parsed['quantity_in_pack'],
                    $article['mapping_id']
                ]);
                $updated++;
            }
        }

        return $updated;
    }
}
