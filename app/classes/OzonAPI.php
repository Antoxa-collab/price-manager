<?php
/**
 * Класс для работы с API Ozon Seller
 * Документация: https://docs.ozon.ru/api/seller/
 *
 * Обновление цен и остатков на маркетплейсе
 */
class OzonAPI
{
    private Database $db;
    private string $clientId = '';
    private string $apiKey = '';
    private string $warehouseId = '';
    private int $userId;

    /**
     * Базовый URL API
     */
    private const BASE_URL = 'https://api-seller.ozon.ru';

    /**
     * Конструктор
     * @param int $userId ID пользователя для загрузки настроек
     */
    public function __construct(int $userId)
    {
        $this->db = Database::getInstance();
        $this->userId = $userId;
        $this->loadSettings();
    }

    /**
     * Загрузка настроек API из базы данных
     */
    private function loadSettings(): void
    {
        $settings = $this->db->fetchOne(
            "SELECT client_id, api_key, warehouse_id FROM api_settings WHERE user_id = ? AND platform = 'ozon' AND is_active = 1",
            [$this->userId]
        );

        if ($settings) {
            $this->clientId = $settings['client_id'] ?? '';
            $this->apiKey = $settings['api_key'] ?? '';
            $this->warehouseId = $settings['warehouse_id'] ?? '';
        }
    }

    /**
     * Проверка наличия настроек
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->apiKey);
    }

    /**
     * Выполнение HTTP запроса к API Ozon
     *
     * @param string $method HTTP метод (GET, POST)
     * @param string $endpoint Эндпоинт API (например /v1/seller/info)
     * @param array $data Данные запроса (будут преобразованы в JSON)
     * @return array Декодированный ответ API
     * @throws Exception При ошибках соединения или API
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('API настройки Ozon не заполнены');
        }

        $url = self::BASE_URL . $endpoint;

        // Заголовки согласно документации Ozon
        $headers = [
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        // Формируем тело запроса
        $jsonBody = '';
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            // ВАЖНО: Для пустого массива отправляем строку "{}"
            // Это требование Ozon API - пустой объект, не пустой массив
            if (empty($data)) {
                $jsonBody = '{}';
            } else {
                $jsonBody = json_encode($data, JSON_UNESCAPED_UNICODE);

                // Проверяем успешность кодирования
                if ($jsonBody === false) {
                    throw new Exception('Ошибка кодирования JSON: ' . json_last_error_msg());
                }
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        // Логируем запрос (debug уровень)
        ErrorLogger::debug('Ozon API запрос', [
            'method' => $method,
            'endpoint' => $endpoint,
            'data_keys' => array_keys($data),
            'http_code' => $httpCode
        ]);

        // Ошибка cURL (сетевая проблема)
        if ($curlErrno !== 0) {
            ErrorLogger::apiError('Ozon', $endpoint, 'CURL error: ' . $curlError, [
                'curl_errno' => $curlErrno
            ]);
            throw new Exception('Ошибка соединения с Ozon API: ' . $curlError);
        }

        // Пустой ответ
        if ($response === '' || $response === false) {
            if ($httpCode >= 200 && $httpCode < 300) {
                // Успешный запрос с пустым телом - это нормально для некоторых методов
                return [];
            }
            ErrorLogger::apiError('Ozon', $endpoint, 'Пустой ответ', [
                'http_code' => $httpCode
            ]);
            throw new Exception('Ozon API вернул пустой ответ (HTTP ' . $httpCode . ')');
        }

        // Декодируем JSON ответ
        $result = json_decode($response, true);

        // Проверяем ошибку парсинга JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            ErrorLogger::apiError('Ozon', $endpoint, 'Invalid JSON', [
                'json_error' => json_last_error_msg(),
                'response' => mb_substr($response, 0, 500)
            ]);
            throw new Exception('Ozon API вернул некорректный JSON: ' . json_last_error_msg() . '. Ответ: ' . substr($response, 0, 200));
        }

        // HTTP ошибки (4xx, 5xx)
        if ($httpCode >= 400) {
            $errorMessage = $this->parseOzonError($result, $response);

            ErrorLogger::apiError('Ozon', $endpoint, $errorMessage, [
                'http_code' => $httpCode,
                'response' => mb_substr($response, 0, 500)
            ]);

            throw new Exception('Ошибка Ozon API: ' . $errorMessage);
        }

        // Успешный ответ
        ErrorLogger::debug('Ozon API ответ OK', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode
        ]);

        return $result ?? [];
    }

    /**
     * Проверка подключения к Ozon API
     * Возвращает HTTP код и детальную информацию
     *
     * @return array Результат проверки с http_code
     */
    public function testConnection(): array
    {
        if (empty($this->clientId) || empty($this->apiKey)) {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'Client-Id или Api-Key не указаны',
                'error_type' => 'config'
            ];
        }

        $url = self::BASE_URL . '/v1/seller/info';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Client-Id: ' . $this->clientId,
                'Api-Key: ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Логируем результат
        ErrorLogger::info('Ozon API testConnection', [
            'http_code' => $httpCode,
            'response_length' => strlen($response),
            'curl_error' => $curlError
        ]);

        // Ошибка CURL
        if ($curlError) {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'Ошибка соединения: ' . $curlError,
                'error_type' => 'network'
            ];
        }

        // Парсим ответ
        $data = json_decode($response, true);

        // HTTP 200 — успех
        if ($httpCode === 200) {
            $companyName = $data['result']['company']['name'] ?? 'Неизвестно';
            $inn = $data['result']['company']['inn'] ?? '';

            ErrorLogger::apiSuccess('Ozon', 'Подключение успешно', [
                'company' => $companyName
            ]);

            return [
                'success' => true,
                'http_code' => 200,
                'message' => "Подключено! Компания: {$companyName}" . ($inn ? " (ИНН: {$inn})" : ''),
                'company' => $data['result']['company'] ?? null,
                'data' => [
                    'company_name' => $companyName,
                    'company' => $data['result']['company'] ?? []
                ]
            ];
        }

        // HTTP ошибки
        $errorMessage = $data['message'] ?? "HTTP ошибка {$httpCode}";

        ErrorLogger::apiError('Ozon', '/v1/seller/info', $errorMessage, [
            'http_code' => $httpCode
        ]);

        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => $errorMessage,
            'error_type' => $httpCode === 401 || $httpCode === 403 ? 'auth' : 'api',
            'raw_response' => substr($response, 0, 500)
        ];
    }

    /**
     * Получение списка складов
     * Метод: POST /v1/warehouse/list
     * ВАЖНО: limit — обязательный параметр (не может быть 0)
     *
     * @return array Список складов
     */
    public function getWarehouses(): array
    {
        try {
            // limit обязательный параметр, максимум 200
            $result = $this->request('POST', '/v1/warehouse/list', [
                'limit' => 200,
                'offset' => 0
            ]);

            return [
                'success' => true,
                'message' => 'Склады получены',
                'warehouses' => $result['result'] ?? [],
                'data' => $result['result'] ?? $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'warehouses' => [],
                'data' => []
            ];
        }
    }

    /**
     * Обновление цен товаров
     * Метод: POST /v1/product/import/prices
     *
     * @param array $prices Массив цен [['product_id' => int, 'price' => float, 'old_price' => float], ...]
     * @return array Результат операции
     */
    public function updatePrices(array $prices): array
    {
        if (empty($prices)) {
            return ['success' => false, 'message' => 'Список цен пуст'];
        }

        // Формируем данные для API согласно документации
        $data = [
            'prices' => []
        ];

        foreach ($prices as $item) {
            $priceData = [
                'product_id' => (int)$item['product_id'],
                'price' => (string)round($item['price'], 2)
            ];

            // Если указана старая цена (для зачёркивания)
            if (!empty($item['old_price'])) {
                $priceData['old_price'] = (string)round($item['old_price'], 2);
            }

            $data['prices'][] = $priceData;
        }

        try {
            $result = $this->request('POST', '/v1/product/import/prices', $data);

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('ozon_update_prices', 'api', null, null, [
                'prices_count' => count($prices),
                'prices' => $prices
            ]);

            return [
                'success' => true,
                'message' => 'Цены успешно обновлены',
                'data' => $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Обновление остатков товаров
     * Метод: POST /v2/products/stocks
     *
     * ВАЖНО: Ozon API ограничение — 1 обновление на товар-склад раз в 30 секунд.
     * Лимит на запрос — 100 товаров.
     *
     * @param array $stocks Массив остатков [['offer_id' => string, 'stock' => int], ...]
     * @param int|null $warehouseId ID склада (если null — автоопределение)
     * @return array Результат операции
     */
    public function updateStocks(array $stocks, ?int $warehouseId = null): array
    {
        if (empty($stocks)) {
            return ['success' => false, 'error' => 'Пустой массив остатков'];
        }

        try {
            // Получаем warehouse_id если не передан
            if (!$warehouseId) {
                $warehouseId = !empty($this->warehouseId) ? (int)$this->warehouseId : $this->fetchWarehouseId();
            }

            if (!$warehouseId) {
                return [
                    'success' => false,
                    'error' => 'Все склады отключены (disabled). Активируйте хотя бы один склад FBS в личном кабинете Ozon Seller → Настройки → Склады.'
                ];
            }

            // Формируем данные для API
            $stocksData = [];
            foreach ($stocks as $item) {
                $stockItem = [
                    'stock' => max(0, (int)($item['stock'] ?? 0)),
                    'warehouse_id' => $warehouseId
                ];

                // Приоритет: offer_id > product_id
                if (!empty($item['offer_id'])) {
                    $stockItem['offer_id'] = (string)$item['offer_id'];
                } elseif (!empty($item['product_id'])) {
                    $stockItem['product_id'] = (int)$item['product_id'];
                } else {
                    continue;
                }

                $stocksData[] = $stockItem;
            }

            if (empty($stocksData)) {
                return ['success' => false, 'error' => 'Нет товаров для обновления'];
            }

            // Разбиваем на батчи по 100 (лимит Ozon API)
            $batches = array_chunk($stocksData, 100);
            $totalUpdated = 0;
            $allErrors = [];

            foreach ($batches as $batchIndex => $batch) {
                error_log("Ozon: Отправляем батч " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " товаров)");

                $response = $this->request('POST', '/v2/products/stocks', [
                    'stocks' => $batch
                ]);

                if (isset($response['result']) && is_array($response['result'])) {
                    foreach ($response['result'] as $result) {
                        if (!empty($result['errors'])) {
                            $offerId = $result['offer_id'] ?? $result['product_id'] ?? 'unknown';
                            foreach ($result['errors'] as $err) {
                                $errorCode = $err['code'] ?? '';
                                $errorMsg = $err['message'] ?? json_encode($err);

                                // Переводим известные ошибки на русский
                                if ($errorCode === 'TOO_MANY_REQUESTS' || stripos($errorMsg, 'too frequently') !== false) {
                                    $allErrors[] = "{$offerId}: Слишком частое обновление (подождите 30 сек)";
                                } elseif (stripos($errorMsg, 'warehouse') !== false || stripos($errorMsg, 'склад') !== false) {
                                    $allErrors[] = "{$offerId}: Проблема со складом — проверьте статус в Ozon Seller";
                                } else {
                                    $allErrors[] = "{$offerId}: {$errorMsg}";
                                }
                            }
                        } elseif ($result['updated'] ?? false) {
                            $totalUpdated++;
                        }
                    }
                } elseif (isset($response['code']) || isset($response['message'])) {
                    // Общая ошибка API
                    $allErrors[] = $response['message'] ?? 'Ошибка API: ' . ($response['code'] ?? 'unknown');
                }
            }

            // Логируем операцию
            try {
                $log = new OperationsLog();
                $log->add('ozon_update_stocks', 'api', null, null, [
                    'warehouse_id' => $warehouseId,
                    'stocks_count' => count($stocksData),
                    'batches' => count($batches),
                    'updated' => $totalUpdated,
                    'errors_count' => count($allErrors)
                ]);
            } catch (Exception $e) {
                // Игнорируем ошибки логирования
            }

            return [
                'success' => $totalUpdated > 0 || empty($allErrors),
                'updated' => $totalUpdated,
                'total' => count($stocksData),
                'errors' => $allErrors,
                'message' => $totalUpdated > 0 ? "Обновлено {$totalUpdated} остатков" : 'Остатки не обновлены',
                'warehouse_id' => $warehouseId
            ];

        } catch (Exception $e) {
            error_log("Ozon: Ошибка updateStocks: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'warehouse_id' => $warehouseId ?? null
            ];
        }
    }

    /**
     * Получение ID первого доступного склада FBS
     * Приоритет: enabled/working → created → первый не-disabled
     * @return int|null
     */
    private function fetchWarehouseId(): ?int
    {
        try {
            $result = $this->request('POST', '/v1/warehouse/list', [
                'limit' => 200,
                'offset' => 0
            ]);

            if (!isset($result['result']) || empty($result['result'])) {
                error_log("Ozon: Склады не найдены");
                return null;
            }

            $warehouses = $result['result'];

            // Приоритет 1: enabled или working (полностью активные склады)
            foreach ($warehouses as $wh) {
                $status = $wh['status'] ?? '';
                if (in_array($status, ['enabled', 'working'])) {
                    $warehouseId = (int)$wh['warehouse_id'];
                    error_log("Ozon: Найден активный склад: {$wh['name']} (ID: {$warehouseId}, статус: {$status})");
                    return $warehouseId;
                }
            }

            // Приоритет 2: created (настроен но не полностью активен)
            foreach ($warehouses as $wh) {
                $status = $wh['status'] ?? '';
                if ($status === 'created') {
                    $warehouseId = (int)$wh['warehouse_id'];
                    error_log("Ozon: Используем склад в статусе created: {$wh['name']} (ID: {$warehouseId})");
                    return $warehouseId;
                }
            }

            // Приоритет 3: любой не-disabled (на всякий случай)
            foreach ($warehouses as $wh) {
                $status = $wh['status'] ?? '';
                if ($status !== 'disabled' && $status !== 'archived') {
                    $warehouseId = (int)$wh['warehouse_id'];
                    error_log("Ozon: Используем первый не-disabled склад: {$wh['name']} (ID: {$warehouseId}, статус: {$status})");
                    return $warehouseId;
                }
            }

            // Ничего не нашли — все склады disabled
            error_log("Ozon: ВСЕ СКЛАДЫ ОТКЛЮЧЕНЫ! Активируйте хотя бы один склад в Ozon Seller.");
            return null;

        } catch (Exception $e) {
            error_log("Ozon: Ошибка fetchWarehouseId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Проверяет статус конкретного склада
     * @param int $warehouseId ID склада
     * @return array
     */
    public function checkWarehouseStatus(int $warehouseId): array
    {
        try {
            $result = $this->request('POST', '/v1/warehouse/list', [
                'limit' => 200,
                'offset' => 0
            ]);

            if (isset($result['result'])) {
                foreach ($result['result'] as $warehouse) {
                    if ((int)$warehouse['warehouse_id'] === $warehouseId) {
                        $status = $warehouse['status'] ?? 'unknown';
                        $name = $warehouse['name'] ?? 'Без названия';
                        $isActive = in_array($status, ['enabled', 'working', 'created']);

                        return [
                            'found' => true,
                            'active' => $isActive,
                            'status' => $status,
                            'name' => $name,
                            'warehouse_id' => $warehouseId
                        ];
                    }
                }
            }

            return [
                'found' => false,
                'active' => false,
                'error' => "Склад {$warehouseId} не найден в списке складов"
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'active' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Обнуление остатков товара
     *
     * @param int $productId ID товара
     * @return array Результат операции
     */
    public function zeroStock(int $productId): array
    {
        return $this->updateStocks([
            ['product_id' => $productId, 'stock' => 0]
        ]);
    }

    /**
     * Получение информации о товаре
     * Метод: POST /v2/product/info
     *
     * @param int $productId ID товара
     * @return array Информация о товаре
     */
    public function getProductInfo(int $productId): array
    {
        try {
            $result = $this->request('POST', '/v2/product/info', [
                'product_id' => $productId
            ]);

            return [
                'success' => true,
                'data' => $result['result'] ?? $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Получение списка товаров
     * Метод: POST /v3/product/list (согласно актуальной документации)
     *
     * @param int $limit Лимит (макс. 1000)
     * @param string $lastId Последний ID для пагинации
     * @return array Список товаров
     */
    public function getProducts(int $limit = 100, string $lastId = ''): array
    {
        try {
            // Формируем запрос согласно документации v3
            $data = [
                'limit' => min($limit, 1000)
            ];

            // filter - обязательный параметр, но может быть пустым объектом
            // Используем пустой массив, который при json_encode станет {}
            // если нет фильтров
            $data['filter'] = new \stdClass();

            if (!empty($lastId)) {
                $data['last_id'] = $lastId;
            }

            $result = $this->request('POST', '/v3/product/list', $data);

            return [
                'success' => true,
                'data' => $result['result'] ?? []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * Получение полного списка товаров с информацией о ценах
     * Использует пагинацию для получения всех товаров
     * ВАЖНО: Использует /v3/product/list + /v3/product/info/list согласно документации
     *
     * @param callable|null $progressCallback Callback для прогресса (count, total, page)
     * @return array Массив товаров с ценами
     */
    public function getProductsList(?callable $progressCallback = null): array
    {
        $allProducts = [];
        $lastId = '';
        $page = 0;
        $limit = 1000;

        do {
            $page++;

            // Шаг 1: Получаем список ID товаров через /v3/product/list
            $listResponse = $this->request('POST', '/v3/product/list', [
                'filter' => [
                    'visibility' => 'ALL'
                ],
                'limit' => $limit,
                'last_id' => $lastId
            ]);

            $items = $listResponse['result']['items'] ?? [];
            $total = $listResponse['result']['total'] ?? 0;
            $lastId = $listResponse['result']['last_id'] ?? '';

            if (empty($items)) {
                break;
            }

            // Собираем offer_id (приоритет) или product_id
            $offerIds = [];
            $productIds = [];

            foreach ($items as $item) {
                if (!empty($item['offer_id'])) {
                    $offerIds[] = $item['offer_id'];
                }
                if (!empty($item['product_id'])) {
                    $productIds[] = (string)$item['product_id'];
                }
            }

            // Шаг 2: Получаем детальную информацию через /v3/product/info/list
            // ВАЖНО: API требует использовать ТОЛЬКО ОДИН параметр (offer_id ИЛИ product_id ИЛИ sku)
            if (!empty($offerIds)) {
                $infoResponse = $this->request('POST', '/v3/product/info/list', [
                    'offer_id' => $offerIds
                ]);
            } elseif (!empty($productIds)) {
                $infoResponse = $this->request('POST', '/v3/product/info/list', [
                    'product_id' => $productIds
                ]);
            } else {
                continue;
            }

            if (empty($infoResponse)) {
                continue;
            }

            $detailedItems = $infoResponse['result']['items'] ?? $infoResponse['items'] ?? [];

            foreach ($detailedItems as $item) {
                // Извлекаем остатки
                $stock = 0;
                if (isset($item['stocks']['stocks']) && is_array($item['stocks']['stocks'])) {
                    foreach ($item['stocks']['stocks'] as $stockItem) {
                        $stock += (int)($stockItem['present'] ?? 0);
                    }
                }

                $allProducts[] = [
                    'product_id' => (string)($item['id'] ?? $item['product_id'] ?? ''),
                    'offer_id' => $item['offer_id'] ?? '',
                    'sku' => (string)($item['sku'] ?? ''),
                    'name' => $item['name'] ?? '',
                    'price' => (float)($item['price'] ?? 0),
                    'min_price' => (float)($item['min_price'] ?? 0),
                    'old_price' => (float)($item['old_price'] ?? 0),
                    'stock' => $stock,
                    'visible' => !($item['is_archived'] ?? false),
                    'barcode' => $item['barcodes'][0] ?? '',
                    'category_id' => $item['description_category_id'] ?? null,
                    'raw_data' => $item
                ];
            }

            // Callback для прогресса
            if ($progressCallback) {
                $progressCallback(count($allProducts), $total, $page);
            }

            // Защита от бесконечного цикла
            if ($page > 100) {
                ErrorLogger::warning('Ozon API: превышен лимит страниц (100)');
                break;
            }

        } while (!empty($lastId) && count($items) === $limit);

        ErrorLogger::info('Ozon API: получено товаров', [
            'total' => count($allProducts),
            'pages' => $page
        ]);

        return $allProducts;
    }

    /**
     * Получение всех товаров с Ozon (алиас для getProductsList)
     *
     * @param callable|null $progressCallback Callback для прогресса
     * @return array ['products' => [], 'total' => int]
     */
    public function getAllProducts(?callable $progressCallback = null): array
    {
        $products = $this->getProductsList($progressCallback);

        return [
            'products' => $products,
            'total' => count($products)
        ];
    }

    /**
     * Получение информации о ценах товаров
     * Метод: POST /v4/product/info/prices
     *
     * @param array $productIds Массив ID товаров
     * @return array
     */
    public function getProductsPriceInfo(array $productIds): array
    {
        try {
            $data = [
                'filter' => [
                    'product_id' => array_map('intval', $productIds),
                    'visibility' => 'ALL'
                ],
                'limit' => 1000
            ];

            $result = $this->request('POST', '/v4/product/info/prices', $data);

            $products = [];
            foreach ($result['result']['items'] ?? [] as $item) {
                $products[] = [
                    'product_id' => $item['product_id'],
                    'offer_id' => $item['offer_id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'price' => $item['price']['price'] ?? 0,
                    'min_price' => $item['price']['min_price'] ?? 0,
                    'old_price' => $item['price']['old_price'] ?? 0,
                    'marketing_price' => $item['price']['marketing_price'] ?? 0,
                    'currency_code' => $item['price']['currency_code'] ?? 'RUB',
                    'visible' => ($item['visibility_details']['visibility'] ?? '') === 'VISIBLE'
                ];
            }

            return [
                'success' => true,
                'data' => $products
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * Обновление цен с минимальной ценой
     * Метод: POST /v1/product/import/prices
     *
     * @param array $prices Массив [['product_id' => X, 'price' => Y, 'min_price' => Z, 'old_price' => W], ...]
     * @return array Результат
     */
    public function updatePricesWithMinPrice(array $prices): array
    {
        if (empty($prices)) {
            return ['success' => false, 'message' => 'Список цен пуст'];
        }

        $data = ['prices' => []];

        foreach ($prices as $item) {
            $priceData = [
                'product_id' => (int)$item['product_id'],
                'price' => (string)round($item['price'], 2)
            ];

            // Минимальная цена
            if (isset($item['min_price']) && $item['min_price'] > 0) {
                $priceData['min_price'] = (string)round($item['min_price'], 2);
            }

            // Старая цена (зачёркнутая)
            if (isset($item['old_price']) && $item['old_price'] > 0) {
                $priceData['old_price'] = (string)round($item['old_price'], 2);
            }

            $data['prices'][] = $priceData;
        }

        try {
            $result = $this->request('POST', '/v1/product/import/prices', $data);

            // Сохраняем историю загрузки
            $this->savePriceUploadHistory($prices, 'success');

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('ozon_update_prices_with_min', 'api', null, null, [
                'prices_count' => count($prices),
                'prices' => array_slice($prices, 0, 10) // Сохраняем первые 10 для логирования
            ]);

            return [
                'success' => true,
                'message' => 'Цены успешно обновлены (' . count($prices) . ' товаров)',
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->savePriceUploadHistory($prices, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Сохранение истории загрузки цен
     *
     * @param array $prices Загруженные цены
     * @param string $status Статус (success, error)
     * @param string|null $errorMessage Сообщение об ошибке
     */
    private function savePriceUploadHistory(array $prices, string $status, ?string $errorMessage = null): void
    {
        foreach ($prices as $price) {
            $this->db->insert('price_upload_history', [
                'user_id' => $this->userId,
                'marketplace' => 'ozon',
                'product_id' => $price['our_product_id'] ?? null,
                'mapping_id' => $price['mapping_id'] ?? null,
                'marketplace_product_id' => (string)$price['product_id'],
                'old_price' => $price['old_mp_price'] ?? null,
                'new_price' => $price['price'] ?? null,
                'old_min_price' => $price['old_mp_min_price'] ?? null,
                'new_min_price' => $price['min_price'] ?? null,
                'status' => $status,
                'error_message' => $errorMessage
            ]);
        }
    }

    /**
     * Сохранение настроек API
     *
     * @param string $clientId Client ID
     * @param string $apiKey API ключ
     * @param string $warehouseId ID склада
     * @return bool
     */
    public function saveSettings(string $clientId, string $apiKey, string $warehouseId): bool
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM api_settings WHERE user_id = ? AND platform = 'ozon'",
            [$this->userId]
        );

        $data = [
            'user_id' => $this->userId,
            'platform' => 'ozon',
            'client_id' => $clientId,
            'api_key' => $apiKey,
            'warehouse_id' => $warehouseId,
            'is_active' => 1
        ];

        if ($existing) {
            $this->db->update('api_settings', $data, 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('api_settings', $data);
        }

        // Обновляем текущие настройки
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->warehouseId = $warehouseId;

        // Логируем изменение
        $log = new OperationsLog();
        $log->add('update_settings', 'api_settings', null, null, [
            'platform' => 'ozon',
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId
        ]);

        return true;
    }

    /**
     * Парсинг ошибки Ozon API
     *
     * @param array|null $result Декодированный ответ
     * @param string $rawResponse Сырой ответ
     * @return string Текст ошибки
     */
    private function parseOzonError(?array $result, string $rawResponse): string
    {
        if ($result === null) {
            return 'Некорректный ответ API';
        }

        // Пробуем разные поля с ошибкой
        if (isset($result['message'])) {
            return $result['message'];
        }

        if (isset($result['error'])) {
            if (is_array($result['error'])) {
                return json_encode($result['error'], JSON_UNESCAPED_UNICODE);
            }
            return $result['error'];
        }

        if (isset($result['error_description'])) {
            return $result['error_description'];
        }

        // Проверяем вложенные ошибки
        if (isset($result['result']['errors']) && is_array($result['result']['errors'])) {
            $errors = [];
            foreach ($result['result']['errors'] as $error) {
                $errors[] = $error['message'] ?? $error['code'] ?? json_encode($error);
            }
            return implode('; ', $errors);
        }

        return 'Неизвестная ошибка (см. логи)';
    }
}
