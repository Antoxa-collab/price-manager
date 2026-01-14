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
                pm.id as mapping_id,
                ypc.offer_id,
                ypc.shop_sku,
                ypc.name as ym_name,
                ypc.barcode,
                ypc.price as ym_price,
                ypc.old_price as ym_old_price,
                ypc.category_name,
                ypc.vendor,
                p.name as product_name,
                p.name as name,
                p.cost_price,
                p.sku as product_sku,
                p.sku as sku
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
     * Использует улучшенный алгоритм с таблицей известных раскладок и комбинированной раскладкой
     */
    public function parseArticle(string $offerId, string $name = '', int $baseWidth = 1520, int $baseHeight = 1520): array
    {
        $piecesPerSheet = 1;
        $quantityInPack = 1;

        $text = $offerId . ' ' . $name;
        error_log("[YM parseArticle] INPUT: offerId='{$offerId}', name='{$name}'");

        // Ищем размер (например, 760x760, 1520x760)
        if (preg_match('/(\d+)[xхХ×](\d+)/iu', $text, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];
            error_log("[YM parseArticle] Parsed dimensions: {$width}x{$height}");

            // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
            if ($width >= 50 && $height >= 50 && $width <= 10000 && $height <= 10000) {
                // Используем улучшенный статический метод с комбинированной раскладкой
                $piecesPerSheet = self::calculatePiecesPerSheet($baseWidth, $baseHeight, $width, $height);
                error_log("[YM parseArticle] calculatePiecesPerSheet({$baseWidth}, {$baseHeight}, {$width}, {$height}) = {$piecesPerSheet}");
            }
        } else {
            error_log("[YM parseArticle] No dimensions found in: '{$text}'");
        }

        // Ищем количество в упаковке (например, 5шт, 10шт)
        if (preg_match('/(\d+)\s*(шт|штук|листов|лист)/iu', $text, $matches)) {
            $quantityInPack = max(1, min(10000, (int)$matches[1]));
        }

        error_log("[YM parseArticle] RESULT: pieces_per_sheet={$piecesPerSheet}, quantity_in_pack={$quantityInPack}");

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
        error_log("[YM autoFillPiecesPerSheet] START: productId={$productId}, baseSheet={$baseWidth}x{$baseHeight}");

        $articles = $this->getProductArticles($productId);
        error_log("[YM autoFillPiecesPerSheet] Found " . count($articles) . " articles");

        $updated = 0;

        foreach ($articles as $article) {
            $offerId = $article['offer_id'] ?? '';
            $name = $article['name'] ?? '';
            $mappingId = $article['mapping_id'] ?? 0;

            error_log("[YM autoFillPiecesPerSheet] Processing: mapping_id={$mappingId}, offer_id='{$offerId}'");

            $parsed = $this->parseArticle($offerId, $name, $baseWidth, $baseHeight);

            error_log("[YM autoFillPiecesPerSheet] Parsed result: pps={$parsed['pieces_per_sheet']}, qty={$parsed['quantity_in_pack']}");

            // Всегда обновляем, если есть размеры (не только если > 1)
            if ($parsed['pieces_per_sheet'] >= 1) {
                $this->db->execute("
                    UPDATE product_mappings
                    SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $parsed['pieces_per_sheet'],
                    $parsed['quantity_in_pack'],
                    $mappingId
                ]);
                error_log("[YM autoFillPiecesPerSheet] UPDATED mapping_id={$mappingId}: pps={$parsed['pieces_per_sheet']}, qty={$parsed['quantity_in_pack']}");
                $updated++;
            }
        }

        error_log("[YM autoFillPiecesPerSheet] DONE: updated={$updated}");
        return $updated;
    }

    /**
     * Статический метод парсинга артикула (для использования в endpoints)
     * Аналог WBProductCache::parseArticleName()
     */
    public static function parseArticleName(string $article, string $name, int $baseWidth = 1520, int $baseHeight = 1520): array
    {
        $result = [
            'width' => 0,
            'height' => 0,
            'quantity' => 1,
            'pieces_per_sheet' => 1,
            'format' => null
        ];

        // Формат "TхWхHмм" (толщина×ширина×высота)
        if (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $name, $matches)) {
            $result['width'] = (int)$matches[2];
            $result['height'] = (int)$matches[3];
        } elseif (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $article, $matches)) {
            $result['width'] = (int)$matches[2];
            $result['height'] = (int)$matches[3];
        }

        // Формат "76х76 см" (сантиметры)
        if ($result['width'] === 0 && preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $name, $matches)) {
            $result['width'] = (int)$matches[1] * 10;
            $result['height'] = (int)$matches[2] * 10;
        } elseif ($result['width'] === 0 && preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $article, $matches)) {
            $result['width'] = (int)$matches[1] * 10;
            $result['height'] = (int)$matches[2] * 10;
        }

        // Формат WxH (миллиметры)
        if ($result['width'] === 0 && preg_match('/(\d+)\s*[xхХ×]\s*(\d+)/u', $article, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        } elseif ($result['width'] === 0 && preg_match('/(\d+)\s*[xхХ×]\s*(\d+)/u', $name, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }

        // Формат бумаги (A2, A3, A4, A5, A6)
        if ($result['width'] === 0) {
            $formats = [
                2 => ['width' => 420, 'height' => 594],
                3 => ['width' => 297, 'height' => 420],
                4 => ['width' => 210, 'height' => 297],
                5 => ['width' => 148, 'height' => 210],
                6 => ['width' => 148, 'height' => 105],
            ];

            if (preg_match('/[AА](\d)/ui', $article, $matches)) {
                $formatNum = (int)$matches[1];
                if (isset($formats[$formatNum])) {
                    $result['width'] = $formats[$formatNum]['width'];
                    $result['height'] = $formats[$formatNum]['height'];
                    $result['format'] = 'A' . $formatNum;
                }
            } elseif (preg_match('/[AА](\d)/ui', $name, $matches)) {
                $formatNum = (int)$matches[1];
                if (isset($formats[$formatNum])) {
                    $result['width'] = $formats[$formatNum]['width'];
                    $result['height'] = $formats[$formatNum]['height'];
                    $result['format'] = 'A' . $formatNum;
                }
            }
        }

        // Количество: 5шт, 5 шт, 10 листов
        if (preg_match('/[_\-\s]?(\d+)\s*(шт|штук|листов|лист)/ui', $article, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        } elseif (preg_match('/(\d+)\s*(шт|штук|листов|лист)/ui', $name, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        }

        // Вычисляем pieces_per_sheet
        if ($result['width'] >= 50 && $result['height'] >= 50) {
            $result['pieces_per_sheet'] = self::calculatePiecesPerSheet(
                $baseWidth, $baseHeight,
                $result['width'], $result['height']
            );
        }

        return $result;
    }

    /**
     * Таблица известных оптимальных раскроев для листа 1520×1520
     * Ключ: "pieceW×pieceH", значение: количество штук
     */
    private static array $knownLayouts1520 = [
        '600x900' => 4,   // 2 прямо + 2 повёрнуто
        '900x600' => 4,
        '1000x500' => 4,  // 3 горизонтально + 1 в остаток
        '500x1000' => 4,
        '760x760' => 4,   // 2×2
        '380x760' => 8,   // 4×2 или 2×4
        '760x380' => 8,
        '506x760' => 6,   // 2×3 или 3×2
        '760x506' => 6,
        '500x750' => 6,   // 3×2
        '750x500' => 6,
        '400x600' => 9,   // 3×3
        '600x400' => 9,
    ];

    /**
     * Расчёт количества кусочков из листа
     * Учитывает комбинированную раскладку (часть деталей прямо, часть повёрнуто)
     */
    public static function calculatePiecesPerSheet(int $sheetWidth, int $sheetHeight, int $pieceWidth, int $pieceHeight): int
    {
        error_log("[YM calculatePiecesPerSheet] INPUT: sheet={$sheetWidth}x{$sheetHeight}, piece={$pieceWidth}x{$pieceHeight}");

        if ($pieceWidth <= 0 || $pieceHeight <= 0) {
            error_log("[YM calculatePiecesPerSheet] Invalid piece dimensions, return 1");
            return 1;
        }

        // 1. Проверяем таблицу известных значений для листа 1520×1520
        if ($sheetWidth === 1520 && $sheetHeight === 1520) {
            $key1 = "{$pieceWidth}x{$pieceHeight}";
            $key2 = "{$pieceHeight}x{$pieceWidth}";
            error_log("[YM calculatePiecesPerSheet] Checking knownLayouts: key1={$key1}, key2={$key2}");

            if (isset(self::$knownLayouts1520[$key1])) {
                $val = self::$knownLayouts1520[$key1];
                error_log("[YM calculatePiecesPerSheet] FOUND in table: {$key1} = {$val}");
                return $val;
            }
            if (isset(self::$knownLayouts1520[$key2])) {
                $val = self::$knownLayouts1520[$key2];
                error_log("[YM calculatePiecesPerSheet] FOUND in table: {$key2} = {$val}");
                return $val;
            }
            error_log("[YM calculatePiecesPerSheet] NOT found in knownLayouts table");
        }

        // 2. Вариант 1: Все детали в одном направлении
        $variant1 = (int)(floor($sheetWidth / $pieceWidth) * floor($sheetHeight / $pieceHeight));

        // 3. Вариант 2: Все детали повёрнуты на 90°
        $variant2 = (int)(floor($sheetWidth / $pieceHeight) * floor($sheetHeight / $pieceWidth));

        // 4. Вариант 3: Комбинированная раскладка (основной + в остаток по ширине)
        $variant3 = self::calculateCombinedLayout($sheetWidth, $sheetHeight, $pieceWidth, $pieceHeight);

        // 5. Вариант 4: Комбинированная с поворотом основных деталей
        $variant4 = self::calculateCombinedLayout($sheetWidth, $sheetHeight, $pieceHeight, $pieceWidth);

        $result = max($variant1, $variant2, $variant3, $variant4);
        error_log("[YM calculatePiecesPerSheet] Variants: v1={$variant1}, v2={$variant2}, v3={$variant3}, v4={$variant4} => result={$result}");

        return max(1, min(10000, $result));
    }

    /**
     * Расчёт комбинированной раскладки
     * Основные детали размещаются в одном направлении, в остаток — повёрнутые
     */
    private static function calculateCombinedLayout(int $sheetW, int $sheetH, int $pieceW, int $pieceH): int
    {
        $total = 0;

        // Основная раскладка
        $cols = (int)floor($sheetW / $pieceW);
        $rows = (int)floor($sheetH / $pieceH);
        $total += $cols * $rows;

        // Остаток по ширине (справа от основной раскладки)
        $remainW = $sheetW - ($cols * $pieceW);
        if ($remainW >= $pieceH && $pieceW > 0) {
            // В остаток по ширине можно положить повёрнутые детали
            $extraCols = (int)floor($remainW / $pieceH);
            $extraRows = (int)floor($sheetH / $pieceW);
            $total += $extraCols * $extraRows;
        }

        // Остаток по высоте (снизу от основной раскладки)
        $remainH = $sheetH - ($rows * $pieceH);
        $usedWidth = $cols * $pieceW; // Ширина, занятая основной раскладкой
        if ($remainH >= $pieceW && $pieceH > 0) {
            // В остаток по высоте можно положить повёрнутые детали
            // Но только в пределах ширины основной раскладки (чтобы не пересекаться с остатком по ширине)
            $extraCols = (int)floor($usedWidth / $pieceH);
            $extraRows = (int)floor($remainH / $pieceW);
            $total += $extraCols * $extraRows;
        }

        return $total;
    }

    /**
     * Статический метод парсинга размеров (для определения КГТ)
     * Аналог WBProductCache::parseDimensions()
     */
    public static function parseDimensions(string $offerId, ?string $name = null): array
    {
        $dimensions = self::extractDimensionsFromText($offerId);

        if (($dimensions['width'] === 0 || $dimensions['height'] === 0) && $name) {
            $nameDimensions = self::extractDimensionsFromText($name);
            if ($dimensions['width'] === 0 && $nameDimensions['width'] > 0) {
                $dimensions['width'] = $nameDimensions['width'];
            }
            if ($dimensions['height'] === 0 && $nameDimensions['height'] > 0) {
                $dimensions['height'] = $nameDimensions['height'];
            }
            if ($dimensions['thickness'] === 0 && $nameDimensions['thickness'] > 0) {
                $dimensions['thickness'] = $nameDimensions['thickness'];
            }
        }

        $dimensions['max_dimension'] = max($dimensions['width'], $dimensions['height'], $dimensions['thickness']);
        $dimensions['is_oversized'] = ($dimensions['width'] > 1200 || $dimensions['height'] > 1200 || $dimensions['thickness'] > 1200);

        return $dimensions;
    }

    /**
     * Извлечь размеры из текста
     */
    private static function extractDimensionsFromText(string $text): array
    {
        $width = 0;
        $height = 0;
        $thickness = 0;

        // Формат "3х500х500мм" (толщина×ширина×высота)
        if (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $text, $matches)) {
            return [
                'width' => (int)$matches[2],
                'height' => (int)$matches[3],
                'thickness' => (int)$matches[1]
            ];
        }

        // Формат "76х76 см" (сантиметры)
        if (preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $text, $matches)) {
            return [
                'width' => (int)$matches[1] * 10,
                'height' => (int)$matches[2] * 10,
                'thickness' => 0
            ];
        }

        // Формат WxH (миллиметры)
        if (preg_match('/(\d+)\s*[xхХ×]\s*(\d+)/u', $text, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];
        }

        return [
            'width' => $width,
            'height' => $height,
            'thickness' => $thickness
        ];
    }

    /**
     * Сохранить настройки артикулов (цена, остатки, раскрой)
     * @param int $productId ID товара
     * @param array $markups Массив настроек [{mapping_id, pieces_per_sheet, price, stock}, ...]
     * @return int Количество сохранённых записей
     */
    public function saveArticleSettings(int $productId, array $markups): int
    {
        error_log("[YM saveArticleSettings] START: productId={$productId}, markups=" . count($markups));

        // Проверяем наличие полей cached_price и cached_stock
        $hasCachedFields = $this->checkCachedFieldsExist();
        error_log("[YM saveArticleSettings] hasCachedFields=" . ($hasCachedFields ? 'YES' : 'NO'));

        $saved = 0;

        foreach ($markups as $item) {
            $mappingId = (int)($item['mapping_id'] ?? 0);
            if (!$mappingId) continue;

            $piecesPerSheet = isset($item['pieces_per_sheet']) && $item['pieces_per_sheet'] !== null
                ? (int)$item['pieces_per_sheet'] : null;
            $price = isset($item['price']) && $item['price'] !== null
                ? (float)$item['price'] : null;
            $stock = isset($item['stock']) && $item['stock'] !== null
                ? (int)$item['stock'] : null;

            error_log("[YM saveArticleSettings] Item: mapping_id={$mappingId}, pps={$piecesPerSheet}, price={$price}, stock={$stock}");

            // Формируем SET часть динамически
            $sets = [];
            $params = [];

            if ($piecesPerSheet !== null && $piecesPerSheet > 0) {
                $sets[] = 'pieces_per_sheet = ?';
                $params[] = max(1, min(10000, $piecesPerSheet));
            }

            // Сохраняем цену и остатки только если поля существуют
            if ($hasCachedFields) {
                if ($price !== null && $price > 0) {
                    $sets[] = 'cached_price = ?';
                    $params[] = max(0, $price);
                }

                if ($stock !== null) {
                    $sets[] = 'cached_stock = ?';
                    $params[] = max(0, $stock);
                }
            }

            if (empty($sets)) {
                error_log("[YM saveArticleSettings] Skip mapping_id={$mappingId} - no fields to update");
                continue;
            }

            $sets[] = 'updated_at = NOW()';
            $params[] = $mappingId;
            $params[] = $productId;
            $params[] = $this->userId;

            $sql = "UPDATE product_mappings SET " . implode(', ', $sets) .
                   " WHERE id = ? AND product_id = ? AND user_id = ?";

            error_log("[YM saveArticleSettings] SQL: {$sql}, params=" . json_encode($params));

            try {
                $this->db->execute($sql, $params);
                $saved++;
                error_log("[YM saveArticleSettings] SUCCESS: mapping_id={$mappingId}");
            } catch (Exception $e) {
                error_log("[YM saveArticleSettings] ERROR: mapping_id={$mappingId}, " . $e->getMessage());
            }
        }

        error_log("[YM saveArticleSettings] DONE: saved={$saved}");
        return $saved;
    }

    /**
     * Проверить наличие полей cached_price и cached_stock в таблице
     */
    private function checkCachedFieldsExist(): bool
    {
        try {
            $result = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'product_mappings'
                 AND COLUMN_NAME = 'cached_price'"
            );
            return ($result['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            error_log("[YM checkCachedFieldsExist] Error: " . $e->getMessage());
            return false;
        }
    }
}
