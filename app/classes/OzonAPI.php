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
            $msg = $result['message'];

            // Человекочитаемое сообщение для ошибки Premium Plus
            if (stripos($msg, 'Premium Plus') !== false || stripos($msg, 'PermissionDenied') !== false) {
                return 'Требуется подписка Premium Plus или Premium Pro для доступа к API отзывов и вопросов';
            }

            return $msg;
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

    // ==========================================
    // РАБОТА С ОТЗЫВАМИ (Premium Plus / Premium Pro)
    // ==========================================

    /**
     * Количество отзывов по статусам
     * POST /v1/review/count
     *
     * @return array {success, total, processed, unprocessed}
     */
    public function getReviewsCount(): array
    {
        try {
            // Пустой объект {} для запроса без параметров
            $response = $this->request('POST', '/v1/review/count', []);

            return [
                'success' => true,
                'total' => (int)($response['total'] ?? 0),
                'processed' => (int)($response['processed'] ?? 0),
                'unprocessed' => (int)($response['unprocessed'] ?? 0)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'total' => 0,
                'processed' => 0,
                'unprocessed' => 0
            ];
        }
    }

    /**
     * Получить список отзывов
     * POST /v1/review/list
     *
     * Пример запроса:
     * {
     *   "last_id": "",
     *   "limit": 100,
     *   "sort_dir": "ASC",
     *   "status": "ALL"
     * }
     *
     * Пример ответа:
     * {
     *   "has_next": true,
     *   "last_id": "string",
     *   "reviews": [{
     *     "comments_amount": 0,
     *     "id": "string",
     *     "is_rating_participant": true,
     *     "order_status": "DELIVERED",
     *     "photos_amount": 0,
     *     "published_at": "2019-08-24T14:15:22Z",
     *     "rating": 5,
     *     "sku": 123456,
     *     "status": "UNPROCESSED",
     *     "text": "Отзыв покупателя",
     *     "videos_amount": 0
     *   }]
     * }
     *
     * @param int $limit Количество (20-100)
     * @param string $lastId ID последнего отзыва для пагинации (пустая строка для первой страницы)
     * @param string $status ALL|UNPROCESSED|PROCESSED
     * @param string $sortDir ASC|DESC
     * @return array
     */
    public function getReviews(int $limit = 100, string $lastId = '', string $status = 'ALL', string $sortDir = 'DESC'): array
    {
        $body = [
            'limit' => min(max($limit, 20), 100),
            'last_id' => $lastId,
            'sort_dir' => $sortDir,
            'status' => $status
        ];

        try {
            $response = $this->request('POST', '/v1/review/list', $body);

            $reviews = [];
            foreach ($response['reviews'] ?? [] as $review) {
                $reviews[] = [
                    'marketplace_review_id' => (string)$review['id'],
                    'sku' => (int)($review['sku'] ?? 0),
                    'rating' => (int)($review['rating'] ?? 5),
                    'review_text' => $review['text'] ?? '',
                    'review_date' => $review['published_at'] ?? date('Y-m-d H:i:s'),
                    'status' => $review['status'] ?? 'UNPROCESSED',
                    'order_status' => $review['order_status'] ?? '',
                    'comments_amount' => (int)($review['comments_amount'] ?? 0),
                    'photos_amount' => (int)($review['photos_amount'] ?? 0),
                    'videos_amount' => (int)($review['videos_amount'] ?? 0),
                    'is_rating_participant' => (bool)($review['is_rating_participant'] ?? true)
                ];
            }

            return [
                'success' => true,
                'reviews' => $reviews,
                'has_next' => (bool)($response['has_next'] ?? false),
                'last_id' => $response['last_id'] ?? ''
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'reviews' => [],
                'has_next' => false,
                'last_id' => ''
            ];
        }
    }

    /**
     * Получить ВСЕ отзывы с автоматической пагинацией
     *
     * @param int $maxPages Максимум страниц (защита от бесконечного цикла)
     * @param string $status ALL|UNPROCESSED|PROCESSED
     * @return array
     */
    public function getAllReviews(int $maxPages = 20, string $status = 'ALL'): array
    {
        $allReviews = [];
        $lastId = '';
        $page = 0;

        do {
            $result = $this->getReviews(100, $lastId, $status);

            if (!$result['success']) {
                // Если первая страница — возвращаем ошибку
                if ($page === 0) {
                    return $result;
                }
                // Иначе возвращаем что успели получить
                break;
            }

            $allReviews = array_merge($allReviews, $result['reviews']);
            $lastId = $result['last_id'];
            $page++;

            // Задержка между запросами (защита от rate limit)
            if ($result['has_next'] && !empty($lastId)) {
                usleep(300000); // 300ms
            }

        } while ($result['has_next'] && $page < $maxPages && !empty($lastId));

        return [
            'success' => true,
            'reviews' => $allReviews,
            'total' => count($allReviews),
            'pages_loaded' => $page
        ];
    }

    /**
     * Получить информацию об одном отзыве
     * POST /v1/review/info
     *
     * @param string $reviewId
     * @return array
     */
    public function getReviewInfo(string $reviewId): array
    {
        try {
            $response = $this->request('POST', '/v1/review/info', [
                'review_id' => $reviewId
            ]);

            return [
                'success' => true,
                'review' => [
                    'id' => $response['id'] ?? $reviewId,
                    'sku' => (int)($response['sku'] ?? 0),
                    'rating' => (int)($response['rating'] ?? 5),
                    'text' => $response['text'] ?? '',
                    'published_at' => $response['published_at'] ?? null,
                    'status' => $response['status'] ?? 'UNPROCESSED',
                    'order_status' => $response['order_status'] ?? '',
                    'comments_amount' => (int)($response['comments_amount'] ?? 0),
                    'likes_amount' => (int)($response['likes_amount'] ?? 0),
                    'dislikes_amount' => (int)($response['dislikes_amount'] ?? 0),
                    'photos_amount' => (int)($response['photos_amount'] ?? 0),
                    'videos_amount' => (int)($response['videos_amount'] ?? 0),
                    'photos' => $response['photos'] ?? [],
                    'videos' => $response['videos'] ?? [],
                    'is_rating_participant' => (bool)($response['is_rating_participant'] ?? true)
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Оставить комментарий (ответ) на отзыв
     * POST /v1/review/comment/create
     *
     * Пример запроса:
     * {
     *   "mark_review_as_processed": true,
     *   "parent_comment_id": "string",  // опционально
     *   "review_id": "string",
     *   "text": "string"
     * }
     *
     * @param string $reviewId ID отзыва
     * @param string $text Текст комментария
     * @param bool $markAsProcessed Отметить отзыв как обработанный
     * @param string|null $parentCommentId ID родительского комментария (для ответа на комментарий)
     * @return array
     */
    public function replyToReview(string $reviewId, string $text, bool $markAsProcessed = true, ?string $parentCommentId = null): array
    {
        $body = [
            'review_id' => $reviewId,
            'text' => $text,
            'mark_review_as_processed' => $markAsProcessed
        ];

        if ($parentCommentId !== null && $parentCommentId !== '') {
            $body['parent_comment_id'] = $parentCommentId;
        }

        try {
            $response = $this->request('POST', '/v1/review/comment/create', $body);

            return [
                'success' => true,
                'comment_id' => $response['comment_id'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Удалить комментарий на отзыв
     * POST /v1/review/comment/delete
     *
     * @param string $commentId ID комментария
     * @return array
     */
    public function deleteReviewComment(string $commentId): array
    {
        try {
            $this->request('POST', '/v1/review/comment/delete', [
                'comment_id' => $commentId
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Получить список комментариев на отзыв
     * POST /v1/review/comment/list
     *
     * ВНИМАНИЕ: Этот метод использует offset, а не last_id!
     *
     * @param string $reviewId ID отзыва
     * @param int $limit Лимит (20-100)
     * @param int $offset Смещение
     * @param string $sortDir ASC|DESC
     * @return array
     */
    public function getReviewComments(string $reviewId, int $limit = 100, int $offset = 0, string $sortDir = 'ASC'): array
    {
        try {
            $response = $this->request('POST', '/v1/review/comment/list', [
                'review_id' => $reviewId,
                'limit' => min(max($limit, 20), 100),
                'offset' => $offset,
                'sort_dir' => $sortDir
            ]);

            return [
                'success' => true,
                'comments' => $response['comments'] ?? [],
                'offset' => (int)($response['offset'] ?? 0)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'comments' => []
            ];
        }
    }

    /**
     * Изменить статус отзывов
     * POST /v1/review/change-status
     *
     * @param array $reviewIds Массив ID отзывов (1-100)
     * @param string $status PROCESSED|UNPROCESSED
     * @return array
     */
    public function changeReviewsStatus(array $reviewIds, string $status): array
    {
        if (empty($reviewIds)) {
            return ['success' => false, 'error' => 'Не указаны ID отзывов'];
        }

        try {
            $this->request('POST', '/v1/review/change-status', [
                'review_ids' => array_slice($reviewIds, 0, 100),
                'status' => $status
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // ==========================================
    // РАБОТА С ВОПРОСАМИ (Premium Plus)
    // ==========================================

    /**
     * Количество вопросов по статусам
     * POST /v1/question/count
     *
     * Пример ответа:
     * {
     *   "all": 10,
     *   "new": 3,
     *   "processed": 4,
     *   "unprocessed": 1,
     *   "viewed": 1
     * }
     *
     * @return array
     */
    public function getQuestionsCount(): array
    {
        try {
            // Пустой объект для запроса без параметров
            $response = $this->request('POST', '/v1/question/count', []);

            return [
                'success' => true,
                'all' => (int)($response['all'] ?? 0),
                'new' => (int)($response['new'] ?? 0),
                'viewed' => (int)($response['viewed'] ?? 0),
                'processed' => (int)($response['processed'] ?? 0),
                'unprocessed' => (int)($response['unprocessed'] ?? 0)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'all' => 0
            ];
        }
    }

    /**
     * Получить список вопросов
     * POST /v1/question/list
     *
     * ВАЖНО: Возвращает до 10 вопросов за раз!
     *
     * Пример запроса:
     * {
     *   "filter": {
     *     "date_from": "2019-08-24T14:15:22Z",
     *     "date_to": "2019-08-24T14:15:22Z",
     *     "status": "ALL"
     *   },
     *   "last_id": ""
     * }
     *
     * Пример ответа:
     * {
     *   "questions": [{
     *     "answers_count": 1,
     *     "author_name": "Пользователь OZON",
     *     "id": "019294ff-6888-7009-89d8-26569e4e450d",
     *     "sku": 646399170,
     *     "product_url": "https://www.ozon.ru/product/1649246352/",
     *     "published_at": "2024-08-14T12:02:01.889Z",
     *     "question_link": "https://www.ozon.ru/product/.../questions/...",
     *     "text": "Новый вопрос о товаре",
     *     "status": "PROCESSED"
     *   }],
     *   "last_id": "019228a7-91d8-76af-a73a-e989dfac7ac8"
     * }
     *
     * @param string $lastId ID последнего вопроса для пагинации
     * @param string $status ALL|NEW|VIEWED|PROCESSED|UNPROCESSED
     * @param string|null $dateFrom Дата от (ISO 8601: 2019-08-24T14:15:22Z)
     * @param string|null $dateTo Дата до (ISO 8601)
     * @return array
     */
    public function getQuestions(string $lastId = '', string $status = 'ALL', ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $filter = ['status' => $status];

        if ($dateFrom) {
            $filter['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $filter['date_to'] = $dateTo;
        }

        $body = [
            'filter' => $filter,
            'last_id' => $lastId
        ];

        try {
            $response = $this->request('POST', '/v1/question/list', $body);

            $questions = [];
            foreach ($response['questions'] ?? [] as $q) {
                $questions[] = [
                    'marketplace_question_id' => (string)$q['id'],
                    'sku' => (int)($q['sku'] ?? 0),
                    'author_name' => $q['author_name'] ?? 'Пользователь OZON',
                    'question_text' => $q['text'] ?? '',
                    'question_date' => $q['published_at'] ?? date('Y-m-d H:i:s'),
                    'status' => $q['status'] ?? 'NEW',
                    'answers_count' => (int)($q['answers_count'] ?? 0),
                    'product_url' => $q['product_url'] ?? '',
                    'question_link' => $q['question_link'] ?? ''
                ];
            }

            // has_next определяем по наличию last_id и количеству вопросов
            $hasNext = !empty($response['last_id']) && count($questions) > 0;

            return [
                'success' => true,
                'questions' => $questions,
                'has_next' => $hasNext,
                'last_id' => $response['last_id'] ?? ''
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'questions' => [],
                'has_next' => false,
                'last_id' => ''
            ];
        }
    }

    /**
     * Получить ВСЕ вопросы с автоматической пагинацией
     *
     * ВАЖНО: API возвращает до 10 вопросов за раз!
     * Поэтому maxPages = 100 даст максимум 1000 вопросов
     *
     * @param int $maxPages Максимум страниц
     * @param string $status ALL|NEW|VIEWED|PROCESSED|UNPROCESSED
     * @return array
     */
    public function getAllQuestions(int $maxPages = 100, string $status = 'ALL'): array
    {
        $allQuestions = [];
        $lastId = '';
        $page = 0;

        do {
            $result = $this->getQuestions($lastId, $status);

            if (!$result['success']) {
                if ($page === 0) {
                    return $result;
                }
                break;
            }

            $allQuestions = array_merge($allQuestions, $result['questions']);
            $lastId = $result['last_id'];
            $page++;

            if ($result['has_next'] && !empty($lastId)) {
                usleep(300000); // 300ms
            }

        } while ($result['has_next'] && $page < $maxPages && !empty($lastId));

        return [
            'success' => true,
            'questions' => $allQuestions,
            'total' => count($allQuestions),
            'pages_loaded' => $page
        ];
    }

    /**
     * Получить информацию о вопросе
     * POST /v1/question/info
     *
     * @param string $questionId
     * @return array
     */
    public function getQuestionInfo(string $questionId): array
    {
        try {
            $response = $this->request('POST', '/v1/question/info', [
                'question_id' => $questionId
            ]);

            return [
                'success' => true,
                'question' => [
                    'id' => $response['id'] ?? $questionId,
                    'sku' => (int)($response['sku'] ?? 0),
                    'author_name' => $response['author_name'] ?? 'Пользователь OZON',
                    'text' => $response['text'] ?? '',
                    'published_at' => $response['published_at'] ?? null,
                    'status' => $response['status'] ?? 'NEW',
                    'answers_count' => (int)($response['answers_count'] ?? 0),
                    'product_url' => $response['product_url'] ?? '',
                    'question_link' => $response['question_link'] ?? ''
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Создать ответ на вопрос
     * POST /v1/question/answer/create
     *
     * ВАЖНО:
     * - Требует sku (int64) — ID товара
     * - text должен быть от 2 до 3000 символов
     *
     * Пример запроса:
     * {
     *   "question_id": "string",
     *   "sku": 646399170,
     *   "text": "string"
     * }
     *
     * Пример ответа:
     * {
     *   "answer_id": "0192e7ce-e12c-7a74-afc7-26e877799204"
     * }
     *
     * @param string $questionId ID вопроса
     * @param int $sku SKU товара (ОБЯЗАТЕЛЬНО!)
     * @param string $text Текст ответа (2-3000 символов)
     * @return array
     */
    public function answerQuestion(string $questionId, int $sku, string $text): array
    {
        // Валидация длины текста
        $textLength = mb_strlen($text, 'UTF-8');
        if ($textLength < 2) {
            return [
                'success' => false,
                'error' => 'Текст ответа слишком короткий (минимум 2 символа)'
            ];
        }
        if ($textLength > 3000) {
            return [
                'success' => false,
                'error' => 'Текст ответа слишком длинный (максимум 3000 символов)'
            ];
        }

        if ($sku <= 0) {
            return [
                'success' => false,
                'error' => 'Не указан SKU товара'
            ];
        }

        try {
            $response = $this->request('POST', '/v1/question/answer/create', [
                'question_id' => $questionId,
                'sku' => $sku,
                'text' => $text
            ]);

            return [
                'success' => true,
                'answer_id' => $response['answer_id'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Удалить ответ на вопрос
     * POST /v1/question/answer/delete
     *
     * @param string $answerId ID ответа
     * @param int $sku SKU товара
     * @return array
     */
    public function deleteQuestionAnswer(string $answerId, int $sku): array
    {
        try {
            $this->request('POST', '/v1/question/answer/delete', [
                'answer_id' => $answerId,
                'sku' => $sku
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Получить список ответов на вопрос
     * POST /v1/question/answer/list
     *
     * @param string $questionId ID вопроса
     * @param int $sku SKU товара
     * @param string $lastId ID последнего ответа для пагинации
     * @return array
     */
    public function getQuestionAnswers(string $questionId, int $sku, string $lastId = ''): array
    {
        try {
            $response = $this->request('POST', '/v1/question/answer/list', [
                'question_id' => $questionId,
                'sku' => $sku,
                'last_id' => $lastId
            ]);

            return [
                'success' => true,
                'answers' => $response['answers'] ?? [],
                'last_id' => $response['last_id'] ?? ''
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'answers' => []
            ];
        }
    }

    /**
     * Изменить статус вопросов
     * POST /v1/question/change-status
     *
     * @param array $questionIds Массив ID вопросов
     * @param string $status NEW|VIEWED|PROCESSED
     * @return array
     */
    public function changeQuestionsStatus(array $questionIds, string $status): array
    {
        if (empty($questionIds)) {
            return ['success' => false, 'error' => 'Не указаны ID вопросов'];
        }

        try {
            $this->request('POST', '/v1/question/change-status', [
                'question_ids' => $questionIds,
                'status' => $status
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
