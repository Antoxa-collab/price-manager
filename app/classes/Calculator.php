<?php
/**
 * Класс калькулятора цен
 * Расчёт цен с наценками для разных типов опта
 */
class Calculator
{
    private Database $db;
    private PriceRounder $rounder;

    /**
     * Типы наценок
     */
    public const MARKUP_TYPES = [
        'retail' => 'Мелкий опт (от 1 шт)',
        'medium' => 'Средний опт (от 10 шт)',
        'wholesale' => 'Крупный опт (от 50 шт)'
    ];

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->rounder = new PriceRounder();
    }

    /**
     * Расчёт цен с наценками
     * @param float $basePrice Закупочная цена
     * @param array $markups Массив наценок [retail, medium, wholesale]
     * @return array Результаты расчёта
     */
    public function calculate(float $basePrice, array $markups): array
    {
        $results = [];

        foreach (self::MARKUP_TYPES as $type => $label) {
            $markup = (float)($markups[$type] ?? 0);

            // Расчёт цены с наценкой
            $priceWithMarkup = $basePrice * (1 + $markup / 100);

            // Округление до красивой цены
            $roundedPrice = $this->rounder->round($priceWithMarkup);

            $results[$type] = [
                'label' => $label,
                'markup_percent' => $markup,
                'price_raw' => round($priceWithMarkup, 2),
                'price_rounded' => $roundedPrice,
                'difference' => round($roundedPrice - $priceWithMarkup, 2)
            ];
        }

        // Добавляем итоговые цены для маркетплейсов
        // Используем цену мелкого опта как базовую для маркетплейсов
        $results['wb_price'] = $results['retail']['price_rounded'];
        $results['ozon_price'] = $results['retail']['price_rounded'];

        return $results;
    }

    /**
     * Сохранение товара в базу данных
     * @param array $data Данные товара
     * @return int ID созданного/обновлённого товара
     */
    public function saveProduct(array $data): int
    {
        $db = $this->db;

        // Подготовка данных
        $productData = [
            'name' => sanitize($data['material_name'] ?? ''),
            'sku' => sanitize($data['seller_article'] ?? ''),
            'category' => sanitize($data['material_type'] ?? ''),
            'description' => sprintf(
                '%s, сорт %s, толщина %s мм',
                $data['material_name'] ?? '',
                $data['grade'] ?? '',
                $data['thickness'] ?? ''
            ),
            'base_price' => (float)($data['base_price'] ?? 0),
            'cost_price' => (float)($data['base_price'] ?? 0),
            'markup_percent' => (float)($data['markup_retail'] ?? 0),
            'final_price' => (float)($data['price_rounded'] ?? 0),
            'wb_article' => sanitize($data['wb_article'] ?? ''),
            'ozon_article' => sanitize($data['ozon_article'] ?? ''),
            'wb_price' => (float)($data['wb_price'] ?? 0),
            'ozon_price' => (float)($data['ozon_price'] ?? 0),
            'stock_quantity' => (int)($data['stock'] ?? 0),
            'is_active' => 1,
            'created_by' => $data['user_id'] ?? null
        ];

        // Проверяем, существует ли товар с таким SKU
        $existing = $db->fetchOne(
            "SELECT id FROM products WHERE sku = ?",
            [$productData['sku']]
        );

        if ($existing) {
            // Обновляем существующий товар
            $db->update('products', $productData, 'id = ?', [$existing['id']]);
            $productId = $existing['id'];

            // Логируем обновление
            $log = new OperationsLog();
            $log->add('update_product', 'product', $productId, null, $productData);
        } else {
            // Создаём новый товар
            $productId = $db->insert('products', $productData);

            // Логируем создание
            $log = new OperationsLog();
            $log->add('create_product', 'product', $productId, null, $productData);
        }

        return $productId;
    }

    /**
     * Получение товара по ID
     * @param int $id ID товара
     * @return array|null
     */
    public function getProduct(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM products WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получение товара по артикулу продавца
     * @param string $sku Артикул
     * @return array|null
     */
    public function getProductBySku(string $sku): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM products WHERE sku = ?",
            [sanitize($sku)]
        );
    }

    /**
     * Получение всех товаров
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array
     */
    public function getProducts(int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM products WHERE is_active = 1 ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Получение списка материалов
     * @return array
     */
    public function getMaterials(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM materials WHERE is_active = 1 ORDER BY name"
        );
    }

    /**
     * Обновление цен на маркетплейсе
     * @param int $productId ID товара
     * @param string $platform Платформа (wb или ozon)
     * @param float $price Новая цена
     * @return bool
     */
    public function updateMarketplacePrice(int $productId, string $platform, float $price): bool
    {
        $column = $platform === 'wb' ? 'wb_price' : 'ozon_price';

        $oldProduct = $this->getProduct($productId);
        $oldPrice = $oldProduct[$column] ?? 0;

        $result = $this->db->update(
            'products',
            [$column => $price],
            'id = ?',
            [$productId]
        );

        if ($result > 0) {
            $log = new OperationsLog();
            $log->add(
                'update_price_' . $platform,
                'product',
                $productId,
                [$column => $oldPrice],
                [$column => $price]
            );
        }

        return $result > 0;
    }

    /**
     * Обновление остатков
     * @param int $productId ID товара
     * @param int $stock Новое количество
     * @return bool
     */
    public function updateStock(int $productId, int $stock): bool
    {
        $oldProduct = $this->getProduct($productId);
        $oldStock = $oldProduct['stock_quantity'] ?? 0;

        $result = $this->db->update(
            'products',
            ['stock_quantity' => $stock],
            'id = ?',
            [$productId]
        );

        if ($result > 0) {
            $log = new OperationsLog();
            $log->add(
                'update_stock',
                'product',
                $productId,
                ['stock_quantity' => $oldStock],
                ['stock_quantity' => $stock]
            );
        }

        return $result > 0;
    }

    /**
     * Статический список материалов (для формы)
     * @return array
     */
    public static function getMaterialsList(): array
    {
        return [
            ['id' => 1, 'name' => 'Фанера ФК 1520x1520', 'size' => '1520x1520'],
            ['id' => 2, 'name' => 'OSB 2500x1250', 'size' => '2500x1250'],
            ['id' => 3, 'name' => 'МДФ 2800x2070', 'size' => '2800x2070'],
            ['id' => 4, 'name' => 'ДВП 2745x1700', 'size' => '2745x1700']
        ];
    }

    /**
     * Статический список сортов
     * @return array
     */
    public static function getGradesList(): array
    {
        return ['1/2', '2/2', '3/4', '4/4', 'F/F'];
    }

    /**
     * Статический список толщин
     * @return array
     */
    public static function getThicknessList(): array
    {
        return [3, 4, 6, 8, 10, 12, 15, 18, 21];
    }

    /**
     * Расчёт цен для Ozon с двумя уровнями наценки
     *
     * @param float $basePrice Закупочная цена за единицу
     * @param float $markupMinPrice Наценка для минимальной цены (%)
     * @param float $markupYourPrice Дополнительная наценка для "вашей цены" (%)
     * @param int $quantityInPack Количество единиц в упаковке на Ozon
     * @return array
     */
    public function calculateOzonPrices(
        float $basePrice,
        float $markupMinPrice,
        float $markupYourPrice,
        int $quantityInPack = 1
    ): array {
        // Расчёт базовой цены за упаковку
        $basePricePerPack = $basePrice * $quantityInPack;

        // Минимальная цена = закупочная + наценка для мин.цены
        $minPriceRaw = $basePricePerPack * (1 + $markupMinPrice / 100);
        $minPrice = $this->rounder->round($minPriceRaw);

        // Ваша цена = минимальная + доп.наценка
        $yourPriceRaw = $minPrice * (1 + $markupYourPrice / 100);
        $yourPrice = $this->rounder->round($yourPriceRaw);

        // Старая цена (зачёркнутая) = ваша цена + 15-20% для визуального эффекта скидки
        $oldPriceRaw = $yourPrice * 1.15;
        $oldPrice = $this->rounder->round($oldPriceRaw);

        return [
            'base_price_per_unit' => $basePrice,
            'base_price_per_pack' => $basePricePerPack,
            'quantity_in_pack' => $quantityInPack,
            'min_price_raw' => round($minPriceRaw, 2),
            'min_price' => $minPrice,
            'your_price_raw' => round($yourPriceRaw, 2),
            'your_price' => $yourPrice,
            'old_price' => $oldPrice,
            'markup_min_price' => $markupMinPrice,
            'markup_your_price' => $markupYourPrice,
            'profit_min' => round($minPrice - $basePricePerPack, 2),
            'profit_your' => round($yourPrice - $basePricePerPack, 2),
            'margin_min_percent' => $basePricePerPack > 0
                ? round((($minPrice - $basePricePerPack) / $basePricePerPack) * 100, 2)
                : 0,
            'margin_your_percent' => $basePricePerPack > 0
                ? round((($yourPrice - $basePricePerPack) / $basePricePerPack) * 100, 2)
                : 0
        ];
    }

    /**
     * Массовый расчёт цен для товаров с сопоставлениями
     *
     * @param array $mappedProducts Массив товаров с сопоставлениями (из ProductMapping::getMappedProducts)
     * @return array
     */
    public function calculateOzonPricesBulk(array $mappedProducts): array
    {
        $results = [];

        foreach ($mappedProducts as $product) {
            $basePrice = (float)($product['base_price'] ?? 0);
            $markupMinPrice = (float)($product['markup_min_price'] ?? 20);
            $markupYourPrice = (float)($product['markup_your_price'] ?? 5);
            $quantityInPack = (int)($product['quantity_in_pack'] ?? 1);

            $calculated = $this->calculateOzonPrices(
                $basePrice,
                $markupMinPrice,
                $markupYourPrice,
                $quantityInPack
            );

            $results[] = array_merge($product, [
                'calculated' => $calculated,
                'new_min_price' => $calculated['min_price'],
                'new_your_price' => $calculated['your_price'],
                'new_old_price' => $calculated['old_price'],
                'price_changed' => (
                    ($product['mp_min_price'] ?? 0) != $calculated['min_price'] ||
                    ($product['mp_price'] ?? 0) != $calculated['your_price']
                )
            ]);
        }

        return $results;
    }

    /**
     * Подготовка данных для отправки в Ozon API
     *
     * @param array $calculatedProducts Массив рассчитанных товаров
     * @param bool $onlyChanged Только изменённые
     * @return array
     */
    public function prepareOzonPriceUpdate(array $calculatedProducts, bool $onlyChanged = true): array
    {
        $pricesForApi = [];

        foreach ($calculatedProducts as $product) {
            if ($onlyChanged && !$product['price_changed']) {
                continue;
            }

            $pricesForApi[] = [
                'product_id' => (int)$product['marketplace_product_id'],
                'price' => $product['new_your_price'],
                'min_price' => $product['new_min_price'],
                'old_price' => $product['new_old_price'],
                // Метаданные для истории
                'our_product_id' => $product['id'] ?? null,
                'mapping_id' => $product['mapping_id'] ?? null,
                'old_mp_price' => $product['mp_price'] ?? null,
                'old_mp_min_price' => $product['mp_min_price'] ?? null
            ];
        }

        return $pricesForApi;
    }

    /**
     * Обновление наценок для товара
     *
     * @param int $productId ID товара
     * @param float $markupMinPrice Наценка для минимальной цены (%)
     * @param float $markupYourPrice Доп.наценка для вашей цены (%)
     * @return bool
     */
    public function updateProductMarkups(int $productId, float $markupMinPrice, float $markupYourPrice): bool
    {
        $oldProduct = $this->getProduct($productId);

        $result = $this->db->update(
            'products',
            [
                'markup_min_price' => $markupMinPrice,
                'markup_your_price' => $markupYourPrice
            ],
            'id = ?',
            [$productId]
        );

        if ($result > 0) {
            $log = new OperationsLog();
            $log->add(
                'update_markups',
                'product',
                $productId,
                [
                    'markup_min_price' => $oldProduct['markup_min_price'] ?? 0,
                    'markup_your_price' => $oldProduct['markup_your_price'] ?? 0
                ],
                [
                    'markup_min_price' => $markupMinPrice,
                    'markup_your_price' => $markupYourPrice
                ]
            );
        }

        return $result > 0;
    }

    /**
     * Массовое обновление наценок для группы товаров
     *
     * @param array $productIds Массив ID товаров
     * @param float $markupMinPrice Наценка для минимальной цены (%)
     * @param float $markupYourPrice Доп.наценка для вашей цены (%)
     * @return int Количество обновлённых товаров
     */
    public function bulkUpdateMarkups(array $productIds, float $markupMinPrice, float $markupYourPrice): int
    {
        $updated = 0;

        foreach ($productIds as $productId) {
            if ($this->updateProductMarkups((int)$productId, $markupMinPrice, $markupYourPrice)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Получение товаров с сопоставлениями для калькулятора Ozon
     *
     * @param string $marketplace Маркетплейс
     * @param array $filters Фильтры
     * @return array
     */
    public function getProductsForOzonCalculator(string $marketplace = 'ozon', array $filters = []): array
    {
        $sql = "SELECT p.*, pm.id as mapping_id, pm.marketplace_product_id,
                       pm.marketplace_sku, pm.marketplace_offer_id, pm.marketplace_name,
                       pm.quantity_in_pack,
                       mpc.price as mp_price, mpc.min_price as mp_min_price,
                       mpc.old_price as mp_old_price, mpc.stock as mp_stock
                FROM products p
                INNER JOIN product_mappings pm ON pm.product_id = p.id
                LEFT JOIN marketplace_products_cache mpc
                    ON mpc.marketplace = pm.marketplace
                    AND mpc.product_id = pm.marketplace_product_id
                WHERE pm.marketplace = ? AND pm.is_active = 1 AND p.is_active = 1";

        $params = [$marketplace];

        // Фильтр по категории
        if (!empty($filters['category'])) {
            $sql .= " AND p.category = ?";
            $params[] = $filters['category'];
        }

        // Фильтр по сорту
        if (!empty($filters['grade'])) {
            $sql .= " AND p.grade = ?";
            $params[] = $filters['grade'];
        }

        // Фильтр по толщине
        if (!empty($filters['thickness'])) {
            $sql .= " AND p.thickness = ?";
            $params[] = (float)$filters['thickness'];
        }

        // Поиск
        if (!empty($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR pm.marketplace_offer_id LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY p.category, p.name, pm.quantity_in_pack";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получение списка категорий
     *
     * @return array
     */
    public function getCategories(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT category FROM products WHERE is_active = 1 AND category IS NOT NULL ORDER BY category"
        );
    }

    /**
     * Получение товаров с сопоставлениями (для выпадающего списка калькулятора)
     *
     * @param string $marketplace Маркетплейс
     * @return array
     */
    public function getProductsWithMappings(string $marketplace = 'ozon'): array
    {
        $sql = "SELECT p.*,
                       COUNT(pm.id) as mapping_count
                FROM products p
                INNER JOIN product_mappings pm ON pm.product_id = p.id
                WHERE pm.marketplace = ? AND pm.is_active = 1 AND p.is_active = 1
                GROUP BY p.id
                ORDER BY p.name";

        return $this->db->fetchAll($sql, [$marketplace]);
    }

    /**
     * Создание нового товара
     *
     * @param array $data Данные товара
     * @return int ID созданного товара
     */
    public function createProduct(array $data): int
    {
        $productData = [
            'name' => sanitize($data['name'] ?? ''),
            'sku' => sanitize($data['sku'] ?? '') ?: $this->generateSku(),
            'category' => sanitize($data['category'] ?? ''),
            'material_type' => sanitize($data['material_type'] ?? ''),
            'grade' => sanitize($data['grade'] ?? ''),
            'thickness' => isset($data['thickness']) ? (float)$data['thickness'] : null,
            'cost_price' => (float)($data['cost_price'] ?? 0),
            'base_price' => (float)($data['base_price'] ?? $data['cost_price'] ?? 0),
            'markup_min_price' => (float)($data['markup_min_price'] ?? 20),
            'markup_your_price' => (float)($data['markup_your_price'] ?? 5),
            'is_active' => 1,
            'created_by' => $data['created_by'] ?? null
        ];

        $productId = $this->db->insert('products', $productData);

        // Логируем создание
        $log = new OperationsLog();
        $log->add('create_product', 'product', $productId, null, $productData);

        return $productId;
    }

    /**
     * Генерация уникального SKU
     *
     * @return string
     */
    private function generateSku(): string
    {
        return 'PRD-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
