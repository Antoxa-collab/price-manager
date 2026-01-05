<?php
/**
 * Кэш товаров Wildberries с локальной БД
 */
class WBProductCache
{
    private Database $db;
    private int $userId;
    private WildberriesAPI $api;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->db = Database::getInstance();
        $this->api = new WildberriesAPI($userId);
        $this->ensureTables();
    }

    /**
     * Создать необходимые таблицы
     */
    private function ensureTables(): void
    {
        $pdo = $this->db->getConnection();

        // Кэш товаров WB
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wb_products_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                nm_id BIGINT NOT NULL,
                imt_id BIGINT,
                vendor_code VARCHAR(100),
                subject_name VARCHAR(255),
                brand VARCHAR(255),
                title VARCHAR(500),
                description TEXT,
                photo_url VARCHAR(500),
                price INT DEFAULT 0,
                discount INT DEFAULT 0,
                final_price INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_product (user_id, nm_id),
                INDEX idx_vendor_code (vendor_code),
                INDEX idx_subject (subject_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Размеры/вариации
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wb_product_sizes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                nm_id BIGINT NOT NULL,
                chrt_id BIGINT NOT NULL,
                tech_size VARCHAR(50),
                sku VARCHAR(100),
                price INT DEFAULT 0,
                stock INT DEFAULT 0,
                UNIQUE KEY unique_size (user_id, nm_id, chrt_id),
                INDEX idx_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Единая таблица сопоставлений (product_mappings) создаётся в schema.sql
        // Колонки pieces_per_sheet и cost_price добавлены в миграциях
    }

    /**
     * Синхронизировать все товары с WB
     */
    public function syncAllProducts(): array
    {
        $result = $this->api->getAllCards();

        if (!$result['success']) {
            return $result;
        }

        $synced = 0;
        $errors = [];

        foreach ($result['cards'] as $card) {
            try {
                $this->saveCard($card);
                $synced++;
            } catch (Exception $e) {
                $errors[] = "NM {$card['nmID']}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'synced' => $synced,
            'total' => count($result['cards']),
            'errors' => $errors
        ];
    }

    /**
     * Сохранить карточку товара в кэш
     */
    private function saveCard(array $card): void
    {
        $nmId = $card['nmID'] ?? 0;
        if (!$nmId) {
            return;
        }

        $imtId = $card['imtID'] ?? null;
        $vendorCode = $card['vendorCode'] ?? '';
        $subjectName = $card['subjectName'] ?? '';
        $brand = $card['brand'] ?? '';
        $title = $card['title'] ?? $vendorCode;
        $description = $card['description'] ?? '';
        $photo = '';

        // Получаем фото
        if (!empty($card['photos'])) {
            $photo = $card['photos'][0]['big'] ?? $card['photos'][0]['c246x328'] ?? '';
        }

        // Основная карточка
        $this->db->execute("
            INSERT INTO wb_products_cache
            (user_id, nm_id, imt_id, vendor_code, subject_name, brand, title, description, photo_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                imt_id = VALUES(imt_id),
                vendor_code = VALUES(vendor_code),
                subject_name = VALUES(subject_name),
                brand = VALUES(brand),
                title = VALUES(title),
                description = VALUES(description),
                photo_url = VALUES(photo_url),
                updated_at = NOW()
        ", [
            $this->userId, $nmId, $imtId, $vendorCode,
            $subjectName, $brand, $title, $description, $photo
        ]);

        // Размеры/вариации
        if (!empty($card['sizes'])) {
            foreach ($card['sizes'] as $size) {
                $chrtId = $size['chrtID'] ?? 0;
                $techSize = $size['techSize'] ?? '';

                foreach ($size['skus'] ?? [] as $sku) {
                    $this->db->execute("
                        INSERT INTO wb_product_sizes
                        (user_id, nm_id, chrt_id, tech_size, sku)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            tech_size = VALUES(tech_size),
                            sku = VALUES(sku)
                    ", [
                        $this->userId, $nmId, $chrtId, $techSize, $sku
                    ]);
                }
            }
        }
    }

    /**
     * Синхронизировать цены с WB
     */
    public function syncPrices(): array
    {
        $result = $this->api->getAllPrices();

        if (!$result['success']) {
            return $result;
        }

        $updated = 0;
        foreach ($result['goods'] as $good) {
            $nmId = $good['nmID'] ?? 0;
            if (!$nmId) continue;

            $price = (int)($good['sizes'][0]['price'] ?? 0);
            $discount = (int)($good['discount'] ?? 0);
            $finalPrice = (int)($good['sizes'][0]['discountedPrice'] ?? $price);

            $this->db->execute("
                UPDATE wb_products_cache
                SET price = ?, discount = ?, final_price = ?
                WHERE user_id = ? AND nm_id = ?
            ", [$price, $discount, $finalPrice, $this->userId, $nmId]);

            $updated++;
        }

        return [
            'success' => true,
            'updated' => $updated
        ];
    }

    /**
     * Получить все товары из кэша с информацией о сопоставлении
     */
    public function getCachedProducts(?string $search = null, int $limit = 100, int $offset = 0): array
    {
        $sql = "
            SELECT wc.*,
                   CASE WHEN pm.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped,
                   p.id as our_product_id,
                   p.name as our_product_name,
                   p.sku as our_product_sku
            FROM wb_products_cache wc
            LEFT JOIN product_mappings pm
                ON CAST(wc.nm_id AS CHAR) COLLATE utf8mb4_unicode_ci = pm.marketplace_product_id
                AND pm.marketplace = 'wildberries'
                AND pm.is_active = 1
            LEFT JOIN products p ON pm.product_id = p.id
            WHERE wc.user_id = ?
        ";
        $params = [$this->userId];

        if ($search) {
            $sql .= " AND (wc.title LIKE ? OR wc.vendor_code LIKE ? OR wc.nm_id LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY wc.updated_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получить товар по nmId
     */
    public function getByNmId(int $nmId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM wb_products_cache WHERE user_id = ? AND nm_id = ?",
            [$this->userId, $nmId]
        );
    }

    /**
     * Получить размеры/SKU для товара
     */
    public function getSizes(int $nmId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM wb_product_sizes WHERE user_id = ? AND nm_id = ? ORDER BY tech_size",
            [$this->userId, $nmId]
        );
    }

    /**
     * Парсинг артикула для определения pieces_per_sheet и quantity_in_pack
     * Пример: "Фанера_1/2_4мм_760x760_5шт" → pieces_per_sheet=4, quantity_in_pack=5
     */
    public function parseArticle(string $vendorCode): array
    {
        $piecesPerSheet = 1;
        $quantityInPack = 1;

        // Ищем размер (например, 760x760, 1520x760)
        if (preg_match('/(\d+)[xхХ×](\d+)/iu', $vendorCode, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];

            // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
            if ($width >= 50 && $height >= 50) {
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
        if (preg_match('/(\d+)\s*шт/iu', $vendorCode, $matches)) {
            $quantityInPack = max(1, (int)$matches[1]);
        }

        return [
            'pieces_per_sheet' => $piecesPerSheet,
            'quantity_in_pack' => $quantityInPack
        ];
    }

    /**
     * Создать/обновить сопоставление товара
     * Использует единую таблицу product_mappings
     */
    public function createMapping(int $productId, int $nmId, ?int $chrtId = null,
                                  int $piecesPerSheet = 1, int $piecesInPack = 1,
                                  float $costPrice = 0): bool
    {
        // Получаем данные товара из кэша для заполнения marketplace_name и marketplace_offer_id
        $wbProduct = $this->getByNmId($nmId);
        $vendorCode = $wbProduct['vendor_code'] ?? null;
        $title = $wbProduct['title'] ?? null;

        // user_id не используем - система однопользовательская, колонки user_id нет в product_mappings
        return $this->db->execute("
            INSERT INTO product_mappings
            (product_id, marketplace, marketplace_product_id, marketplace_sku,
             marketplace_offer_id, marketplace_name, quantity_in_pack, pieces_per_sheet, cost_price, is_active)
            VALUES (?, 'wildberries', ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                marketplace_sku = VALUES(marketplace_sku),
                marketplace_offer_id = VALUES(marketplace_offer_id),
                marketplace_name = VALUES(marketplace_name),
                quantity_in_pack = VALUES(quantity_in_pack),
                pieces_per_sheet = VALUES(pieces_per_sheet),
                cost_price = VALUES(cost_price),
                is_active = 1,
                updated_at = NOW()
        ", [
            $productId,
            (string)$nmId,
            $chrtId ? (string)$chrtId : null,
            $vendorCode,
            $title,
            $piecesInPack,
            $piecesPerSheet,
            $costPrice
        ]);
    }

    /**
     * Удалить сопоставление
     * Использует единую таблицу product_mappings
     */
    public function deleteMapping(int $productId, int $nmId): bool
    {
        // user_id не используем - система однопользовательская
        return $this->db->execute(
            "DELETE FROM product_mappings
             WHERE product_id = ? AND marketplace = 'wildberries' AND marketplace_product_id = ?",
            [$productId, (string)$nmId]
        );
    }

    /**
     * Получить сопоставленные товары с расчётом цен
     * Использует единую таблицу product_mappings
     */
    public function getMappedProducts(int $productId): array
    {
        // user_id не используем в product_mappings - система однопользовательская
        // Но в wb_products_cache user_id есть, поэтому фильтруем по нему
        return $this->db->fetchAll("
            SELECT
                m.id,
                m.product_id,
                CAST(m.marketplace_product_id AS UNSIGNED) as nm_id,
                CAST(m.marketplace_sku AS UNSIGNED) as chrt_id,
                m.pieces_per_sheet,
                m.quantity_in_pack as pieces_in_pack,
                m.cost_price,
                m.created_at,
                p.nm_id as cache_nm_id,
                p.vendor_code,
                p.title,
                p.photo_url,
                p.price as current_price,
                p.discount as current_discount,
                p.final_price
            FROM product_mappings m
            JOIN wb_products_cache p
                ON p.nm_id = CAST(m.marketplace_product_id AS UNSIGNED)
                AND p.user_id = ?
            WHERE m.product_id = ? AND m.marketplace = 'wildberries' AND m.is_active = 1
            ORDER BY p.vendor_code
        ", [$this->userId, $productId]);
    }

    /**
     * Получить все сопоставления пользователя (WB)
     * Использует единую таблицу product_mappings
     */
    public function getAllMappings(): array
    {
        // user_id не используем в product_mappings - система однопользовательская
        // Но в wb_products_cache user_id есть, поэтому фильтруем по нему
        return $this->db->fetchAll("
            SELECT
                m.id,
                m.product_id,
                CAST(m.marketplace_product_id AS UNSIGNED) as nm_id,
                CAST(m.marketplace_sku AS UNSIGNED) as chrt_id,
                m.pieces_per_sheet,
                m.quantity_in_pack as pieces_in_pack,
                m.cost_price,
                m.created_at,
                p.nm_id as cache_nm_id,
                p.vendor_code,
                p.title,
                p.photo_url,
                p.price as current_price,
                p.discount as current_discount,
                p.final_price,
                pr.name as product_name,
                pr.sku as product_article
            FROM product_mappings m
            JOIN wb_products_cache p
                ON p.nm_id = CAST(m.marketplace_product_id AS UNSIGNED)
                AND p.user_id = ?
            LEFT JOIN products pr ON pr.id = m.product_id
            WHERE m.marketplace = 'wildberries' AND m.is_active = 1
            ORDER BY pr.name, p.vendor_code
        ", [$this->userId]);
    }

    /**
     * Поиск товаров для сопоставления
     */
    public function searchForMapping(string $query): array
    {
        $searchTerm = "%{$query}%";
        return $this->db->fetchAll("
            SELECT
                c.*,
                GROUP_CONCAT(DISTINCT s.tech_size ORDER BY s.tech_size SEPARATOR ', ') as sizes
            FROM wb_products_cache c
            LEFT JOIN wb_product_sizes s ON s.nm_id = c.nm_id AND s.user_id = c.user_id
            WHERE c.user_id = ?
              AND (c.title LIKE ? OR c.vendor_code LIKE ? OR c.nm_id LIKE ?)
            GROUP BY c.id
            ORDER BY c.title
            LIMIT 50
        ", [$this->userId, $searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Получить статистику
     * Использует единую таблицу product_mappings
     */
    public function getStats(): array
    {
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt, MAX(updated_at) as last_sync FROM wb_products_cache WHERE user_id = ?",
            [$this->userId]
        );

        // user_id не используем в product_mappings - система однопользовательская
        $mapped = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT marketplace_product_id) as cnt
             FROM product_mappings
             WHERE marketplace = 'wildberries' AND is_active = 1"
        );

        return [
            'total_products' => (int)($total['cnt'] ?? 0),
            'mapped_count' => (int)($mapped['cnt'] ?? 0),
            'last_sync' => $total['last_sync'] ?? null
        ];
    }

    /**
     * Очистить кэш
     */
    public function clearCache(): bool
    {
        $this->db->execute("DELETE FROM wb_product_sizes WHERE user_id = ?", [$this->userId]);
        $this->db->execute("DELETE FROM wb_products_cache WHERE user_id = ?", [$this->userId]);
        return true;
    }
}
