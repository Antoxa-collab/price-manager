<?php
/**
 * Класс для работы с базой знаний о товарах
 * Хранит информацию о товарах из WB Content API для использования AI
 */
class ProductKnowledge
{
    private Database $db;
    private ?WildberriesAPI $wbApi = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Установить WB API для синхронизации
     */
    public function setWildberriesAPI(WildberriesAPI $api): void
    {
        $this->wbApi = $api;
    }

    /**
     * Синхронизировать все карточки товаров с WB
     */
    public function syncAllFromWildberries(int $userId): array
    {
        if (!$this->wbApi) {
            throw new Exception('WildberriesAPI не установлен');
        }

        $cards = $this->wbApi->getAllProductCards();
        $synced = 0;
        $updated = 0;
        $errors = 0;

        foreach ($cards as $card) {
            try {
                $result = $this->saveProductCard($userId, $card);
                if ($result === 'inserted') {
                    $synced++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            } catch (Exception $e) {
                $errors++;
                error_log("[ProductKnowledge] Ошибка сохранения карточки: " . $e->getMessage());
            }
        }

        return [
            'total' => count($cards),
            'synced' => $synced,
            'updated' => $updated,
            'errors' => $errors
        ];
    }

    /**
     * Сохранить карточку товара в базу знаний
     */
    public function saveProductCard(int $userId, array $card): string
    {
        $nmId = $card['nmID'] ?? null;
        if (!$nmId) {
            throw new Exception('nmID отсутствует в карточке');
        }

        // Извлекаем ключевые характеристики
        $keyChars = $this->extractKeyCharacteristics($card['characteristics'] ?? []);

        // Извлекаем данные из карточки WB
        $data = [
            'user_id' => $userId,
            'marketplace' => 'wildberries',
            'marketplace_product_id' => (string)$nmId,
            'imt_id' => isset($card['imtID']) ? (string)$card['imtID'] : null,
            'supplier_article' => $card['vendorCode'] ?? null,
            'product_name' => $card['title'] ?? null,
            'brand' => $card['brand'] ?? null,
            'product_description' => $card['description'] ?? null,
            'characteristics' => !empty($card['characteristics'])
                ? json_encode($card['characteristics'], JSON_UNESCAPED_UNICODE)
                : null,
            'dimensions' => $keyChars['dimensions'],
            'weight' => $keyChars['weight'],
            'material' => $keyChars['material'],
            'quantity_in_pack' => $keyChars['quantity_in_pack'],
            'last_synced_at' => date('Y-m-d H:i:s'),
            'wb_updated_at' => isset($card['updatedAt']) ? $this->convertIsoDate($card['updatedAt']) : null
        ];

        // Проверяем существует ли запись
        $existing = $this->db->fetchOne(
            "SELECT id FROM product_knowledge
             WHERE marketplace = 'wildberries' AND marketplace_product_id = ?",
            [(string)$nmId]
        );

        if ($existing) {
            // Обновляем существующую запись
            $this->db->execute(
                "UPDATE product_knowledge SET
                    supplier_article = ?,
                    product_name = ?,
                    brand = ?,
                    product_description = ?,
                    characteristics = ?,
                    dimensions = ?,
                    weight = ?,
                    material = ?,
                    quantity_in_pack = ?,
                    last_synced_at = ?,
                    wb_updated_at = ?
                WHERE id = ?",
                [
                    $data['supplier_article'],
                    $data['product_name'],
                    $data['brand'],
                    $data['product_description'],
                    $data['characteristics'],
                    $data['dimensions'],
                    $data['weight'],
                    $data['material'],
                    $data['quantity_in_pack'],
                    $data['last_synced_at'],
                    $data['wb_updated_at'],
                    $existing['id']
                ]
            );
            return 'updated';
        } else {
            // Создаём новую запись
            $this->db->execute(
                "INSERT INTO product_knowledge
                (user_id, marketplace, marketplace_product_id, imt_id, supplier_article,
                 product_name, brand, product_description, characteristics, dimensions,
                 weight, material, quantity_in_pack, last_synced_at, wb_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['user_id'],
                    $data['marketplace'],
                    $data['marketplace_product_id'],
                    $data['imt_id'],
                    $data['supplier_article'],
                    $data['product_name'],
                    $data['brand'],
                    $data['product_description'],
                    $data['characteristics'],
                    $data['dimensions'],
                    $data['weight'],
                    $data['material'],
                    $data['quantity_in_pack'],
                    $data['last_synced_at'],
                    $data['wb_updated_at']
                ]
            );
            return 'inserted';
        }
    }

    /**
     * Получить информацию о товаре по nmId
     */
    public function getByNmId(string $nmId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM product_knowledge
             WHERE marketplace = 'wildberries' AND marketplace_product_id = ?",
            [$nmId]
        );
    }

    /**
     * Получить информацию о товаре по артикулу продавца
     */
    public function getByArticle(string $article): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM product_knowledge
             WHERE marketplace = 'wildberries' AND supplier_article = ?",
            [$article]
        );
    }

    /**
     * Получить контекст товара для AI промпта
     */
    public function getProductContextForAI(string $nmId): string
    {
        $product = $this->getByNmId($nmId);

        if (!$product) {
            return '';
        }

        $context = "═══════════════════════════════════════════════════\n";
        $context .= "ДАННЫЕ О ТОВАРЕ (используй для точных ответов):\n";
        $context .= "═══════════════════════════════════════════════════\n\n";

        if (!empty($product['product_name'])) {
            $context .= "📦 Название: {$product['product_name']}\n";
        }
        if (!empty($product['supplier_article'])) {
            $context .= "🏷️ Артикул продавца: {$product['supplier_article']}\n";
        }
        if (!empty($product['brand'])) {
            $context .= "🏢 Бренд: {$product['brand']}\n";
        }

        // Извлекаем и вычисляем размеры для листовых материалов
        $dimensions = $this->extractNumericDimensions($product);
        $calculations = $this->calculateSheetData($dimensions, $product);

        $context .= "\n📐 РАЗМЕРЫ И ХАРАКТЕРИСТИКИ:\n";

        // Числовые размеры (для расчётов)
        if ($dimensions['length']) {
            $context .= "• Длина: {$dimensions['length']} мм\n";
        }
        if ($dimensions['width']) {
            $context .= "• Ширина: {$dimensions['width']} мм\n";
        }
        if ($dimensions['thickness']) {
            $context .= "• Толщина: {$dimensions['thickness']} мм\n";
        }

        // Вес
        if (!empty($product['weight'])) {
            $context .= "• Вес упаковки: {$product['weight']}\n";
        }

        // Материал
        if (!empty($product['material'])) {
            $context .= "• Материал: {$product['material']}\n";
        }

        // Количество в упаковке
        if (!empty($product['quantity_in_pack'])) {
            $context .= "• Количество в упаковке: {$product['quantity_in_pack']}\n";
        } elseif ($calculations['sheets_per_pack']) {
            $context .= "• Количество в упаковке: {$calculations['sheets_per_pack']} шт\n";
        }

        // ВЫЧИСЛЕННЫЕ ДАННЫЕ для расчётов
        if ($calculations['sheet_area'] || $calculations['pack_area']) {
            $context .= "\n📊 ДАННЫЕ ДЛЯ РАСЧЁТОВ:\n";

            if ($calculations['sheet_area']) {
                $context .= "• Площадь одного листа: {$calculations['sheet_area']} кв.м\n";
            }
            if ($calculations['pack_area']) {
                $context .= "• Площадь упаковки: {$calculations['pack_area']} кв.м\n";
            }
            if ($calculations['weight_per_sheet']) {
                $context .= "• Вес одного листа: ~{$calculations['weight_per_sheet']} кг\n";
            }
        }

        // Важные заметки от продавца
        if (!empty($product['custom_notes'])) {
            $context .= "\n⚠️ ВАЖНАЯ ИНФОРМАЦИЯ:\n{$product['custom_notes']}\n";
        }

        // Полное описание (сокращённое)
        if (!empty($product['product_description'])) {
            $desc = mb_substr($product['product_description'], 0, 500);
            $context .= "\n📝 Описание: {$desc}\n";
        }

        // Дополнительные характеристики (только важные)
        if (!empty($product['characteristics'])) {
            $chars = json_decode($product['characteristics'], true);
            if (is_array($chars) && !empty($chars)) {
                $importantChars = $this->filterImportantCharacteristics($chars);
                if (!empty($importantChars)) {
                    $context .= "\n📋 Дополнительные характеристики:\n";
                    foreach ($importantChars as $char) {
                        $context .= "• {$char['name']}: {$char['value']}\n";
                    }
                }
            }
        }

        $context .= "\n═══════════════════════════════════════════════════\n";

        return $context;
    }

    /**
     * Извлечь числовые размеры из характеристик
     */
    private function extractNumericDimensions(array $product): array
    {
        $result = [
            'length' => null,
            'width' => null,
            'thickness' => null
        ];

        // Сначала пробуем из названия товара (часто формат: 6х625х500мм)
        $name = $product['product_name'] ?? '';
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[хxХX×]\s*(\d+(?:[.,]\d+)?)\s*[хxХX×]\s*(\d+(?:[.,]\d+)?)/u', $name, $m)) {
            // Определяем что есть что по величине
            $dims = [(float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2]), (float)str_replace(',', '.', $m[3])];
            sort($dims);
            $result['thickness'] = $dims[0];  // Наименьшее - толщина
            $result['width'] = $dims[1];       // Среднее - ширина
            $result['length'] = $dims[2];      // Наибольшее - длина
        }

        // Дополняем из характеристик
        if (!empty($product['characteristics'])) {
            $chars = json_decode($product['characteristics'], true);
            if (is_array($chars)) {
                foreach ($chars as $char) {
                    $name = mb_strtolower($char['name'] ?? '');
                    $value = $char['value'] ?? '';
                    if (is_array($value)) {
                        $value = $value[0] ?? '';
                    }

                    // Извлекаем число из значения
                    if (preg_match('/(\d+(?:[.,]\d+)?)/u', (string)$value, $numMatch)) {
                        $num = (float)str_replace(',', '.', $numMatch[1]);

                        if (strpos($name, 'длина') !== false && !$result['length']) {
                            $result['length'] = $num;
                        } elseif (strpos($name, 'ширина') !== false && !$result['width']) {
                            $result['width'] = $num;
                        } elseif (strpos($name, 'толщин') !== false && !$result['thickness']) {
                            $result['thickness'] = $num;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Вычислить данные для листовых материалов
     */
    private function calculateSheetData(array $dimensions, array $product): array
    {
        $result = [
            'sheet_area' => null,
            'pack_area' => null,
            'sheets_per_pack' => null,
            'weight_per_sheet' => null
        ];

        // Вычисляем площадь листа
        if ($dimensions['length'] && $dimensions['width']) {
            $lengthM = $dimensions['length'] / 1000;  // мм -> м
            $widthM = $dimensions['width'] / 1000;
            $sheetArea = round($lengthM * $widthM, 4);
            $result['sheet_area'] = $sheetArea;

            // Извлекаем количество в упаковке
            $qtyStr = $product['quantity_in_pack'] ?? '';
            if (preg_match('/(\d+)/u', $qtyStr, $qtyMatch)) {
                $sheetsPerPack = (int)$qtyMatch[1];
                $result['sheets_per_pack'] = $sheetsPerPack;

                // Вычисляем площадь упаковки
                if ($sheetsPerPack > 0) {
                    $result['pack_area'] = round($sheetArea * $sheetsPerPack, 2);
                }
            }

            // Вычисляем вес одного листа
            $weightStr = $product['weight'] ?? '';
            if (preg_match('/(\d+(?:[.,]\d+)?)/u', $weightStr, $wMatch) && $result['sheets_per_pack']) {
                $totalWeight = (float)str_replace(',', '.', $wMatch[1]);
                if ($result['sheets_per_pack'] > 0) {
                    $result['weight_per_sheet'] = round($totalWeight / $result['sheets_per_pack'], 2);
                }
            }
        }

        return $result;
    }

    /**
     * Отфильтровать важные характеристики
     */
    private function filterImportantCharacteristics(array $chars): array
    {
        $important = [];
        $importantKeywords = ['толщ', 'длин', 'ширин', 'вес', 'масс', 'количеств', 'штук', 'площадь',
                              'марка', 'сорт', 'класс', 'влагостойк', 'плотность', 'нагрузк'];
        $added = 0;

        foreach ($chars as $char) {
            if ($added >= 10) break;

            $name = mb_strtolower($char['name'] ?? '');
            $value = $char['value'] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            if (!$name || !$value) continue;

            // Проверяем ключевые слова
            foreach ($importantKeywords as $keyword) {
                if (mb_strpos($name, $keyword) !== false) {
                    $important[] = ['name' => $char['name'], 'value' => $value];
                    $added++;
                    break;
                }
            }
        }

        return $important;
    }

    /**
     * Извлечь ключевые характеристики из массива характеристик WB
     */
    private function extractKeyCharacteristics(array $characteristics): array
    {
        $result = [
            'dimensions' => null,
            'weight' => null,
            'material' => null,
            'quantity_in_pack' => null
        ];

        $dims = [];

        foreach ($characteristics as $char) {
            $name = mb_strtolower($char['name'] ?? '');
            $value = $char['value'] ?? null;

            if (!$value) {
                continue;
            }

            // Размеры
            if (strpos($name, 'длина') !== false ||
                strpos($name, 'ширина') !== false ||
                strpos($name, 'высота') !== false ||
                strpos($name, 'толщина') !== false ||
                strpos($name, 'размер') !== false ||
                strpos($name, 'габарит') !== false ||
                strpos($name, 'диаметр') !== false
            ) {
                $valStr = is_array($value) ? implode(', ', $value) : (string)$value;
                $dims[] = "{$char['name']}: {$valStr}";
            }

            // Вес
            if (strpos($name, 'вес') !== false ||
                strpos($name, 'масса') !== false
            ) {
                $result['weight'] = is_array($value) ? implode(', ', $value) : (string)$value;
            }

            // Материал
            if (strpos($name, 'материал') !== false ||
                strpos($name, 'состав') !== false
            ) {
                $result['material'] = is_array($value) ? implode(', ', $value) : (string)$value;
            }

            // Количество в упаковке
            if (strpos($name, 'количество') !== false &&
                (strpos($name, 'упаков') !== false || strpos($name, 'комплект') !== false || strpos($name, 'набор') !== false)
            ) {
                $result['quantity_in_pack'] = is_array($value) ? implode(', ', $value) : (string)$value;
            }

            // Также проверяем прямое совпадение "количество штук"
            if (strpos($name, 'штук') !== false || $name === 'количество') {
                $result['quantity_in_pack'] = is_array($value) ? implode(', ', $value) : (string)$value;
            }
        }

        $result['dimensions'] = !empty($dims) ? implode(', ', $dims) : null;

        return $result;
    }

    /**
     * Извлечь размеры из характеристик (устаревший метод, оставлен для совместимости)
     */
    private function extractDimensions(array $card): ?string
    {
        $characteristics = $card['characteristics'] ?? [];
        $dims = [];

        foreach ($characteristics as $char) {
            $name = mb_strtolower($char['name'] ?? '');
            $value = $char['value'] ?? null;

            if ($value && (
                strpos($name, 'длина') !== false ||
                strpos($name, 'ширина') !== false ||
                strpos($name, 'высота') !== false ||
                strpos($name, 'толщина') !== false ||
                strpos($name, 'размер') !== false ||
                strpos($name, 'габарит') !== false
            )) {
                $dims[] = "{$char['name']}: {$value}";
            }
        }

        return !empty($dims) ? implode(', ', $dims) : null;
    }

    /**
     * Обновить заметки для AI (вручную)
     */
    public function updateCustomNotes(int $id, string $notes): bool
    {
        return $this->db->execute(
            "UPDATE product_knowledge SET custom_notes = ?, updated_at = NOW() WHERE id = ?",
            [$notes, $id]
        ) > 0;
    }

    /**
     * Получить список всех товаров
     */
    public function getAll(int $userId, int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $sql = "SELECT * FROM product_knowledge
                WHERE user_id = ? AND marketplace = 'wildberries'";
        $params = [$userId];

        if ($search) {
            $sql .= " AND (product_name LIKE ? OR supplier_article LIKE ? OR marketplace_product_id LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $sql .= " ORDER BY product_name ASC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получить количество товаров
     */
    public function getCount(int $userId, ?string $search = null): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM product_knowledge
                WHERE user_id = ? AND marketplace = 'wildberries'";
        $params = [$userId];

        if ($search) {
            $sql .= " AND (product_name LIKE ? OR supplier_article LIKE ? OR marketplace_product_id LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Создать минимальную запись из данных вопроса/отзыва
     */
    public function createFromQuestionData(int $userId, array $productDetails): void
    {
        $nmId = $productDetails['nmId'] ?? $productDetails['marketplace_product_id'] ?? null;
        if (!$nmId) {
            return;
        }

        // Проверить существует ли уже
        $existing = $this->getByNmId((string)$nmId);
        if ($existing) {
            return;
        }

        // Создать минимальную запись
        $this->saveProductCard($userId, [
            'nmID' => $nmId,
            'vendorCode' => $productDetails['supplierArticle'] ?? $productDetails['product_article'] ?? null,
            'title' => $productDetails['productName'] ?? $productDetails['product_name'] ?? null
        ]);
    }

    /**
     * Конвертировать ISO 8601 дату в MySQL datetime формат
     */
    private function convertIsoDate(?string $isoDate): ?string
    {
        if (empty($isoDate)) {
            return null;
        }

        try {
            $dt = new DateTime($isoDate);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}
