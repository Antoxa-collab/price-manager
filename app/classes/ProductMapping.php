<?php
/**
 * Класс для управления сопоставлениями товаров с маркетплейсами
 * Связывает наши товары (материал + сорт + толщина) с артикулами на Ozon/WB
 */
class ProductMapping
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Кэш существующих колонок в product_mappings
     * @var array|null
     */
    private static ?array $existingColumns = null;

    /**
     * Проверить существование колонки в product_mappings
     * @param string $columnName Имя колонки
     * @return bool
     */
    private function hasColumn(string $columnName): bool
    {
        if (self::$existingColumns === null) {
            try {
                $result = $this->db->fetchAll(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'product_mappings'"
                );
                self::$existingColumns = array_column($result, 'COLUMN_NAME');
            } catch (Exception $e) {
                self::$existingColumns = [];
            }
        }
        return in_array($columnName, self::$existingColumns);
    }

    /**
     * Получить все сопоставления для товара
     * @param int $productId ID нашего товара
     * @param string $marketplace Маркетплейс (ozon, wildberries, yandex)
     * @return array
     */
    public function getByProduct(int $productId, string $marketplace = 'ozon'): array
    {
        // Динамически определяем какие колонки запрашивать
        $cachedColumns = $this->hasColumn('cached_price')
            ? "pm.cached_price, pm.cached_stock,"
            : "NULL as cached_price, NULL as cached_stock,";

        $discountColumns = $this->hasColumn('custom_discount')
            ? "pm.custom_discount, pm.is_discount_edited,"
            : "NULL as custom_discount, 0 as is_discount_edited,";

        $costPrice = $this->hasColumn('cost_price')
            ? "pm.cost_price,"
            : "0 as cost_price,";

        $sql = "SELECT pm.id as mapping_id, pm.product_id, pm.marketplace,
                       pm.marketplace_product_id, pm.marketplace_sku,
                       pm.marketplace_offer_id, pm.marketplace_name,
                       pm.quantity_in_pack, pm.pieces_per_sheet,
                       {$cachedColumns} {$costPrice}
                       {$discountColumns}
                       mpc.name as cached_name, mpc.price as mp_price,
                       mpc.min_price as mp_min_price, mpc.old_price as mp_old_price,
                       mpc.stock as mp_stock
                FROM product_mappings pm
                LEFT JOIN marketplace_products_cache mpc
                    ON mpc.marketplace = pm.marketplace
                    AND mpc.product_id = pm.marketplace_product_id
                WHERE pm.product_id = ? AND pm.marketplace = ? AND pm.is_active = 1
                ORDER BY pm.created_at DESC";

        return $this->db->fetchAll($sql, [$productId, $marketplace]);
    }

    /**
     * Добавить сопоставление
     * @param int $productId ID нашего товара
     * @param string $marketplace Маркетплейс
     * @param array $marketplaceData Данные с маркетплейса
     * @return int ID созданного сопоставления
     */
    public function addMapping(int $productId, string $marketplace, array $marketplaceData): int
    {
        // Проверяем, нет ли уже такого сопоставления
        $existing = $this->db->fetchOne(
            "SELECT id FROM product_mappings
             WHERE product_id = ? AND marketplace = ? AND marketplace_product_id = ?",
            [$productId, $marketplace, $marketplaceData['product_id']]
        );

        if ($existing) {
            // Обновляем существующее сопоставление
            $this->db->update(
                'product_mappings',
                [
                    'marketplace_sku' => $marketplaceData['sku'] ?? null,
                    'marketplace_offer_id' => $marketplaceData['offer_id'] ?? null,
                    'marketplace_name' => $marketplaceData['name'] ?? null,
                    'quantity_in_pack' => $marketplaceData['quantity_in_pack'] ?? 1,
                    'pieces_per_sheet' => $marketplaceData['pieces_per_sheet'] ?? 1,
                    'is_active' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'id = ?',
                [$existing['id']]
            );
            return (int)$existing['id'];
        }

        // Создаём новое сопоставление
        return $this->db->insert('product_mappings', [
            'product_id' => $productId,
            'marketplace' => $marketplace,
            'marketplace_product_id' => $marketplaceData['product_id'],
            'marketplace_sku' => $marketplaceData['sku'] ?? null,
            'marketplace_offer_id' => $marketplaceData['offer_id'] ?? null,
            'marketplace_name' => $marketplaceData['name'] ?? null,
            'quantity_in_pack' => $marketplaceData['quantity_in_pack'] ?? 1,
            'pieces_per_sheet' => $marketplaceData['pieces_per_sheet'] ?? 1,
            'is_active' => 1
        ]);
    }

    /**
     * Удалить сопоставление (мягкое удаление)
     * @param int $mappingId ID сопоставления
     * @return bool
     */
    public function removeMapping(int $mappingId): bool
    {
        $result = $this->db->update(
            'product_mappings',
            ['is_active' => 0],
            'id = ?',
            [$mappingId]
        );
        return $result > 0;
    }

    /**
     * Удалить сопоставление полностью
     * @param int $mappingId ID сопоставления
     * @return bool
     */
    public function deleteMapping(int $mappingId): bool
    {
        $result = $this->db->delete('product_mappings', 'id = ?', [$mappingId]);
        return $result > 0;
    }

    /**
     * Получить все сопоставленные товары с маркетплейса
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function getMappedProducts(string $marketplace = 'ozon'): array
    {
        $sql = "SELECT p.*, pm.id as mapping_id, pm.marketplace_product_id,
                       pm.marketplace_sku, pm.marketplace_offer_id, pm.marketplace_name,
                       pm.quantity_in_pack, pm.pieces_per_sheet,
                       mpc.price as mp_price, mpc.min_price as mp_min_price,
                       mpc.old_price as mp_old_price, mpc.stock as mp_stock
                FROM products p
                INNER JOIN product_mappings pm ON pm.product_id = p.id
                LEFT JOIN marketplace_products_cache mpc
                    ON mpc.marketplace = pm.marketplace
                    AND mpc.product_id = pm.marketplace_product_id
                WHERE pm.marketplace = ? AND pm.is_active = 1 AND p.is_active = 1
                ORDER BY p.category, p.name";

        return $this->db->fetchAll($sql, [$marketplace]);
    }

    /**
     * Получить несопоставленные товары с маркетплейса (из кэша)
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function getUnmappedMarketplaceProducts(string $marketplace = 'ozon'): array
    {
        $sql = "SELECT mpc.*
                FROM marketplace_products_cache mpc
                LEFT JOIN product_mappings pm
                    ON pm.marketplace = mpc.marketplace
                    AND pm.marketplace_product_id = mpc.product_id
                    AND pm.is_active = 1
                WHERE mpc.marketplace = ? AND pm.id IS NULL
                ORDER BY mpc.name";

        return $this->db->fetchAll($sql, [$marketplace]);
    }

    /**
     * Получить наши несопоставленные товары
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function getUnmappedOurProducts(string $marketplace = 'ozon'): array
    {
        $sql = "SELECT p.*
                FROM products p
                LEFT JOIN product_mappings pm
                    ON pm.product_id = p.id
                    AND pm.marketplace = ?
                    AND pm.is_active = 1
                WHERE p.is_active = 1 AND pm.id IS NULL
                ORDER BY p.category, p.name";

        return $this->db->fetchAll($sql, [$marketplace]);
    }

    /**
     * Получить сопоставление по ID
     * @param int $mappingId ID сопоставления
     * @return array|null
     */
    public function getById(int $mappingId): ?array
    {
        return $this->db->fetchOne(
            "SELECT pm.*, p.name as product_name, p.base_price, p.markup_percent,
                    p.markup_min_price, p.markup_your_price
             FROM product_mappings pm
             INNER JOIN products p ON p.id = pm.product_id
             WHERE pm.id = ?",
            [$mappingId]
        );
    }

    /**
     * Обновить количество в упаковке для сопоставления
     * @param int $mappingId ID сопоставления
     * @param int $quantity Количество в упаковке
     * @return bool
     */
    public function updateQuantityInPack(int $mappingId, int $quantity): bool
    {
        $result = $this->db->update(
            'product_mappings',
            ['quantity_in_pack' => $quantity],
            'id = ?',
            [$mappingId]
        );
        return $result > 0;
    }

    /**
     * Обновить pieces_per_sheet для сопоставления
     * @param int $mappingId ID сопоставления
     * @param int $piecesPerSheet Количество единиц из 1 листа
     * @return bool
     */
    public function updatePiecesPerSheet(int $mappingId, int $piecesPerSheet): bool
    {
        $result = $this->db->update(
            'product_mappings',
            ['pieces_per_sheet' => max(1, $piecesPerSheet)],
            'id = ?',
            [$mappingId]
        );
        return $result > 0;
    }

    /**
     * Обновить оба параметра упаковки для сопоставления
     * @param int $mappingId ID сопоставления
     * @param int $quantityInPack Количество в упаковке на Ozon
     * @param int $piecesPerSheet Количество единиц из 1 листа
     * @return bool
     */
    public function updatePackSettings(int $mappingId, int $quantityInPack, int $piecesPerSheet): bool
    {
        $result = $this->db->update(
            'product_mappings',
            [
                'quantity_in_pack' => max(1, $quantityInPack),
                'pieces_per_sheet' => max(1, $piecesPerSheet)
            ],
            'id = ?',
            [$mappingId]
        );
        return $result > 0;
    }

    /**
     * Массовое сопоставление товаров
     * @param array $mappings Массив сопоставлений [['product_id' => X, 'marketplace_data' => [...]]]
     * @param string $marketplace Маркетплейс
     * @return array Результат операции
     */
    public function bulkAddMappings(array $mappings, string $marketplace = 'ozon'): array
    {
        $success = 0;
        $errors = [];

        $this->db->beginTransaction();
        try {
            foreach ($mappings as $mapping) {
                try {
                    $this->addMapping(
                        $mapping['product_id'],
                        $marketplace,
                        $mapping['marketplace_data']
                    );
                    $success++;
                } catch (Exception $e) {
                    $errors[] = [
                        'product_id' => $mapping['product_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => 'Ошибка при сохранении: ' . $e->getMessage(),
                'saved' => 0,
                'errors' => []
            ];
        }

        return [
            'success' => count($errors) === 0,
            'message' => "Сохранено {$success} сопоставлений",
            'saved' => $success,
            'errors' => $errors
        ];
    }

    /**
     * Поиск товаров маркетплейса в кэше
     * @param string $query Поисковый запрос
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function searchMarketplaceProducts(string $query, string $marketplace = 'ozon'): array
    {
        $sql = "SELECT mpc.*,
                       CASE WHEN pm.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped,
                       pm.product_id as mapped_to_product_id
                FROM marketplace_products_cache mpc
                LEFT JOIN product_mappings pm
                    ON pm.marketplace = mpc.marketplace
                    AND pm.marketplace_product_id = mpc.product_id
                    AND pm.is_active = 1
                WHERE mpc.marketplace = ?
                  AND (mpc.name LIKE ? OR mpc.offer_id LIKE ? OR mpc.sku LIKE ?)
                ORDER BY mpc.name
                LIMIT 50";

        $searchTerm = '%' . $query . '%';
        return $this->db->fetchAll($sql, [$marketplace, $searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Получить список product_id маркетплейса, которые уже сопоставлены
     * @param string $marketplace Маркетплейс
     * @return array Массив marketplace_product_id
     */
    public function getMappedMarketplaceProductIds(string $marketplace = 'ozon'): array
    {
        $result = $this->db->fetchAll(
            "SELECT marketplace_product_id
             FROM product_mappings
             WHERE marketplace = ? AND is_active = 1",
            [$marketplace]
        );

        return array_column($result, 'marketplace_product_id');
    }

    /**
     * Получить статистику сопоставлений
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function getStatistics(string $marketplace = 'ozon'): array
    {
        $stats = [];

        // Всего товаров на маркетплейсе (в кэше)
        $stats['total_marketplace'] = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM marketplace_products_cache WHERE marketplace = ?",
            [$marketplace]
        );

        // Всего наших товаров
        $stats['total_our_products'] = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM products WHERE is_active = 1"
        );

        // Сопоставлено товаров маркетплейса
        $stats['mapped_marketplace'] = (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT pm.marketplace_product_id)
             FROM product_mappings pm
             WHERE pm.marketplace = ? AND pm.is_active = 1",
            [$marketplace]
        );

        // Наших товаров с сопоставлениями
        $stats['our_products_with_mappings'] = (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT pm.product_id)
             FROM product_mappings pm
             WHERE pm.marketplace = ? AND pm.is_active = 1",
            [$marketplace]
        );

        // Процент сопоставления
        $stats['mapping_percent'] = $stats['total_marketplace'] > 0
            ? round(($stats['mapped_marketplace'] / $stats['total_marketplace']) * 100, 1)
            : 0;

        return $stats;
    }
}
