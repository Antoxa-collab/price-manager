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

        // Извлекаем первый баркод из sizes[].skus[] для загрузки остатков
        $barcode = null;
        if (!empty($card['sizes']) && is_array($card['sizes'])) {
            foreach ($card['sizes'] as $size) {
                if (!empty($size['skus']) && is_array($size['skus'])) {
                    $barcode = $size['skus'][0];
                    break; // Берём первый найденный баркод
                }
            }
        }

        // Основная карточка
        $this->db->execute("
            INSERT INTO wb_products_cache
            (user_id, nm_id, imt_id, vendor_code, barcode, subject_name, brand, title, description, photo_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                imt_id = VALUES(imt_id),
                vendor_code = VALUES(vendor_code),
                barcode = VALUES(barcode),
                subject_name = VALUES(subject_name),
                brand = VALUES(brand),
                title = VALUES(title),
                description = VALUES(description),
                photo_url = VALUES(photo_url),
                updated_at = NOW()
        ", [
            $this->userId, $nmId, $imtId, $vendorCode, $barcode,
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
                m.id as mapping_id,
                m.product_id,
                CAST(m.marketplace_product_id AS UNSIGNED) as nm_id,
                CAST(m.marketplace_sku AS UNSIGNED) as chrt_id,
                m.pieces_per_sheet,
                m.quantity_in_pack,
                m.cost_price,
                m.created_at,
                p.nm_id as cache_nm_id,
                p.vendor_code,
                p.barcode,
                p.title as wb_name,
                p.photo_url,
                p.price as wb_price,
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
                m.id as mapping_id,
                m.product_id,
                CAST(m.marketplace_product_id AS UNSIGNED) as nm_id,
                CAST(m.marketplace_sku AS UNSIGNED) as chrt_id,
                m.pieces_per_sheet,
                m.quantity_in_pack,
                m.cost_price,
                m.created_at,
                p.nm_id as cache_nm_id,
                p.vendor_code,
                p.barcode,
                p.title as wb_name,
                p.photo_url,
                p.price as wb_price,
                p.discount as current_discount,
                p.final_price,
                pr.name,
                pr.sku
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

    /**
     * Найти количество кусочков в справочнике раскроя
     *
     * @param int $userId ID пользователя
     * @param int $sheetWidth Ширина листа
     * @param int $sheetHeight Высота листа
     * @param int $pieceWidth Ширина кусочка
     * @param int $pieceHeight Высота кусочка
     * @return int|null actual_qty из справочника или null если не найден
     */
    public function lookupCuttingReference(int $userId, int $sheetWidth, int $sheetHeight, int $pieceWidth, int $pieceHeight): ?int
    {
        error_log("[WB lookupCuttingReference] START: userId=$userId, sheet={$sheetWidth}x{$sheetHeight}, piece={$pieceWidth}x{$pieceHeight}");

        // ВАЖНО: Ищем ВСЕ листы подходящего размера (не только первый!)
        // Разные материалы могут иметь одинаковые размеры листа (2500x1250),
        // но кусочки заданы только в одном из них.
        // Допускаем погрешность в 50мм для размера листа
        // ВАЖНО: Используем CAST AS SIGNED для избежания ошибки MySQL 22003
        $sheets = $this->db->fetchAll(
            "SELECT id, sheet_width, sheet_height, material_name FROM cutting_sheets
             WHERE user_id = ? AND is_active = 1
               AND ABS(CAST(sheet_width AS SIGNED) - CAST(? AS SIGNED)) <= 50
               AND ABS(CAST(sheet_height AS SIGNED) - CAST(? AS SIGNED)) <= 50
             ORDER BY ABS(CAST(sheet_width AS SIGNED) - CAST(? AS SIGNED)) + ABS(CAST(sheet_height AS SIGNED) - CAST(? AS SIGNED)) ASC",
            [$userId, $sheetWidth, $sheetHeight, $sheetWidth, $sheetHeight]
        );

        if (!$sheets || count($sheets) === 0) {
            error_log("[WB lookupCuttingReference] No sheets found for {$sheetWidth}x{$sheetHeight}");
            return null;
        }

        $sheetNames = implode(', ', array_map(fn($s) => "id={$s['id']} {$s['material_name']}", $sheets));
        error_log("[WB lookupCuttingReference] Found " . count($sheets) . " sheets: $sheetNames");

        // Ищем кусочек во ВСЕХ найденных листах
        foreach ($sheets as $sheet) {
            error_log("[WB lookupCuttingReference] Searching piece {$pieceWidth}x{$pieceHeight} in sheet_id={$sheet['id']} ({$sheet['material_name']})");

            // Ищем размер кусочка в справочнике
            // С учётом погрешности ±15мм (для компенсации округлений и разницы в замерах)
            // И с учётом поворота на 90° (WxH или HxW)
            $piece = $this->db->fetchOne(
                "SELECT id, piece_name, piece_width, piece_height, actual_qty, calculated_qty
                 FROM cutting_pieces
                 WHERE sheet_id = ?
                   AND (
                       -- Прямое совпадение с погрешностью ±15мм
                       (ABS(CAST(piece_width AS SIGNED) - CAST(? AS SIGNED)) <= 15
                        AND ABS(CAST(piece_height AS SIGNED) - CAST(? AS SIGNED)) <= 15)
                       OR
                       -- Поворот на 90° с погрешностью ±15мм
                       (ABS(CAST(piece_width AS SIGNED) - CAST(? AS SIGNED)) <= 15
                        AND ABS(CAST(piece_height AS SIGNED) - CAST(? AS SIGNED)) <= 15)
                   )
                 ORDER BY
                   LEAST(
                       ABS(CAST(piece_width AS SIGNED) - CAST(? AS SIGNED)) + ABS(CAST(piece_height AS SIGNED) - CAST(? AS SIGNED)),
                       ABS(CAST(piece_width AS SIGNED) - CAST(? AS SIGNED)) + ABS(CAST(piece_height AS SIGNED) - CAST(? AS SIGNED))
                   ) ASC
                 LIMIT 1",
                [
                    $sheet['id'],
                    $pieceWidth, $pieceHeight,      // прямое совпадение
                    $pieceHeight, $pieceWidth,      // поворот 90°
                    $pieceWidth, $pieceHeight,      // ORDER BY прямое
                    $pieceHeight, $pieceWidth       // ORDER BY поворот
                ]
            );

            if ($piece) {
                error_log("[WB lookupCuttingReference] FOUND in sheet_id={$sheet['id']}: piece_id={$piece['id']}, {$piece['piece_name']} {$piece['piece_width']}x{$piece['piece_height']}, actual_qty={$piece['actual_qty']}");
                return (int)$piece['actual_qty'];
            }

            // Диагностика: какие кусочки есть в этом листе
            $allPieces = $this->db->fetchAll(
                "SELECT piece_name, piece_width, piece_height, actual_qty FROM cutting_pieces WHERE sheet_id = ?",
                [$sheet['id']]
            );
            if ($allPieces) {
                $piecesStr = implode(', ', array_map(fn($p) => "{$p['piece_width']}x{$p['piece_height']}({$p['actual_qty']})", $allPieces));
                error_log("[WB lookupCuttingReference] Pieces in sheet_id={$sheet['id']}: $piecesStr");
            } else {
                error_log("[WB lookupCuttingReference] No pieces in sheet_id={$sheet['id']}");
            }
        }

        error_log("[WB lookupCuttingReference] Piece {$pieceWidth}x{$pieceHeight} NOT FOUND in any of " . count($sheets) . " sheets");
        return null;
    }

    /**
     * Рассчитывает количество кусочков с листа с учётом поворота
     * @param int $sheetWidth Ширина листа (мм)
     * @param int $sheetHeight Высота листа (мм)
     * @param int $pieceWidth Ширина кусочка (мм)
     * @param int $pieceHeight Высота кусочка (мм)
     * @return int Количество кусочков (от 1 до 10000)
     */
    public static function calculatePiecesPerSheet(int $sheetWidth, int $sheetHeight, int $pieceWidth, int $pieceHeight): int
    {
        // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
        if ($pieceWidth < 50 || $pieceHeight < 50) {
            return 1;
        }

        // Вариант 1: стандартная ориентация
        $cols1 = floor($sheetWidth / $pieceWidth);
        $rows1 = floor($sheetHeight / $pieceHeight);
        $total1 = max(1, $cols1) * max(1, $rows1);

        // Вариант 2: повёрнутая ориентация (90°)
        $cols2 = floor($sheetWidth / $pieceHeight);
        $rows2 = floor($sheetHeight / $pieceWidth);
        $total2 = max(1, $cols2) * max(1, $rows2);

        // Возвращаем лучший вариант, но не больше 10000
        return min(10000, max($total1, $total2));
    }

    /**
     * Парсит артикул (vendor_code) и/или название и извлекает размер и количество
     * Примеры артикулов:
     * - "фанера_1/2_4мм_760x768_5шт" → width=760, height=768, quantity=5
     * - "фанера_1/2_4мм_380x380_10шт" → width=380, height=380, quantity=10
     *
     * @param string $article Артикул (vendor_code)
     * @param string $name Название товара
     * @param int $baseWidth Ширина базового листа
     * @param int $baseHeight Высота базового листа
     * @return array ['width', 'height', 'quantity', 'pieces_per_sheet', 'format']
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

        // 0. ПЕРВЫЙ ПРИОРИТЕТ: Формат "TхWхHмм" (толщина×ширина×высота)
        // Пример: "Фанера для лазерной резки 3х500х500мм 20 листов" → thickness=3, width=500, height=500
        // Толщина: 1-2 цифры, размеры: 3+ цифры каждый
        // Сначала в названии (там чаще такой формат)
        if (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $name, $matches)) {
            $result['width'] = (int)$matches[2];   // 500
            $result['height'] = (int)$matches[3];  // 500
            // thickness = (int)$matches[1] = 3 (не используем)
        }
        // Потом в артикуле
        elseif (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $article, $matches)) {
            $result['width'] = (int)$matches[2];
            $result['height'] = (int)$matches[3];
        }

        // 0.5. Формат "76х76 см" (сантиметры) — УМНОЖАЕМ НА 10!
        // Пример: "Фанера для лазерной резки 76х76 см" → 760×760 мм
        // Сначала в названии
        if ($result['width'] === 0 && preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $name, $matches)) {
            $result['width'] = (int)$matches[1] * 10;   // 76 см → 760 мм
            $result['height'] = (int)$matches[2] * 10;  // 76 см → 760 мм
        }
        // Потом в артикуле
        elseif ($result['width'] === 0 && preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $article, $matches)) {
            $result['width'] = (int)$matches[1] * 10;
            $result['height'] = (int)$matches[2] * 10;
        }

        // 1. Ищем размер в формате WxH (например, 760x768, 1520×760) — миллиметры
        // Сначала в артикуле (если ещё не найдено)
        if ($result['width'] === 0 && preg_match('/(\d+)\s*[xхХ×]\s*(\d+)/u', $article, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }
        // Потом в названии
        elseif ($result['width'] === 0 && preg_match('/(\d+)\s*[xхХ×]\s*(\d+)/u', $name, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }

        // 2. Ищем формат бумаги (A2, A3, A4, A5, A6)
        if ($result['width'] === 0) {
            $formats = [
                2 => ['width' => 420, 'height' => 594],
                3 => ['width' => 297, 'height' => 420],
                4 => ['width' => 210, 'height' => 297],
                5 => ['width' => 148, 'height' => 210],
                6 => ['width' => 148, 'height' => 105],  // A6
            ];

            // Сначала в артикуле
            if (preg_match('/[AА](\d)/ui', $article, $matches)) {
                $formatNum = (int)$matches[1];
                if (isset($formats[$formatNum])) {
                    $result['width'] = $formats[$formatNum]['width'];
                    $result['height'] = $formats[$formatNum]['height'];
                    $result['format'] = 'A' . $formatNum;
                }
            }
            // Потом в названии
            elseif (preg_match('/[AА](\d)/ui', $name, $matches)) {
                $formatNum = (int)$matches[1];
                if (isset($formats[$formatNum])) {
                    $result['width'] = $formats[$formatNum]['width'];
                    $result['height'] = $formats[$formatNum]['height'];
                    $result['format'] = 'A' . $formatNum;
                }
            }
        }

        // 3. Ищем количество: 5шт, 5 шт, 5штук, 5 штук, 10 листов
        // Ограничение: min 1, max 10000 (защита от переполнения)
        if (preg_match('/[_\-\s]?(\d+)\s*(шт|штук|листов|лист)/ui', $article, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        }
        elseif (preg_match('/(\d+)\s*(шт|штук|листов|лист)/ui', $name, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        }

        // 4. Вычисляем pieces_per_sheet если нашли размер
        if ($result['width'] >= 50 && $result['height'] >= 50) {
            $result['pieces_per_sheet'] = self::calculatePiecesPerSheet(
                $baseWidth, $baseHeight,
                $result['width'], $result['height']
            );
        }

        return $result;
    }

    /**
     * Проверить, является ли товар КГТ (крупногабаритным) по размерам из артикула
     * КГТ = любая сторона > 1200мм
     *
     * Парсит из артикула:
     * - Размеры (ширина x высота): МДФ_10мм_1350х500_1шт → 1350x500
     * - Толщину: МДФ_10мм_... → 10мм
     *
     * @param string $vendorCode Артикул товара
     * @param string|null $title Название товара (для fallback)
     * @return bool true если товар КГТ
     */
    public static function isOversized(string $vendorCode, ?string $title = null): bool
    {
        $dimensions = self::parseDimensions($vendorCode, $title);
        return $dimensions['is_oversized'];
    }

    /**
     * Получить размеры товара из артикула (с fallback на название)
     *
     * @param string $vendorCode Артикул товара
     * @param string|null $title Название товара (для fallback если размер не найден в артикуле)
     * @return array ['width' => int, 'height' => int, 'thickness' => int, 'max_dimension' => int, 'is_oversized' => bool, 'source' => string]
     */
    public static function parseDimensions(string $vendorCode, ?string $title = null): array
    {
        // 1. Пытаемся найти размер в артикуле
        $dimensions = self::extractDimensionsFromText($vendorCode);
        $source = 'vendor_code';

        // 2. Если не нашли полный размер в артикуле — ищем в названии
        if (($dimensions['width'] === 0 || $dimensions['height'] === 0) && $title) {
            $titleDimensions = self::extractDimensionsFromText($title);

            // Заполняем недостающие данные из названия
            if ($dimensions['width'] === 0 && $titleDimensions['width'] > 0) {
                $dimensions['width'] = $titleDimensions['width'];
                $source = 'title';
            }
            if ($dimensions['height'] === 0 && $titleDimensions['height'] > 0) {
                $dimensions['height'] = $titleDimensions['height'];
                $source = ($source === 'title') ? 'title' : 'mixed';
            }
            // Толщина тоже может быть в названии
            if ($dimensions['thickness'] === 0 && $titleDimensions['thickness'] > 0) {
                $dimensions['thickness'] = $titleDimensions['thickness'];
            }
        }

        $dimensions['max_dimension'] = max($dimensions['width'], $dimensions['height'], $dimensions['thickness']);
        $dimensions['is_oversized'] = ($dimensions['width'] > 1200 || $dimensions['height'] > 1200 || $dimensions['thickness'] > 1200);
        $dimensions['source'] = $source;

        return $dimensions;
    }

    /**
     * Извлечь размеры из текста (артикул или название)
     *
     * @param string $text Текст для парсинга
     * @return array ['width' => int, 'height' => int, 'thickness' => int]
     */
    private static function extractDimensionsFromText(string $text): array
    {
        $width = 0;
        $height = 0;
        $thickness = 0;

        // Формат "3х500х500мм" (толщина×ширина×высота) — ПЕРВЫЙ приоритет
        // Толщина: 1-2 цифры, размеры: 3+ цифры каждый
        // Пример: "Фанера для лазерной резки 3х500х500мм 20 листов"
        if (preg_match('/(\d{1,2})\s*[xхХ×]\s*(\d{3,})\s*[xхХ×]\s*(\d{3,})/u', $text, $matches)) {
            return [
                'width' => (int)$matches[2],      // 500
                'height' => (int)$matches[3],     // 500
                'thickness' => (int)$matches[1]   // 3
            ];
        }

        // Формат "76х76 см" (сантиметры) — УМНОЖАЕМ НА 10 для конвертации в мм!
        // Пример: "Фанера для лазерной резки 76х76 см" → 760×760 мм
        if (preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})\s*см/ui', $text, $matches)) {
            return [
                'width' => (int)$matches[1] * 10,   // 76 см → 760 мм
                'height' => (int)$matches[2] * 10,  // 76 см → 760 мм
                'thickness' => 0
            ];
        }

        // Толщина (Nмм) — ищем в начале, чтобы не путать с размерами
        if (preg_match('/(\d+)\s*мм/ui', $text, $matches)) {
            $thickness = (int)$matches[1];
        }

        // Размеры (ширина x высота) в миллиметрах
        // ВАЖНО: Исключаем формат бумаги А(4)297х210 — там первая цифра это номер формата
        // Ищем паттерн: число от 3 цифр (100+) x число от 2 цифр
        // Это отсекает А(4)297 где "4" — номер формата
        if (preg_match('/(\d{3,})\s*[xхХ×]\s*(\d{2,})/u', $text, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];
        }

        // Если не нашли размеры, проверяем формат бумаги А2, А3, А4, А5, А6
        if ($width === 0 && $height === 0) {
            $formats = [
                2 => ['width' => 594, 'height' => 420],  // A2 (исправлен порядок: ширина > высота)
                3 => ['width' => 420, 'height' => 297],  // A3
                4 => ['width' => 297, 'height' => 210],  // A4
                5 => ['width' => 210, 'height' => 148],  // A5
                6 => ['width' => 148, 'height' => 105],  // A6
            ];

            // Ищем форматы: А2, A2, А(2), А 2, и т.д.
            if (preg_match('/[AА]\s*\(?(\d)\)?/ui', $text, $matches)) {
                $formatNum = (int)$matches[1];
                if (isset($formats[$formatNum])) {
                    $width = $formats[$formatNum]['width'];
                    $height = $formats[$formatNum]['height'];
                }
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'thickness' => $thickness
        ];
    }

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack для маппингов товара
     * Парсит артикул (vendor_code) и название товара на WB
     *
     * ИНТЕГРАЦИЯ СО СПРАВОЧНИКОМ РАСКРОЯ:
     * 1. Парсим размер кусочка из артикула/названия
     * 2. Ищем в справочнике (cutting_sheets + cutting_pieces) количество
     * 3. Если найден — используем actual_qty из справочника
     * 4. Если не найден — используем локальный расчёт
     *
     * @param int $productId ID нашего товара
     * @param int $baseWidth Ширина базового листа
     * @param int $baseHeight Высота базового листа
     * @return int Количество обновлённых маппингов
     */
    public function autoFillPiecesPerSheet(int $productId, int $baseWidth = 1520, int $baseHeight = 1520): int
    {
        error_log("[WB autoFillPiecesPerSheet] START: productId=$productId, baseSheet={$baseWidth}x{$baseHeight}, userId={$this->userId}");

        $updated = 0;

        // Получаем все маппинги для этого товара (WB)
        $mappings = $this->db->fetchAll(
            "SELECT pm.id, pm.marketplace_offer_id, pm.marketplace_name,
                    wc.vendor_code as cache_vendor_code, wc.title as cache_title
             FROM product_mappings pm
             LEFT JOIN wb_products_cache wc
                 ON wc.nm_id = CAST(pm.marketplace_product_id AS UNSIGNED)
                 AND wc.user_id = ?
             WHERE pm.product_id = ? AND pm.marketplace = 'wildberries' AND pm.is_active = 1",
            [$this->userId, $productId]
        );

        error_log("[WB autoFillPiecesPerSheet] Found " . count($mappings) . " mappings for product $productId");

        foreach ($mappings as $mapping) {
            // Собираем артикул (приоритет: из маппинга, потом из кэша)
            $articleText = $mapping['marketplace_offer_id'] ?: $mapping['cache_vendor_code'] ?: '';

            // Собираем название (приоритет: из маппинга, потом из кэша)
            $nameText = $mapping['marketplace_name'] ?: $mapping['cache_title'] ?: '';

            // Если нет ни артикула, ни названия — пропускаем
            if (empty($articleText) && empty($nameText)) {
                error_log("[WB autoFillPiecesPerSheet] Skipping mapping_id={$mapping['id']} - no article or name");
                continue;
            }

            // Парсим артикул И название
            $parsed = self::parseArticleName($articleText, $nameText, $baseWidth, $baseHeight);
            error_log("[WB autoFillPiecesPerSheet] Parsed: article='$articleText' => width={$parsed['width']}, height={$parsed['height']}, calculated_pps={$parsed['pieces_per_sheet']}");

            // Пытаемся найти в справочнике раскроя
            $piecesPerSheet = $parsed['pieces_per_sheet'];
            $fromReference = false;

            if ($parsed['width'] > 0 && $parsed['height'] > 0) {
                error_log("[WB autoFillPiecesPerSheet] Calling lookupCuttingReference: userId={$this->userId}, sheet={$baseWidth}x{$baseHeight}, piece={$parsed['width']}x{$parsed['height']}");
                $referenceLookup = $this->lookupCuttingReference(
                    $this->userId,
                    $baseWidth,
                    $baseHeight,
                    $parsed['width'],
                    $parsed['height']
                );

                error_log("[WB autoFillPiecesPerSheet] lookupCuttingReference returned: " . ($referenceLookup !== null ? $referenceLookup : "NULL"));

                // Валидация значения из справочника (защита от некорректных данных)
                if ($referenceLookup !== null && $referenceLookup > 0 && $referenceLookup <= 10000) {
                    $piecesPerSheet = $referenceLookup;
                    $fromReference = true;
                    error_log("[WB autoFillPiecesPerSheet] Using REFERENCE value: $piecesPerSheet");
                } else {
                    error_log("[WB autoFillPiecesPerSheet] Using CALCULATED value: $piecesPerSheet");
                }
            } else {
                error_log("[WB autoFillPiecesPerSheet] Skipping reference lookup - no valid piece dimensions (width={$parsed['width']}, height={$parsed['height']})");
            }

            // Финальная валидация перед записью в БД (защита от MySQL 22003)
            $piecesPerSheet = max(1, min(10000, (int)$piecesPerSheet));
            $quantity = max(1, min(10000, (int)$parsed['quantity']));

            // Логируем для отладки
            $formatInfo = $parsed['format'] ? " (format={$parsed['format']})" : '';
            $refInfo = $fromReference ? ' [from reference]' : ' [calculated]';
            error_log("WB AutoFill: mapping_id={$mapping['id']}, article='{$articleText}', name='{$nameText}' => pieces_per_sheet={$piecesPerSheet}, qty={$quantity}{$formatInfo}{$refInfo}");

            // Дополнительная проверка типов перед UPDATE
            if (!is_int($piecesPerSheet) || $piecesPerSheet < 1 || $piecesPerSheet > 10000) {
                error_log("WB AutoFill ERROR: invalid piecesPerSheet={$piecesPerSheet} (type=" . gettype($piecesPerSheet) . ")");
                $piecesPerSheet = 1;
            }
            if (!is_int($quantity) || $quantity < 1 || $quantity > 10000) {
                error_log("WB AutoFill ERROR: invalid quantity={$quantity} (type=" . gettype($quantity) . ")");
                $quantity = 1;
            }

            // Обновляем маппинг
            try {
                $this->db->execute(
                    "UPDATE product_mappings
                     SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$piecesPerSheet, $quantity, $mapping['id']]
                );
                $updated++;
            } catch (PDOException $e) {
                error_log("WB AutoFill DB ERROR: mapping_id={$mapping['id']}, pps={$piecesPerSheet}, qty={$quantity}, code={$e->getCode()}, msg={$e->getMessage()}");
                throw $e;
            }
        }

        return $updated;
    }
}
