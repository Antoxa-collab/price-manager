<?php
/**
 * Класс для кэширования товаров с Ozon
 * Хранит локальную копию данных товаров для быстрого доступа
 */
class OzonProductCache
{
    private Database $db;
    private string $marketplace = 'ozon';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Синхронизировать товары с Ozon API
     * @param OzonAPI $api Экземпляр API
     * @return array Результат синхронизации
     */
    public function syncFromApi(OzonAPI $api): array
    {
        $result = [
            'success' => false,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => []
        ];

        try {
            // Проверяем подключение
            if (!$api->isConfigured()) {
                throw new Exception('Ozon API не настроен. Укажите Client-Id и Api-Key в настройках.');
            }

            $testResult = $api->testConnection();
            if (!$testResult['success']) {
                throw new Exception('Ошибка подключения к Ozon: ' . $testResult['message']);
            }

            // Получаем список товаров с Ozon через новый метод
            $apiResult = $api->getAllProducts();
            $products = $apiResult['products'] ?? [];

            if (empty($products)) {
                return [
                    'success' => true,
                    'total' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'message' => 'Товары на Ozon не найдены'
                ];
            }

            $result['total'] = count($products);

            $this->db->beginTransaction();
            try {
                foreach ($products as $product) {
                    try {
                        $saved = $this->saveProduct($product);
                        if ($saved['is_new']) {
                            $result['created']++;
                        } else {
                            $result['updated']++;
                        }
                    } catch (Exception $e) {
                        $result['errors'][] = [
                            'product_id' => $product['product_id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }
                $this->db->commit();
                $result['success'] = true;

                // Логируем успешную синхронизацию
                ErrorLogger::info('Ozon sync completed', [
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'total' => $result['total']
                ]);

            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
            ErrorLogger::error('Ozon sync failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Сохранить товар в кэш
     * @param array $productData Данные товара с Ozon
     * @return array
     */
    public function saveProduct(array $productData): array
    {
        $productId = (string)($productData['product_id'] ?? $productData['id'] ?? '');
        if (empty($productId)) {
            throw new Exception('product_id отсутствует в данных товара');
        }

        // Проверяем существование
        $existing = $this->db->fetchOne(
            "SELECT id FROM marketplace_products_cache WHERE marketplace = ? AND product_id = ?",
            [$this->marketplace, $productId]
        );

        $data = [
            'marketplace' => $this->marketplace,
            'product_id' => $productId,
            'sku' => (string)($productData['sku'] ?? $productData['fbo_sku'] ?? ''),
            'offer_id' => $productData['offer_id'] ?? null,
            'name' => $productData['name'] ?? '',
            'price' => $this->extractPrice($productData, 'price'),
            'min_price' => $this->extractPrice($productData, 'min_price'),
            'old_price' => $this->extractPrice($productData, 'old_price'),
            'stock' => $this->extractStock($productData),
            'is_visible' => isset($productData['visible']) ? ($productData['visible'] ? 1 : 0) : 1,
            'raw_data' => json_encode($productData, JSON_UNESCAPED_UNICODE),
            'synced_at' => date('Y-m-d H:i:s')
        ];

        if ($existing) {
            $this->db->update(
                'marketplace_products_cache',
                $data,
                'id = ?',
                [$existing['id']]
            );
            return ['is_new' => false, 'id' => $existing['id']];
        } else {
            $id = $this->db->insert('marketplace_products_cache', $data);
            return ['is_new' => true, 'id' => $id];
        }
    }

    /**
     * Извлечь цену из данных товара
     * @param array $data Данные товара
     * @param string $type Тип цены (price, min_price, old_price)
     * @return float|null
     */
    private function extractPrice(array $data, string $type): ?float
    {
        // Ozon возвращает цены в разных форматах
        if (isset($data[$type])) {
            return (float)$data[$type];
        }

        // Проверяем вложенный объект price
        if (isset($data['price'][$type])) {
            return (float)$data['price'][$type];
        }

        // Для marketing_price структуры
        if ($type === 'price' && isset($data['marketing_price'])) {
            return (float)$data['marketing_price'];
        }

        if ($type === 'min_price' && isset($data['min_ozon_price'])) {
            return (float)$data['min_ozon_price'];
        }

        if ($type === 'old_price' && isset($data['old_price'])) {
            return (float)$data['old_price'];
        }

        return null;
    }

    /**
     * Извлечь остатки из данных товара
     * @param array $data Данные товара
     * @return int|null
     */
    private function extractStock(array $data): ?int
    {
        if (isset($data['stock'])) {
            return is_array($data['stock'])
                ? (int)($data['stock']['present'] ?? array_sum($data['stock']))
                : (int)$data['stock'];
        }

        if (isset($data['stocks'])) {
            $total = 0;
            foreach ($data['stocks'] as $stock) {
                $total += (int)($stock['present'] ?? $stock['quantity'] ?? 0);
            }
            return $total;
        }

        return null;
    }

    /**
     * Получить все товары из кэша
     * @param array $filters Фильтры
     * @return array
     */
    public function getAll(array $filters = []): array
    {
        $sql = "SELECT mpc.*,
                       CASE WHEN pm.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped,
                       pm.product_id as our_product_id,
                       p.name as our_product_name
                FROM marketplace_products_cache mpc
                LEFT JOIN product_mappings pm
                    ON pm.marketplace = mpc.marketplace
                    AND pm.marketplace_product_id = mpc.product_id
                    AND pm.is_active = 1
                LEFT JOIN products p ON p.id = pm.product_id
                WHERE mpc.marketplace = ?";
        $params = [$this->marketplace];

        // Фильтр по сопоставлению
        if (isset($filters['mapped'])) {
            if ($filters['mapped']) {
                $sql .= " AND pm.id IS NOT NULL";
            } else {
                $sql .= " AND pm.id IS NULL";
            }
        }

        // Поиск
        if (!empty($filters['search'])) {
            $sql .= " AND (mpc.name LIKE ? OR mpc.offer_id LIKE ? OR mpc.sku LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY mpc.name";

        // Лимит
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получить товар по ID маркетплейса
     * @param string $marketplaceProductId
     * @return array|null
     */
    public function getByProductId(string $marketplaceProductId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM marketplace_products_cache
             WHERE marketplace = ? AND product_id = ?",
            [$this->marketplace, $marketplaceProductId]
        );
    }

    /**
     * Получить товар по offer_id (артикул продавца)
     * @param string $offerId
     * @return array|null
     */
    public function getByOfferId(string $offerId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM marketplace_products_cache
             WHERE marketplace = ? AND offer_id = ?",
            [$this->marketplace, $offerId]
        );
    }

    /**
     * Очистить кэш
     * @return int Количество удалённых записей
     */
    public function clearCache(): int
    {
        return $this->db->delete(
            'marketplace_products_cache',
            'marketplace = ?',
            [$this->marketplace]
        );
    }

    /**
     * Получить время последней синхронизации
     * @return string|null
     */
    public function getLastSyncTime(): ?string
    {
        return $this->db->fetchColumn(
            "SELECT MAX(synced_at) FROM marketplace_products_cache WHERE marketplace = ?",
            [$this->marketplace]
        );
    }

    /**
     * Получить количество товаров в кэше
     * @return int
     */
    public function getCount(): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM marketplace_products_cache WHERE marketplace = ?",
            [$this->marketplace]
        );
    }

    /**
     * Обновить цены в кэше после загрузки на Ozon
     * @param array $priceUpdates Массив обновлений [product_id => ['price' => X, 'min_price' => Y]]
     * @return int Количество обновлённых записей
     */
    public function updatePrices(array $priceUpdates): int
    {
        $updated = 0;

        foreach ($priceUpdates as $productId => $prices) {
            $data = ['synced_at' => date('Y-m-d H:i:s')];

            if (isset($prices['price'])) {
                $data['price'] = (float)$prices['price'];
            }
            if (isset($prices['min_price'])) {
                $data['min_price'] = (float)$prices['min_price'];
            }
            if (isset($prices['old_price'])) {
                $data['old_price'] = (float)$prices['old_price'];
            }

            $result = $this->db->update(
                'marketplace_products_cache',
                $data,
                'marketplace = ? AND product_id = ?',
                [$this->marketplace, $productId]
            );

            $updated += $result;
        }

        return $updated;
    }

    /**
     * Получить товары с устаревшим кэшем
     * @param int $hoursOld Количество часов
     * @return array
     */
    public function getStaleProducts(int $hoursOld = 24): array
    {
        $sql = "SELECT * FROM marketplace_products_cache
                WHERE marketplace = ?
                  AND synced_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY synced_at ASC";

        return $this->db->fetchAll($sql, [$this->marketplace, $hoursOld]);
    }

    /**
     * Определяет размер листа по умолчанию на основе названия товара
     * @param string $productName Название товара
     * @return array ['width' => int, 'height' => int, 'material' => string]
     */
    public static function getDefaultSheetSize(string $productName): array
    {
        $name = mb_strtolower($productName);

        // Фанера ФК (берёзовая) - стандартный размер 1520x1520
        if (preg_match('/фанера.*фк|фк.*фанера|берёз|береза/ui', $name)) {
            return ['width' => 1520, 'height' => 1520, 'material' => 'Фанера ФК'];
        }

        // Фанера ФСФ (влагостойкая) - стандартный размер 2440x1220
        if (preg_match('/фанера.*фсф|фсф.*фанера|влагост/ui', $name)) {
            return ['width' => 2440, 'height' => 1220, 'material' => 'Фанера ФСФ'];
        }

        // МДФ - стандартный размер 2800x2070
        if (preg_match('/мдф|mdf/ui', $name)) {
            return ['width' => 2800, 'height' => 2070, 'material' => 'МДФ'];
        }

        // ДВП - стандартный размер 2745x1700
        if (preg_match('/двп|двп/ui', $name)) {
            return ['width' => 2745, 'height' => 1700, 'material' => 'ДВП'];
        }

        // ЛДСП - стандартный размер 2800x2070
        if (preg_match('/лдсп|дсп|ламинир/ui', $name)) {
            return ['width' => 2800, 'height' => 2070, 'material' => 'ЛДСП'];
        }

        // Ищем явные размеры в названии: 1520x1520, 2440х1220
        if (preg_match('/(\d{3,4})\s*[xхX×\*]\s*(\d{3,4})/u', $name, $matches)) {
            return [
                'width' => (int)$matches[1],
                'height' => (int)$matches[2],
                'material' => 'Определён из названия'
            ];
        }

        // По умолчанию - фанера ФК 1520x1520
        return ['width' => 1520, 'height' => 1520, 'material' => 'Фанера ФК (по умолчанию)'];
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
     * Парсит артикул (offer_id) и/или название и извлекает размер и количество
     * Примеры артикулов:
     * - "фанера_1/2_4мм_760x768_5шт" → width=760, height=768, quantity=5, pieces_per_sheet=4
     * - "фанера_1/2_4мм_380x380_10шт" → width=380, height=380, quantity=10, pieces_per_sheet=16
     * - "фанера_1/2_4мм_1000x1000_3шт" → width=1000, height=1000, quantity=3, pieces_per_sheet=1
     * - "Фанера_A4_10шт" → width=210, height=297 (из формата A4)
     *
     * @param string $articleText Артикул (offer_id)
     * @param string $nameText Название товара на Ozon
     * @param int $baseWidth Ширина базового листа (по умолчанию 1520)
     * @param int $baseHeight Высота базового листа (по умолчанию 1520)
     * @return array ['width' => int, 'height' => int, 'quantity' => int, 'pieces_per_sheet' => int, 'format' => string|null]
     */
    public static function parseArticleName(string $articleText, string $nameText = '', int $baseWidth = 1520, int $baseHeight = 1520): array
    {
        $result = [
            'width' => 0,
            'height' => 0,
            'quantity' => 1,
            'pieces_per_sheet' => 1,
            'format' => null
        ];

        // Приводим к нижнему регистру для упрощения поиска
        $article = mb_strtolower($articleText);
        $name = mb_strtolower($nameText);
        $fullText = $article . ' ' . $name;

        // 1. Ищем явные размеры: 760x768, 760х768 (кириллица), 760X768, 760*768
        // Сначала в артикуле (приоритет)
        if (preg_match('/(\d{2,4})\s*[xхX×\*]\s*(\d{2,4})/u', $article, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }
        // Потом в названии, если в артикуле не нашли
        elseif (preg_match('/(\d{2,4})\s*[xхX×\*]\s*(\d{2,4})/u', $name, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }

        // 2. Если размеры не найдены — ищем формат бумаги: A4, A3, A2, A1, A0, А4, А3 (кириллица)
        if ($result['width'] == 0) {
            $formats = [
                '4' => ['width' => 210, 'height' => 297],
                '3' => ['width' => 297, 'height' => 420],
                '2' => ['width' => 420, 'height' => 594],
                '1' => ['width' => 594, 'height' => 841],
                '0' => ['width' => 841, 'height' => 1189],
            ];

            // Ищем формат: A4, А4, a4, а4
            if (preg_match('/[aа]\s*([0-4])/ui', $fullText, $matches)) {
                $formatNum = $matches[1];
                if (isset($formats[$formatNum])) {
                    $result['width'] = $formats[$formatNum]['width'];
                    $result['height'] = $formats[$formatNum]['height'];
                    $result['format'] = 'A' . $formatNum;
                }
            }
        }

        // 3. Ищем количество: 5шт, 5 шт, 5штук, 5 штук, 10 листов
        // Ограничение: min 1, max 10000 (защита от переполнения)
        // Сначала в артикуле
        if (preg_match('/[_\-\s]?(\d+)\s*(шт|штук|листов|лист)/ui', $article, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        }
        // Потом в названии
        elseif (preg_match('/(\d+)\s*(шт|штук|листов|лист)/ui', $name, $matches)) {
            $result['quantity'] = max(1, min(10000, (int)$matches[1]));
        }

        // 4. Вычисляем pieces_per_sheet если нашли размер
        // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
        // Максимальное количество кусочков — 10000 (защита от переполнения)
        if ($result['width'] >= 50 && $result['height'] >= 50) {
            // Вариант 1: стандартная ориентация
            $piecesWidth1 = floor($baseWidth / $result['width']);
            $piecesHeight1 = floor($baseHeight / $result['height']);
            $total1 = max(1, $piecesWidth1) * max(1, $piecesHeight1);

            // Вариант 2: повёрнутая ориентация (90°)
            $piecesWidth2 = floor($baseWidth / $result['height']);
            $piecesHeight2 = floor($baseHeight / $result['width']);
            $total2 = max(1, $piecesWidth2) * max(1, $piecesHeight2);

            // Выбираем лучший вариант (больше кусочков), но не больше 10000
            $result['pieces_per_sheet'] = min(10000, max($total1, $total2));
        }

        return $result;
    }

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack для маппингов товара
     * Парсит И артикул (offer_id), И название товара на Ozon
     * Артикул содержит размер и количество: фанера_1/2_4мм_760x768_5шт
     * Название может содержать: "Фанера для уроков труда, Размер A4 (297x210), 10 шт"
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
        $updated = 0;

        // Получаем все маппинги для этого товара
        // Получаем user_id через JOIN с products (поле называется created_by)
        $mappings = $this->db->fetchAll(
            "SELECT pm.id, p.created_by as user_id, pm.marketplace_offer_id, pm.marketplace_name,
                    mpc.offer_id as cache_offer_id, mpc.name as cache_name
             FROM product_mappings pm
             JOIN products p ON p.id = pm.product_id
             LEFT JOIN marketplace_products_cache mpc
                 ON mpc.product_id = pm.marketplace_product_id
                 AND mpc.marketplace = pm.marketplace
             WHERE pm.product_id = ? AND pm.marketplace = 'ozon' AND pm.is_active = 1",
            [$productId]
        );

        foreach ($mappings as $mapping) {
            // Собираем артикул (приоритет: из маппинга, потом из кэша)
            $articleText = $mapping['marketplace_offer_id'] ?: $mapping['cache_offer_id'] ?: '';

            // Собираем название (приоритет: из маппинга, потом из кэша)
            $nameText = $mapping['marketplace_name'] ?: $mapping['cache_name'] ?: '';

            // Если нет ни артикула, ни названия — пропускаем
            if (empty($articleText) && empty($nameText)) {
                continue;
            }

            // Парсим артикул И название
            $parsed = self::parseArticleName($articleText, $nameText, $baseWidth, $baseHeight);

            // Пытаемся найти в справочнике раскроя
            $piecesPerSheet = $parsed['pieces_per_sheet'];
            $fromReference = false;

            if ($parsed['width'] > 0 && $parsed['height'] > 0) {
                $userId = $mapping['user_id'] ?? 1;
                $referenceLookup = $this->lookupCuttingReference(
                    $userId,
                    $baseWidth,
                    $baseHeight,
                    $parsed['width'],
                    $parsed['height']
                );

                // Валидация значения из справочника (защита от некорректных данных)
                if ($referenceLookup !== null && $referenceLookup > 0 && $referenceLookup <= 10000) {
                    $piecesPerSheet = $referenceLookup;
                    $fromReference = true;
                }
            }

            // Финальная валидация перед записью в БД (защита от MySQL 22003)
            $piecesPerSheet = max(1, min(10000, (int)$piecesPerSheet));
            $quantity = max(1, min(10000, (int)$parsed['quantity']));

            // Логируем для отладки
            $formatInfo = $parsed['format'] ? " (format={$parsed['format']})" : '';
            $refInfo = $fromReference ? ' [from reference]' : ' [calculated]';
            error_log("AutoFill: mapping_id={$mapping['id']}, article='{$articleText}', name='{$nameText}' => pieces_per_sheet={$piecesPerSheet}, qty={$quantity}{$formatInfo}{$refInfo}");

            // Дополнительная проверка типов перед UPDATE
            if (!is_int($piecesPerSheet) || $piecesPerSheet < 1 || $piecesPerSheet > 10000) {
                error_log("AutoFill ERROR: invalid piecesPerSheet={$piecesPerSheet} (type=" . gettype($piecesPerSheet) . ")");
                $piecesPerSheet = 1;
            }
            if (!is_int($quantity) || $quantity < 1 || $quantity > 10000) {
                error_log("AutoFill ERROR: invalid quantity={$quantity} (type=" . gettype($quantity) . ")");
                $quantity = 1;
            }

            // Обновляем маппинг с try-catch для детальной диагностики
            try {
                $this->db->execute(
                    "UPDATE product_mappings
                     SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$piecesPerSheet, $quantity, $mapping['id']]
                );
                $updated++;
            } catch (PDOException $e) {
                error_log("AutoFill DB ERROR: mapping_id={$mapping['id']}, pps={$piecesPerSheet}, qty={$quantity}, code={$e->getCode()}, msg={$e->getMessage()}");
                throw $e;
            }
        }

        return $updated;
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
        // Ищем лист в справочнике с точным или близким размером
        // Допускаем погрешность в 50мм для размера листа
        // ВАЖНО: Используем CAST AS SIGNED для избежания ошибки MySQL 22003
        // при вычитании UNSIGNED значений (когда результат отрицательный)
        $sheet = $this->db->fetchOne(
            "SELECT id FROM cutting_sheets
             WHERE user_id = ? AND is_active = 1
               AND ABS(CAST(sheet_width AS SIGNED) - CAST(? AS SIGNED)) <= 50
               AND ABS(CAST(sheet_height AS SIGNED) - CAST(? AS SIGNED)) <= 50
             ORDER BY ABS(CAST(sheet_width AS SIGNED) - CAST(? AS SIGNED)) + ABS(CAST(sheet_height AS SIGNED) - CAST(? AS SIGNED)) ASC
             LIMIT 1",
            [$userId, $sheetWidth, $sheetHeight, $sheetWidth, $sheetHeight]
        );

        if (!$sheet) {
            return null;
        }

        // Ищем размер кусочка в справочнике
        // Точное совпадение или с учётом поворота на 90°
        $piece = $this->db->fetchOne(
            "SELECT actual_qty FROM cutting_pieces
             WHERE sheet_id = ?
               AND (
                   (piece_width = ? AND piece_height = ?)
                   OR (piece_width = ? AND piece_height = ?)
               )
             LIMIT 1",
            [$sheet['id'], $pieceWidth, $pieceHeight, $pieceHeight, $pieceWidth]
        );

        return $piece ? (int)$piece['actual_qty'] : null;
    }

    /**
     * Создать сопоставление товара с Ozon
     * @param int $userId ID пользователя
     * @param int $productId ID нашего товара
     * @param string $marketplaceProductId product_id с Ozon
     * @param int $quantityInPack Количество в упаковке
     * @param int $piecesPerSheet Кусочков с листа
     * @param float $costPrice Себестоимость
     * @return bool
     */
    public function createMapping(int $userId, int $productId, string $marketplaceProductId,
                                  int $quantityInPack = 1, int $piecesPerSheet = 1, float $costPrice = 0): bool
    {
        // Получаем данные товара из кэша
        $ozonProduct = $this->getByProductId($marketplaceProductId);
        $offerId = $ozonProduct['offer_id'] ?? null;
        $name = $ozonProduct['name'] ?? null;
        $sku = $ozonProduct['sku'] ?? null;

        return $this->db->execute("
            INSERT INTO product_mappings
            (user_id, product_id, marketplace, marketplace_product_id, marketplace_sku,
             marketplace_offer_id, marketplace_name, quantity_in_pack, pieces_per_sheet, cost_price, is_active)
            VALUES (?, ?, 'ozon', ?, ?, ?, ?, ?, ?, ?, 1)
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
            $userId,
            $productId,
            $marketplaceProductId,
            $sku,
            $offerId,
            $name,
            $quantityInPack,
            $piecesPerSheet,
            $costPrice
        ]);
    }

    /**
     * Удалить сопоставление товара
     */
    public function deleteMapping(int $userId, int $productId, string $marketplaceProductId): bool
    {
        return $this->db->execute(
            "DELETE FROM product_mappings
             WHERE user_id = ? AND product_id = ? AND marketplace = 'ozon' AND marketplace_product_id = ?",
            [$userId, $productId, $marketplaceProductId]
        );
    }

    /**
     * Получить все сопоставления (Ozon)
     */
    public function getAllMappings(): array
    {
        return $this->db->fetchAll("
            SELECT
                pm.*,
                mpc.name as cache_name,
                mpc.offer_id as cache_offer_id,
                mpc.price as current_price,
                mpc.min_price,
                mpc.stock,
                pr.name as product_name,
                pr.sku as product_sku
            FROM product_mappings pm
            LEFT JOIN marketplace_products_cache mpc
                ON mpc.product_id = pm.marketplace_product_id
                AND mpc.marketplace = pm.marketplace
            LEFT JOIN products pr ON pr.id = pm.product_id
            WHERE pm.marketplace = 'ozon' AND pm.is_active = 1
            ORDER BY pr.name, mpc.name
        ");
    }

    /**
     * Получить сопоставления для конкретного товара
     */
    public function getMappedProducts(int $productId): array
    {
        return $this->db->fetchAll("
            SELECT
                pm.*,
                mpc.name as cache_name,
                mpc.offer_id as cache_offer_id,
                mpc.price as current_price,
                mpc.min_price,
                mpc.old_price,
                mpc.stock
            FROM product_mappings pm
            LEFT JOIN marketplace_products_cache mpc
                ON mpc.product_id = pm.marketplace_product_id
                AND mpc.marketplace = pm.marketplace
            WHERE pm.product_id = ? AND pm.marketplace = 'ozon' AND pm.is_active = 1
            ORDER BY mpc.name
        ", [$productId]);
    }
}
