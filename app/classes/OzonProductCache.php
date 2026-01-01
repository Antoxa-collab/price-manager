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
     * Парсит артикул (offer_id) и извлекает размер и количество
     * Примеры артикулов:
     * - "фанера_1/2_4мм_760x768_5шт" → width=760, height=768, quantity=5, pieces_per_sheet=4
     * - "фанера_1/2_4мм_380x380_10шт" → width=380, height=380, quantity=10, pieces_per_sheet=16
     * - "фанера_1/2_4мм_1000x1000_3шт" → width=1000, height=1000, quantity=3, pieces_per_sheet=1
     *
     * @param string $articleName Артикул (offer_id) или название
     * @param int $baseWidth Ширина базового листа (по умолчанию 1520)
     * @param int $baseHeight Высота базового листа (по умолчанию 1520)
     * @return array ['width' => int, 'height' => int, 'quantity' => int, 'pieces_per_sheet' => int]
     */
    public static function parseArticleName(string $articleName, int $baseWidth = 1520, int $baseHeight = 1520): array
    {
        $result = [
            'width' => 0,
            'height' => 0,
            'quantity' => 1,
            'pieces_per_sheet' => 1
        ];

        // Приводим к нижнему регистру для упрощения поиска
        $text = mb_strtolower($articleName);

        // Ищем размер в разных форматах:
        // 760x768, 760х768 (кириллица), 760X768, 760*768, 1000x500
        if (preg_match('/(\d+)\s*[xхX×\*]\s*(\d+)/u', $text, $matches)) {
            $result['width'] = (int)$matches[1];
            $result['height'] = (int)$matches[2];
        }

        // Ищем количество в разных форматах:
        // 5шт, 5 шт, 5штук, _5шт, -5шт
        if (preg_match('/[_\-\s]?(\d+)\s*шт/ui', $text, $matches)) {
            $result['quantity'] = max(1, (int)$matches[1]);
        }

        // Вычисляем pieces_per_sheet только если нашли размер
        if ($result['width'] > 0 && $result['height'] > 0) {
            // Сколько кусочков помещается по ширине и высоте
            // 760x768 из листа 1520x1520: floor(1520/760)=2, floor(1520/768)=1 → 2*1=2
            // Но 760x760: floor(1520/760)=2, floor(1520/760)=2 → 2*2=4
            $piecesWidth = floor($baseWidth / $result['width']);
            $piecesHeight = floor($baseHeight / $result['height']);

            // Минимум 1 кусок в каждом направлении
            $piecesWidth = max(1, $piecesWidth);
            $piecesHeight = max(1, $piecesHeight);

            $result['pieces_per_sheet'] = (int)($piecesWidth * $piecesHeight);
        }

        return $result;
    }

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack для маппингов товара
     * ВАЖНО: Парсит АРТИКУЛ (offer_id), а не название товара!
     * Артикул содержит размер и количество: фанера_1/2_4мм_760x768_5шт
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
        $mappings = $this->db->fetchAll(
            "SELECT pm.id, pm.marketplace_offer_id, pm.marketplace_name,
                    mpc.offer_id as cache_offer_id, mpc.name as cache_name
             FROM product_mappings pm
             LEFT JOIN marketplace_products_cache mpc
                 ON mpc.product_id = pm.marketplace_product_id
                 AND mpc.marketplace = pm.marketplace
             WHERE pm.product_id = ? AND pm.marketplace = 'ozon' AND pm.is_active = 1",
            [$productId]
        );

        foreach ($mappings as $mapping) {
            // ВАЖНО: Парсим АРТИКУЛ (offer_id), а не название!
            // Приоритет: marketplace_offer_id > cache_offer_id > marketplace_name > cache_name
            $textToParse = $mapping['marketplace_offer_id']
                        ?: $mapping['cache_offer_id']
                        ?: $mapping['marketplace_name']
                        ?: $mapping['cache_name']
                        ?: '';

            if (empty($textToParse)) {
                continue;
            }

            // Парсим артикул
            $parsed = self::parseArticleName($textToParse, $baseWidth, $baseHeight);

            // Логируем для отладки
            error_log("AutoFill: '{$textToParse}' => pieces_per_sheet={$parsed['pieces_per_sheet']}, qty={$parsed['quantity']}");

            // Обновляем маппинг
            $this->db->execute(
                "UPDATE product_mappings
                 SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW()
                 WHERE id = ?",
                [$parsed['pieces_per_sheet'], $parsed['quantity'], $mapping['id']]
            );

            $updated++;
        }

        return $updated;
    }
}
