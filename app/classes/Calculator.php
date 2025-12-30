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
}
