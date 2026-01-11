<?php
/**
 * Wildberries Seller API Client
 * Документация: https://dev.wildberries.ru/openapi/
 */
class WildberriesAPI
{
    private Database $db;
    private string $apiToken = '';
    private string $warehouseId = '';
    private int $userId;

    // Базовые URL для разных категорий API
    private const API_URLS = [
        'common'      => 'https://common-api.wildberries.ru',
        'content'     => 'https://content-api.wildberries.ru',
        'marketplace' => 'https://marketplace-api.wildberries.ru',
        'statistics'  => 'https://statistics-api.wildberries.ru',
        'analytics'   => 'https://seller-analytics-api.wildberries.ru',
        'prices'      => 'https://discounts-prices-api.wildberries.ru',
        'feedbacks'   => 'https://feedbacks-api.wildberries.ru',
        'supplies'    => 'https://supplies-api.wildberries.ru',
        'suppliers'   => 'https://suppliers-api.wildberries.ru'
    ];

    /**
     * Базовый URL API (legacy)
     */
    private const BASE_URL = 'https://suppliers-api.wildberries.ru';

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
            "SELECT api_key, warehouse_id FROM api_settings WHERE user_id = ? AND platform = 'wildberries' AND is_active = 1",
            [$this->userId]
        );

        if ($settings) {
            $this->apiToken = $settings['api_key'] ?? '';
            $this->warehouseId = $settings['warehouse_id'] ?? '';
        }
    }

    /**
     * Проверка наличия настроек
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiToken);
    }

    /**
     * Выполнение HTTP запроса к API
     * @param string $method HTTP метод (GET, POST, PUT, DELETE)
     * @param string $endpoint Эндпоинт API
     * @param array $data Данные запроса
     * @return array Ответ API
     * @throws Exception
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('API токен Wildberries не настроен');
        }

        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Authorization: ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            logError('WB API CURL error: ' . $error);
            throw new Exception('Ошибка соединения с API Wildberries: ' . $error);
        }

        $result = json_decode($response, true) ?? [];

        // Логируем запрос
        logError('WB API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => substr($response, 0, 500)
        ]);

        if ($httpCode >= 400) {
            $errorMessage = $result['errorText'] ?? $result['message'] ?? 'Неизвестная ошибка API';
            throw new Exception('Ошибка API Wildberries: ' . $errorMessage);
        }

        return $result;
    }

    /**
     * Обновление цен товаров
     * @param array $prices Массив цен [['nmId' => int, 'price' => float], ...]
     * @return array Результат операции
     */
    public function updatePrices(array $prices): array
    {
        if (empty($prices)) {
            return ['success' => false, 'message' => 'Список цен пуст'];
        }

        // Формируем данные для API
        $data = [];
        foreach ($prices as $item) {
            $data[] = [
                'nmId' => (int)$item['nmId'],
                'price' => (int)round($item['price'])
            ];
        }

        try {
            $result = $this->request('POST', '/api/v2/upload/task', [
                'data' => $data
            ]);

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('wb_update_prices', 'api', null, null, [
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
     * @param array $stocks Массив остатков [['sku' => string, 'amount' => int], ...]
     * @return array Результат операции
     */
    public function updateStocks(array $stocks): array
    {
        if (empty($stocks)) {
            return ['success' => false, 'message' => 'Список остатков пуст'];
        }

        if (empty($this->warehouseId)) {
            return ['success' => false, 'message' => 'ID склада не настроен'];
        }

        // Формируем данные для API
        $data = [
            'stocks' => []
        ];

        foreach ($stocks as $item) {
            $data['stocks'][] = [
                'sku' => (string)$item['sku'],
                'amount' => (int)$item['amount']
            ];
        }

        try {
            $result = $this->request('PUT', '/api/v3/stocks/' . $this->warehouseId, $data);

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('wb_update_stocks', 'api', null, null, [
                'warehouse_id' => $this->warehouseId,
                'stocks_count' => count($stocks),
                'stocks' => $stocks
            ]);

            return [
                'success' => true,
                'message' => 'Остатки успешно обновлены',
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
     * Обнуление остатков товара
     * @param string $sku Артикул товара
     * @return array Результат операции
     */
    public function zeroStock(string $sku): array
    {
        return $this->updateStocks([
            ['sku' => $sku, 'amount' => 0]
        ]);
    }

    /**
     * Получение списка складов
     * @return array Список складов
     */
    public function getWarehouses(): array
    {
        error_log("[WB getWarehouses] Запрашиваем склады...");

        try {
            $result = $this->requestV2('GET', 'marketplace', '/api/v3/warehouses');

            error_log("[WB getWarehouses] Ответ: " . json_encode($result, JSON_UNESCAPED_UNICODE));

            // WB API возвращает массив складов напрямую
            $warehouses = is_array($result) ? $result : [];

            error_log("[WB getWarehouses] Найдено складов: " . count($warehouses));

            return [
                'success' => true,
                'warehouses' => $warehouses
            ];
        } catch (Exception $e) {
            error_log("[WB getWarehouses] ОШИБКА: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'warehouses' => []
            ];
        }
    }

    /**
     * Получение информации о товаре по nmId
     * @param int $nmId ID номенклатуры
     * @return array Информация о товаре
     */
    public function getProductInfo(int $nmId): array
    {
        try {
            $result = $this->request('POST', '/content/v2/get/cards/list', [
                'settings' => [
                    'cursor' => ['limit' => 1],
                    'filter' => ['withPhoto' => -1]
                ],
                'vendorCodes' => []
            ]);

            return [
                'success' => true,
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
     * Сохранение настроек API
     * @param string $apiToken API токен
     * @param string $warehouseId ID склада
     * @return bool
     */
    public function saveSettings(string $apiToken, string $warehouseId): bool
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM api_settings WHERE user_id = ? AND platform = 'wildberries'",
            [$this->userId]
        );

        $data = [
            'user_id' => $this->userId,
            'platform' => 'wildberries',
            'api_key' => $apiToken,
            'warehouse_id' => $warehouseId,
            'is_active' => 1
        ];

        if ($existing) {
            $this->db->update('api_settings', $data, 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('api_settings', $data);
        }

        // Обновляем текущие настройки
        $this->apiToken = $apiToken;
        $this->warehouseId = $warehouseId;

        // Логируем изменение
        $log = new OperationsLog();
        $log->add('update_settings', 'api_settings', null, null, [
            'platform' => 'wildberries',
            'warehouse_id' => $warehouseId
        ]);

        return true;
    }

    // ==================== НОВЫЕ МЕТОДЫ API ====================

    /**
     * Выполнение HTTP запроса к новому API (с разными хостами)
     */
    private function requestV2(string $method, string $category, string $endpoint, array $data = [], array $queryParams = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('API токен Wildberries не настроен');
        }

        $baseUrl = self::API_URLS[$category] ?? self::API_URLS['suppliers'];
        $url = $baseUrl . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Authorization: ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[WB API ERROR] [$method] $url - $error");
            throw new Exception("Ошибка соединения: $error");
        }

        // Логируем ошибки
        if ($httpCode >= 400) {
            error_log("[WB API] [$method] $url - HTTP $httpCode");
            error_log("[WB API] Response: " . substr($response, 0, 1000));

            // Детальное логирование для HTTP 409
            if ($httpCode === 409) {
                error_log("[WB API 409 DEBUG] ===== ПОЛНЫЙ ОТВЕТ =====");
                error_log("[WB API 409 DEBUG] URL: $url");
                error_log("[WB API 409 DEBUG] Method: $method");
                error_log("[WB API 409 DEBUG] Request body: " . json_encode($data, JSON_UNESCAPED_UNICODE));
                error_log("[WB API 409 DEBUG] Response body: " . $response);
                error_log("[WB API 409 DEBUG] ===== КОНЕЦ =====");
            }
        }

        if ($httpCode === 429) {
            throw new Exception("Превышен лимит запросов (HTTP 429). Повторите позже.");
        }

        if ($httpCode === 409) {
            // Парсим ответ для понимания причины конфликта
            $errorData = json_decode($response, true);
            $errorDetail = $errorData['detail'] ?? $errorData['message'] ?? $errorData['error'] ?? $response;
            error_log("[WB API 409] Причина конфликта: " . (is_array($errorDetail) ? json_encode($errorDetail, JSON_UNESCAPED_UNICODE) : $errorDetail));
            throw new Exception("HTTP 409 Conflict: " . (is_string($errorDetail) ? $errorDetail : json_encode($errorDetail, JSON_UNESCAPED_UNICODE)));
        }

        if ($httpCode === 401) {
            // Проверяем на ошибку scope
            if (strpos($response, 'scope not allowed') !== false || strpos($response, 'token scope') !== false) {
                throw new Exception("Токен WB не имеет нужных прав. Создайте новый токен с правами на 'Контент', 'Цены', 'Отзывы' в личном кабинете WB Seller → Настройки → Доступ к API.");
            }
            throw new Exception("Ошибка авторизации. Проверьте API токен.");
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMsg = $this->parseWbError($errorData, $httpCode);
            throw new Exception($errorMsg);
        }

        if (empty($response)) {
            return [];
        }

        return json_decode($response, true) ?? [];
    }

    private function parseWbError(?array $errorData, int $httpCode): string
    {
        if ($errorData === null) {
            return "HTTP ошибка: $httpCode";
        }

        if (isset($errorData['detail'])) {
            return $errorData['detail'];
        }
        if (isset($errorData['message'])) {
            return $errorData['message'];
        }
        if (isset($errorData['error'])) {
            return is_array($errorData['error'])
                ? json_encode($errorData['error'], JSON_UNESCAPED_UNICODE)
                : $errorData['error'];
        }
        if (isset($errorData['errorText'])) {
            return $errorData['errorText'];
        }

        return "HTTP ошибка: $httpCode";
    }

    // ==================== ПРОВЕРКА ПОДКЛЮЧЕНИЯ ====================

    /**
     * Проверка подключения к API
     * GET /ping
     */
    public function testConnection(): array
    {
        try {
            $result = $this->requestV2('GET', 'common', '/ping');
            return [
                'success' => true,
                'status' => $result['Status'] ?? 'OK',
                'timestamp' => $result['TS'] ?? date('c')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Получить информацию о продавце
     * GET /api/v1/seller-info
     */
    public function getSellerInfo(): array
    {
        try {
            $result = $this->requestV2('GET', 'common', '/api/v1/seller-info');
            return [
                'success' => true,
                'name' => $result['name'] ?? '',
                'sid' => $result['sid'] ?? '',
                'tradeMark' => $result['tradeMark'] ?? ''
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== КАРТОЧКИ ТОВАРОВ ====================

    /**
     * Получить список карточек товаров
     * POST /content/v2/get/cards/list
     */
    public function getCardsList(int $limit = 100, ?string $updatedAt = null, ?int $nmID = null): array
    {
        try {
            $data = [
                'settings' => [
                    'cursor' => [
                        'limit' => min($limit, 100)
                    ],
                    'filter' => [
                        'withPhoto' => -1
                    ]
                ]
            ];

            if ($updatedAt !== null) {
                $data['settings']['cursor']['updatedAt'] = $updatedAt;
            }
            if ($nmID !== null) {
                $data['settings']['cursor']['nmID'] = $nmID;
            }

            $result = $this->requestV2('POST', 'content', '/content/v2/get/cards/list', $data);

            return [
                'success' => true,
                'cards' => $result['cards'] ?? [],
                'cursor' => $result['cursor'] ?? null
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'cards' => []];
        }
    }

    /**
     * Получить ВСЕ карточки товаров (с пагинацией)
     * WB API возвращает максимум 100 карточек за раз
     * Пагинация через cursor: updatedAt + nmID
     */
    public function getAllCards(): array
    {
        $allCards = [];
        $updatedAt = null;
        $nmID = null;
        $iterations = 0;
        $maxIterations = 100; // До 10000 товаров

        error_log("[WB getAllCards] Starting pagination...");

        do {
            $result = $this->getCardsList(100, $updatedAt, $nmID);

            if (!$result['success']) {
                error_log("[WB getAllCards] Error on iteration {$iterations}: " . ($result['error'] ?? 'unknown'));
                if ($iterations === 0) {
                    return $result;
                }
                break;
            }

            $cards = $result['cards'] ?? [];
            $cardsCount = count($cards);
            $allCards = array_merge($allCards, $cards);

            error_log("[WB getAllCards] Iteration {$iterations}: got {$cardsCount} cards, total so far: " . count($allCards));

            // Проверяем курсор для следующей страницы
            $cursor = $result['cursor'] ?? null;

            // Если вернулось меньше 100 карточек - это последняя страница
            if ($cardsCount < 100) {
                error_log("[WB getAllCards] Last page reached (got {$cardsCount} < 100)");
                break;
            }

            // Если нет курсора - выходим
            if (empty($cursor) || empty($cursor['updatedAt']) || empty($cursor['nmID'])) {
                error_log("[WB getAllCards] No cursor for next page");
                break;
            }

            // Обновляем параметры для следующего запроса
            $updatedAt = $cursor['updatedAt'];
            $nmID = $cursor['nmID'];

            $iterations++;

            // Задержка между запросами (200мс)
            usleep(200000);

        } while ($iterations < $maxIterations);

        error_log("[WB getAllCards] Finished: total {$iterations} iterations, " . count($allCards) . " cards");

        return [
            'success' => true,
            'cards' => $allCards,
            'total' => count($allCards)
        ];
    }

    // ==================== ЦЕНЫ (НОВЫЙ API) ====================

    /**
     * Получить текущие цены
     * GET /api/v2/list/goods/filter
     */
    public function getPrices(int $limit = 1000, int $offset = 0): array
    {
        try {
            $result = $this->requestV2('GET', 'prices', '/api/v2/list/goods/filter', [], [
                'limit' => min($limit, 1000),
                'offset' => $offset
            ]);

            return [
                'success' => true,
                'goods' => $result['data']['listGoods'] ?? [],
                'total' => count($result['data']['listGoods'] ?? [])
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'goods' => []];
        }
    }

    /**
     * Получить ВСЕ цены с пагинацией
     */
    public function getAllPrices(): array
    {
        $allGoods = [];
        $offset = 0;
        $limit = 1000;
        $maxIterations = 20;
        $iteration = 0;

        do {
            $result = $this->getPrices($limit, $offset);

            if (!$result['success']) {
                if ($iteration === 0) {
                    return $result;
                }
                break;
            }

            $goods = $result['goods'];
            $allGoods = array_merge($allGoods, $goods);

            $offset += $limit;
            $iteration++;

            if (count($goods) < $limit) {
                break;
            }

            usleep(200000);

        } while ($iteration < $maxIterations);

        return [
            'success' => true,
            'goods' => $allGoods,
            'total' => count($allGoods)
        ];
    }

    /**
     * Обновить цены (новый API)
     * POST /api/v2/upload/task
     */
    public function uploadPrices(array $prices): array
    {
        if (empty($prices)) {
            return ['success' => false, 'error' => 'Пустой массив цен'];
        }

        try {
            $data = [];
            $sentNmIds = []; // Для отслеживания отправленных nmID
            foreach ($prices as $item) {
                $nmId = (int)$item['nmID'];
                $price = (int)$item['price'];
                $discount = (int)($item['discount'] ?? 0);

                // Валидация: цена должна быть > 0
                if ($price <= 0) {
                    error_log("[WB uploadPrices] ПРОПУСК nmID={$nmId}: цена <= 0 ({$price})");
                    continue;
                }

                // Валидация: скидка должна быть 0-99
                if ($discount < 0 || $discount > 99) {
                    error_log("[WB uploadPrices] ПРОПУСК nmID={$nmId}: скидка вне диапазона 0-99 ({$discount})");
                    continue;
                }

                $data[] = [
                    'nmID' => $nmId,
                    'price' => $price,
                    'discount' => $discount
                ];
                $sentNmIds[] = $nmId;
            }

            if (empty($data)) {
                return ['success' => false, 'error' => 'Все товары отфильтрованы при валидации'];
            }

            // Подробное логирование отправляемых данных
            error_log("[WB uploadPrices] ===== ОТПРАВКА ЦЕН =====");
            error_log("[WB uploadPrices] Товаров: " . count($data));
            foreach ($data as $item) {
                error_log("[WB uploadPrices]   nmID={$item['nmID']}, price={$item['price']}, discount={$item['discount']}");
            }

            $result = $this->requestV2('POST', 'prices', '/api/v2/upload/task', [
                'data' => $data
            ]);

            // Логируем полный ответ WB
            error_log("[WB uploadPrices] Ответ WB (полный): " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // Проверяем на rate limit (WB возвращает error: '1' или error: 1)
            if (isset($result['error'])) {
                $errorCode = $result['error'];
                error_log("[WB uploadPrices] WB вернул ошибку: " . json_encode($errorCode));

                // Rate limit — слишком частые запросы
                if ($errorCode === '1' || $errorCode === 1) {
                    return [
                        'success' => false,
                        'error' => 'rate_limit',
                        'error_code' => 'RATE_LIMIT',
                        'message' => 'WB ограничивает частоту изменения цен (макс. 10 запросов/6 сек). Подождите 5-10 минут и повторите.',
                        'sent' => count($data),
                        'sent_nm_ids' => $sentNmIds
                    ];
                }

                // Другая ошибка
                return [
                    'success' => false,
                    'error' => is_string($errorCode) ? $errorCode : json_encode($errorCode),
                    'message' => 'Ошибка WB API: ' . (is_string($errorCode) ? $errorCode : json_encode($errorCode)),
                    'sent' => count($data)
                ];
            }

            // WB API возвращает taskId при успехе
            // Структура ответа: {"data": {"id": 123456}} или {"alreadyExists": true}
            $taskId = $result['data']['id'] ?? null;
            $alreadyExists = $result['alreadyExists'] ?? false;

            // Если задача создана, проверяем её статус (с повторными попытками)
            if ($taskId) {
                error_log("[WB uploadPrices] Создана задача #{$taskId}, ожидаем обработки...");

                $status = null;
                $maxAttempts = 5;
                $waitMs = 1000; // 1 секунда между попытками

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    usleep($waitMs * 1000);
                    $status = $this->getUploadTaskStatus($taskId);
                    error_log("[WB uploadPrices] Попытка #{$attempt}: статус задачи #{$taskId}: " . json_encode($status, JSON_UNESCAPED_UNICODE));

                    // Если задача обработана (статус 3, 5, 6) — выходим
                    if (($status['isProcessed'] ?? false) || ($status['status'] ?? 0) >= 3) {
                        break;
                    }

                    // Увеличиваем время ожидания для следующей попытки
                    $waitMs = min($waitMs * 1.5, 3000);
                }

                $processed = $status['processed'] ?? 0;
                $errors = $status['errors'] ?? [];
                $errorCount = count($errors);
                $taskStatus = $status['status'] ?? 0;

                // Если есть ошибки, логируем их подробно
                if ($errorCount > 0) {
                    error_log("[WB uploadPrices] ===== ОШИБКИ WB =====");
                    foreach ($errors as $err) {
                        error_log("[WB uploadPrices] nmID={$err['nmID']}: {$err['error']}");
                    }
                    error_log("[WB uploadPrices] ===== КОНЕЦ ОШИБОК =====");
                }

                // Вычисляем какие nmID были успешно обработаны
                $errorNmIds = array_column($errors, 'nmID');
                $successNmIds = array_diff($sentNmIds, $errorNmIds);

                return [
                    'success' => true,
                    'taskId' => $taskId,
                    'taskStatus' => $taskStatus,
                    'sent' => count($data),
                    'updated' => $processed ?: (count($data) - $errorCount),
                    'error_count' => $errorCount,
                    'errors' => $errors,
                    'error_nm_ids' => $errorNmIds,
                    'success_nm_ids' => array_values($successNmIds),
                    'alreadyExists' => $alreadyExists,
                    'message' => "Задача #{$taskId}: обработано " . ($processed ?: (count($data) - $errorCount)) . " из " . count($data) . " товаров."
                        . ($errorCount > 0 ? " Ошибок: {$errorCount}" : "")
                ];
            }

            // Если alreadyExists — цены уже актуальны
            if ($alreadyExists) {
                return [
                    'success' => true,
                    'taskId' => null,
                    'sent' => count($data),
                    'updated' => count($data),
                    'alreadyExists' => true,
                    'message' => "Цены уже актуальны для " . count($data) . " товаров."
                ];
            }

            return [
                'success' => true,
                'taskId' => $taskId,
                'sent' => count($data),
                'updated' => count($data),
                'message' => "Отправлено " . count($data) . " товаров."
            ];
        } catch (Exception $e) {
            error_log("[WB uploadPrices] КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage());
            error_log("[WB uploadPrices] Trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получить статус задачи загрузки цен
     * GET /api/v2/history/tasks
     *
     * Статусы WB:
     * 1 = в очереди
     * 2 = в обработке
     * 3 = успешно обработано
     * 4 = отменено
     * 5 = частично обработано (есть ошибки)
     * 6 = все товары с ошибками
     */
    public function getUploadTaskStatus(int $taskId): array
    {
        try {
            error_log("[WB getUploadTaskStatus] Запрашиваем статус задачи #{$taskId}");

            $result = $this->requestV2('GET', 'prices', '/api/v2/history/tasks', [], [
                'limit' => 20  // Увеличили лимит для надёжности
            ]);

            error_log("[WB getUploadTaskStatus] Ответ WB (полный): " . json_encode($result, JSON_UNESCAPED_UNICODE));

            // Ищем нашу задачу в списке
            $tasks = $result['data']['historyTasks'] ?? [];
            error_log("[WB getUploadTaskStatus] Найдено задач в истории: " . count($tasks));

            foreach ($tasks as $task) {
                $uploadId = $task['uploadID'] ?? 0;
                if ($uploadId === $taskId) {
                    $status = $task['status'] ?? 0;
                    // Статусы: 3 = успешно, 5 = частично с ошибками, 6 = все ошибки
                    $isProcessed = in_array($status, [3, 5, 6]);
                    $hasErrors = in_array($status, [5, 6]);

                    $processed = $task['processedCount'] ?? $task['goodsCount'] ?? 0;
                    $total = $task['goodsCount'] ?? 0;

                    error_log("[WB getUploadTaskStatus] Задача #{$taskId} найдена: status={$status}, processed={$processed}/{$total}, hasErrors=" . ($hasErrors ? 'yes' : 'no'));

                    // Если есть ошибки ИЛИ обработано меньше чем отправлено — запрашиваем детали
                    $errors = [];
                    if ($hasErrors || ($isProcessed && $processed < $total)) {
                        error_log("[WB getUploadTaskStatus] Запрашиваем детали ошибок для задачи #{$taskId}");
                        $details = $this->getUploadTaskDetails($taskId);
                        $errors = $details['errors'] ?? [];
                        error_log("[WB getUploadTaskStatus] Получено ошибок: " . count($errors));
                    }

                    return [
                        'taskId' => $taskId,
                        'status' => $status,
                        'isProcessed' => $isProcessed,
                        'processed' => $processed,
                        'total' => $total,
                        'errors' => $errors,
                        'rawTask' => $task  // Для отладки
                    ];
                }
            }

            error_log("[WB getUploadTaskStatus] Задача #{$taskId} НЕ найдена в истории (ещё в очереди?)");

            // Задача не найдена в истории — возможно ещё в очереди
            return [
                'taskId' => $taskId,
                'status' => 0,
                'isProcessed' => false,
                'processed' => 0,
                'total' => 0,
                'errors' => []
            ];
        } catch (Exception $e) {
            error_log("[WB getUploadTaskStatus] ОШИБКА: " . $e->getMessage());
            return [
                'taskId' => $taskId,
                'status' => -1,
                'isProcessed' => false,
                'processed' => 0,
                'total' => 0,
                'errors' => [['nmID' => 0, 'error' => 'Не удалось получить статус: ' . $e->getMessage()]]
            ];
        }
    }

    /**
     * Получить детали задачи загрузки (ошибки)
     * GET /api/v2/history/goods/task/{taskId}
     */
    public function getUploadTaskDetails(int $taskId): array
    {
        try {
            error_log("[WB getUploadTaskDetails] Запрашиваем детали задачи #{$taskId}");

            $result = $this->requestV2('GET', 'prices', "/api/v2/history/goods/task", [], [
                'uploadID' => $taskId,
                'limit' => 100
            ]);

            error_log("[WB getUploadTaskDetails] Ответ WB: " . json_encode($result, JSON_UNESCAPED_UNICODE));

            $errors = [];
            $goods = $result['data']['historyGoods'] ?? [];

            error_log("[WB getUploadTaskDetails] Товаров в ответе: " . count($goods));

            foreach ($goods as $good) {
                $nmId = $good['nmID'] ?? 0;
                $errorText = $good['errorText'] ?? '';
                $status = $good['status'] ?? 0;

                // Статус товара: 3 = успех, другие = ошибки
                $hasError = !empty($errorText) || $status != 3;

                if ($hasError) {
                    $errors[] = [
                        'nmID' => $nmId,
                        'error' => $errorText ?: "Статус: {$status}",
                        'status' => $status,
                        'price' => $good['price'] ?? null,
                        'discount' => $good['discount'] ?? null
                    ];
                    error_log("[WB getUploadTaskDetails] Ошибка: nmID={$nmId}, error='{$errorText}', status={$status}");
                }
            }

            error_log("[WB getUploadTaskDetails] Всего ошибок: " . count($errors));

            return ['errors' => $errors, 'goods' => $goods];
        } catch (Exception $e) {
            error_log("[WB getUploadTaskDetails] ОШИБКА: " . $e->getMessage());
            return ['errors' => [], 'goods' => []];
        }
    }

    // ==================== ОСТАТКИ (НОВЫЙ API) ====================

    /**
     * Получить остатки FBS
     * POST /api/v3/stocks/{warehouseId}
     */
    public function getStocksV3(int $warehouseId): array
    {
        try {
            $result = $this->requestV2('POST', 'marketplace', "/api/v3/stocks/{$warehouseId}", [
                'skus' => []
            ]);

            return [
                'success' => true,
                'stocks' => $result['stocks'] ?? []
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'stocks' => []];
        }
    }

    /**
     * Обновить остатки FBS (новый API)
     * PUT /api/v3/stocks/{warehouseId}
     */
    public function updateStocksV3(int $warehouseId, array $stocks): array
    {
        if (empty($stocks)) {
            return ['success' => false, 'error' => 'Пустой массив остатков'];
        }

        // Фильтруем пустые SKU — они вызывают HTTP 409
        $filteredStocks = array_filter($stocks, function ($item) {
            $sku = (string)($item['sku'] ?? '');
            return !empty($sku) && strlen($sku) >= 8;
        });

        if (empty($filteredStocks)) {
            error_log("[WB updateStocksV3] Все SKU отфильтрованы как невалидные");
            return [
                'success' => false,
                'error' => 'Нет валидных баркодов для загрузки. Минимальная длина баркода: 8 символов.'
            ];
        }

        try {
            $data = [
                'stocks' => array_values(array_map(function ($item) {
                    return [
                        'sku' => (string)$item['sku'],
                        'amount' => max(0, (int)$item['amount'])
                    ];
                }, $filteredStocks))
            ];

            // Логируем отправляемые данные
            error_log("[WB updateStocksV3] Склад: {$warehouseId}, товаров: " . count($filteredStocks) . " (из " . count($stocks) . ")");
            error_log("[WB updateStocksV3] Данные: " . json_encode($data, JSON_UNESCAPED_UNICODE));

            $result = $this->requestV2('PUT', 'marketplace', "/api/v3/stocks/{$warehouseId}", $data);

            // Логируем ответ WB
            error_log("[WB updateStocksV3] Ответ WB: " . json_encode($result, JSON_UNESCAPED_UNICODE));

            return [
                'success' => true,
                'updated' => count($filteredStocks),
                'warehouse_id' => $warehouseId,
                'skipped' => count($stocks) - count($filteredStocks)
            ];
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            error_log("[WB updateStocksV3] ОШИБКА: " . $errorMsg);

            // Парсим ошибку КГТ (CargoWarehouseRestrictionSGTKGTPlus)
            $oversizedSkus = $this->parseOversizedSkusFromError($errorMsg);

            // Возвращаем детальную информацию для отладки
            return [
                'success' => false,
                'error' => $errorMsg,
                'warehouse_id' => $warehouseId,
                'stocks_count' => count($filteredStocks),
                'debug_skus' => array_slice(array_column($filteredStocks, 'sku'), 0, 5),
                'oversized_skus' => $oversizedSkus // SKU, которые WB пометил как КГТ
            ];
        }
    }

    /**
     * Парсинг SKU из ошибки CargoWarehouseRestrictionSGTKGTPlus
     * Формат ошибки: HTTP 409 Conflict: [{"data":[{"sku":"2042764943238"...}],"code":"CargoWarehouseRestrictionSGTKGTPlus"...}]
     *
     * @param string $errorMsg Сообщение об ошибке
     * @return array Массив SKU (баркодов), которые WB пометил как КГТ
     */
    private function parseOversizedSkusFromError(string $errorMsg): array
    {
        $oversizedSkus = [];

        // Проверяем, что это ошибка КГТ
        if (strpos($errorMsg, 'CargoWarehouseRestrictionSGTKGTPlus') === false) {
            return [];
        }

        // Ищем JSON в сообщении об ошибке (после "HTTP 409 Conflict: ")
        if (preg_match('/HTTP 409 Conflict:\s*(.+)$/s', $errorMsg, $matches)) {
            $jsonStr = $matches[1];

            // Парсим JSON
            $data = json_decode($jsonStr, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (isset($item['data']) && is_array($item['data'])) {
                        foreach ($item['data'] as $skuData) {
                            if (!empty($skuData['sku'])) {
                                $oversizedSkus[] = (string)$skuData['sku'];
                            }
                        }
                    }
                }
            }
        }

        if (!empty($oversizedSkus)) {
            error_log("[WB updateStocksV3] Обнаружено КГТ-товаров: " . count($oversizedSkus) . " - " . implode(', ', array_slice($oversizedSkus, 0, 5)));
        }

        return $oversizedSkus;
    }

    // ==================== ОТЗЫВЫ ====================

    /**
     * Получить количество отзывов
     * GET /api/v1/feedbacks/count
     */
    public function getFeedbacksCount(): array
    {
        try {
            $result = $this->requestV2('GET', 'feedbacks', '/api/v1/feedbacks/count');

            return [
                'success' => true,
                'total' => $result['data']['countUnanswered'] ?? 0,
                'answered' => $result['data']['countArchive'] ?? 0
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'total' => 0];
        }
    }

    /**
     * Получить отзывы
     * GET /api/v1/feedbacks
     */
    public function getFeedbacks(int $take = 100, int $skip = 0, ?bool $isAnswered = null): array
    {
        try {
            $params = [
                'take' => min($take, 5000),
                'skip' => $skip,
                'order' => 'dateDesc'
            ];

            if ($isAnswered !== null) {
                $params['isAnswered'] = $isAnswered ? 'true' : 'false';
            }

            $result = $this->requestV2('GET', 'feedbacks', '/api/v1/feedbacks', [], $params);

            $feedbacks = [];
            foreach ($result['data']['feedbacks'] ?? [] as $fb) {
                $feedbacks[] = [
                    'id' => $fb['id'] ?? '',
                    'nmId' => $fb['productDetails']['nmId'] ?? 0,
                    'productName' => $fb['productDetails']['productName'] ?? '',
                    'supplierArticle' => $fb['productDetails']['supplierArticle'] ?? '',
                    'userName' => $fb['userName'] ?? 'Покупатель',
                    'text' => $fb['text'] ?? '',
                    'rating' => (int)($fb['productValuation'] ?? 5),
                    'createdDate' => $fb['createdDate'] ?? '',
                    'isAnswered' => (bool)($fb['answer'] ?? false),
                    'answer' => $fb['answer']['text'] ?? null,
                    'photos' => $fb['photoLinks'] ?? []
                ];
            }

            return [
                'success' => true,
                'feedbacks' => $feedbacks,
                'total' => $result['data']['countUnanswered'] ?? 0
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'feedbacks' => []];
        }
    }

    /**
     * Получить ВСЕ неотвеченные отзывы
     */
    public function getAllUnansweredFeedbacks(): array
    {
        $allFeedbacks = [];
        $skip = 0;
        $take = 100;
        $maxIterations = 50;
        $iteration = 0;

        do {
            $result = $this->getFeedbacks($take, $skip, false);

            if (!$result['success']) {
                if ($iteration === 0) {
                    return $result;
                }
                break;
            }

            $feedbacks = $result['feedbacks'];
            $allFeedbacks = array_merge($allFeedbacks, $feedbacks);

            $skip += $take;
            $iteration++;

            if (count($feedbacks) < $take) {
                break;
            }

            usleep(300000);

        } while ($iteration < $maxIterations);

        return [
            'success' => true,
            'feedbacks' => $allFeedbacks,
            'total' => count($allFeedbacks)
        ];
    }

    /**
     * Ответить на отзыв
     * POST /api/v1/feedbacks/answer
     * Формат: { "id": "...", "text": "..." }
     * Документация: https://dev.wildberries.ru/openapi/user-communication
     */
    public function replyToFeedback(string $feedbackId, string $text): array
    {
        try {
            error_log("[WB API] replyToFeedback: ID={$feedbackId}, text_len=" . strlen($text));

            $this->requestV2('POST', 'feedbacks', '/api/v1/feedbacks/answer', [
                'id' => $feedbackId,
                'text' => $text
            ]);

            error_log("[WB API] replyToFeedback: SUCCESS");
            return ['success' => true];
        } catch (Exception $e) {
            error_log("[WB API] replyToFeedback: ERROR - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== ВОПРОСЫ ====================

    /**
     * Получить количество вопросов
     * GET /api/v1/questions/count
     */
    public function getQuestionsCount(): array
    {
        try {
            $result = $this->requestV2('GET', 'feedbacks', '/api/v1/questions/count');

            return [
                'success' => true,
                'total' => $result['data']['countUnanswered'] ?? 0,
                'answered' => $result['data']['countArchive'] ?? 0
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'total' => 0];
        }
    }

    /**
     * Получить вопросы
     * GET /api/v1/questions
     */
    public function getQuestions(int $take = 100, int $skip = 0, ?bool $isAnswered = null): array
    {
        try {
            $params = [
                'take' => min($take, 5000),
                'skip' => $skip,
                'order' => 'dateDesc'
            ];

            if ($isAnswered !== null) {
                $params['isAnswered'] = $isAnswered ? 'true' : 'false';
            }

            $result = $this->requestV2('GET', 'feedbacks', '/api/v1/questions', [], $params);

            $questions = [];
            foreach ($result['data']['questions'] ?? [] as $q) {
                $questions[] = [
                    'id' => $q['id'] ?? '',
                    'nmId' => $q['productDetails']['nmId'] ?? 0,
                    'productName' => $q['productDetails']['productName'] ?? '',
                    'supplierArticle' => $q['productDetails']['supplierArticle'] ?? '',
                    'userName' => $q['userName'] ?? 'Покупатель',
                    'text' => $q['text'] ?? '',
                    'createdDate' => $q['createdDate'] ?? '',
                    'isAnswered' => (bool)($q['answer'] ?? false),
                    'answer' => $q['answer']['text'] ?? null
                ];
            }

            return [
                'success' => true,
                'questions' => $questions,
                'total' => $result['data']['countUnanswered'] ?? 0
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'questions' => []];
        }
    }

    /**
     * Получить ВСЕ неотвеченные вопросы
     */
    public function getAllUnansweredQuestions(): array
    {
        $allQuestions = [];
        $skip = 0;
        $take = 100;
        $maxIterations = 50;
        $iteration = 0;

        do {
            $result = $this->getQuestions($take, $skip, false);

            if (!$result['success']) {
                if ($iteration === 0) {
                    return $result;
                }
                break;
            }

            $questions = $result['questions'];
            $allQuestions = array_merge($allQuestions, $questions);

            $skip += $take;
            $iteration++;

            if (count($questions) < $take) {
                break;
            }

            usleep(300000);

        } while ($iteration < $maxIterations);

        return [
            'success' => true,
            'questions' => $allQuestions,
            'total' => count($allQuestions)
        ];
    }

    /**
     * Ответить на вопрос
     * PATCH /api/v1/questions
     * Формат: { "id": "...", "answer": { "text": "..." }, "state": "wbRu" }
     */
    public function replyToQuestion(string $questionId, string $text): array
    {
        try {
            error_log("[WB API] replyToQuestion: ID={$questionId}, text_len=" . strlen($text));

            $this->requestV2('PATCH', 'feedbacks', '/api/v1/questions', [
                'id' => $questionId,
                'answer' => [
                    'text' => $text
                ],
                'state' => 'wbRu'
            ]);

            error_log("[WB API] replyToQuestion: SUCCESS");
            return ['success' => true];
        } catch (Exception $e) {
            error_log("[WB API] replyToQuestion: ERROR - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== КОМИССИИ ====================

    /**
     * Получить тарифы комиссий
     * GET /api/v1/tariffs/commission
     */
    public function getCommissions(): array
    {
        try {
            $result = $this->requestV2('GET', 'common', '/api/v1/tariffs/commission');

            return [
                'success' => true,
                'report' => $result['report'] ?? []
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получить тарифы на хранение и логистику
     * GET /api/v1/tariffs/box
     */
    public function getBoxTariffs(?string $date = null): array
    {
        try {
            $params = [];
            if ($date) {
                $params['date'] = $date;
            }

            $result = $this->requestV2('GET', 'common', '/api/v1/tariffs/box', [], $params);

            return [
                'success' => true,
                'response' => $result['response'] ?? []
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== CONTENT API (КАРТОЧКИ ТОВАРОВ) ====================

    /**
     * Получить все карточки товаров продавца из Content API
     * POST /content/v2/get/cards/list
     * Документация: https://dev.wildberries.ru/openapi/work-with-products
     *
     * @param int $limit Количество карточек за запрос (макс 100)
     * @return array Массив карточек товаров
     */
    public function getAllProductCards(int $limit = 100): array
    {
        $allCards = [];
        $cursor = null;
        $totalFetched = 0;
        $maxIterations = 100; // Защита от бесконечного цикла

        error_log("[WB Content API] Начинаем загрузку карточек товаров");

        for ($i = 0; $i < $maxIterations; $i++) {
            $body = [
                'settings' => [
                    'cursor' => [
                        'limit' => $limit
                    ],
                    'filter' => [
                        'withPhoto' => -1 // Все товары (-1 = все, 0 = без фото, 1 = с фото)
                    ]
                ]
            ];

            // Добавляем курсор для пагинации
            if ($cursor !== null) {
                $body['settings']['cursor']['updatedAt'] = $cursor['updatedAt'];
                $body['settings']['cursor']['nmID'] = $cursor['nmID'];
            }

            try {
                $response = $this->requestV2('POST', 'content', '/content/v2/get/cards/list', $body);

                $cards = $response['cards'] ?? [];
                $newCursor = $response['cursor'] ?? null;

                if (empty($cards)) {
                    error_log("[WB Content API] Больше карточек нет, завершаем");
                    break;
                }

                $allCards = array_merge($allCards, $cards);
                $totalFetched += count($cards);

                error_log("[WB Content API] Загружено карточек: {$totalFetched}");

                // Если карточек меньше лимита — это последняя страница
                if (count($cards) < $limit || $newCursor === null) {
                    break;
                }

                $cursor = $newCursor;

                // Пауза между запросами чтобы не превысить лимиты
                usleep(200000); // 200ms

            } catch (Exception $e) {
                error_log("[WB Content API] Ошибка загрузки карточек: " . $e->getMessage());
                break;
            }
        }

        error_log("[WB Content API] Всего загружено карточек: " . count($allCards));
        return $allCards;
    }

    /**
     * Получить карточку товара по nmId
     * Использует фильтр textSearch для поиска конкретного товара
     *
     * @param int $nmId ID товара на WB
     * @return array|null Данные карточки или null
     */
    public function getProductCardByNmId(int $nmId): ?array
    {
        $body = [
            'settings' => [
                'cursor' => ['limit' => 100],
                'filter' => [
                    'withPhoto' => -1,
                    'textSearch' => (string)$nmId
                ]
            ]
        ];

        try {
            $response = $this->requestV2('POST', 'content', '/content/v2/get/cards/list', $body);
            $cards = $response['cards'] ?? [];

            foreach ($cards as $card) {
                if ((int)$card['nmID'] === $nmId) {
                    return $card;
                }
            }

            return null;
        } catch (Exception $e) {
            error_log("[WB Content API] Ошибка получения карточки {$nmId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получить карточки товаров по списку nmId
     *
     * @param array $nmIds Массив ID товаров
     * @return array Массив карточек (ключ = nmID)
     */
    public function getProductCardsByNmIds(array $nmIds): array
    {
        if (empty($nmIds)) {
            return [];
        }

        $result = [];
        $allCards = $this->getAllProductCards();

        foreach ($allCards as $card) {
            $nmId = $card['nmID'] ?? null;
            if ($nmId && in_array($nmId, $nmIds)) {
                $result[$nmId] = $card;
            }
        }

        return $result;
    }

    // ==================== КАРАНТИН ЦЕН ====================

    /**
     * Получить товары в карантине цен
     * GET /api/v2/quarantine
     *
     * Карантин — это состояние, когда WB блокирует изменение цены товара.
     * Причины попадания в карантин:
     * - Новая цена со скидкой в 3+ раза меньше старой
     * - Резкое изменение цены
     *
     * @param int $limit Количество товаров (макс 1000)
     * @param int $offset Смещение для пагинации
     * @return array Товары в карантине
     */
    public function getQuarantineGoods(int $limit = 1000, int $offset = 0): array
    {
        try {
            error_log("[WB getQuarantineGoods] Запрашиваем карантин: limit={$limit}, offset={$offset}");

            $result = $this->requestV2('GET', 'prices', '/api/v2/quarantine', [], [
                'limit' => min($limit, 1000),
                'offset' => $offset
            ]);

            error_log("[WB getQuarantineGoods] Ответ WB: " . json_encode($result, JSON_UNESCAPED_UNICODE));

            $quarantineGoods = $result['data']['quarantineGoods'] ?? [];

            // Форматируем для удобства
            $goods = [];
            foreach ($quarantineGoods as $item) {
                $goods[] = [
                    'nmID' => $item['nmID'] ?? 0,
                    'vendorCode' => $item['vendorCode'] ?? '',
                    'currentPrice' => $item['price'] ?? 0,
                    'currentDiscount' => $item['discount'] ?? 0,
                    'newPrice' => $item['newPrice'] ?? 0,
                    'newDiscount' => $item['newDiscount'] ?? 0,
                    'reason' => $this->translateQuarantineReason($item['warningType'] ?? '')
                ];
            }

            return [
                'success' => true,
                'quarantine' => $goods,
                'total' => count($goods)
            ];
        } catch (Exception $e) {
            error_log("[WB getQuarantineGoods] ОШИБКА: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'quarantine' => []
            ];
        }
    }

    /**
     * Проверить, находятся ли указанные nmID в карантине
     *
     * @param array $nmIds Массив nmID для проверки
     * @return array ['inQuarantine' => [...], 'clear' => [...]]
     */
    public function checkQuarantine(array $nmIds): array
    {
        if (empty($nmIds)) {
            return ['inQuarantine' => [], 'clear' => $nmIds];
        }

        $quarantine = $this->getQuarantineGoods(1000);
        if (!$quarantine['success']) {
            // Если не удалось получить карантин — считаем все товары "чистыми"
            error_log("[WB checkQuarantine] Не удалось получить карантин, продолжаем без проверки");
            return ['inQuarantine' => [], 'clear' => $nmIds, 'error' => $quarantine['error']];
        }

        $quarantineNmIds = array_column($quarantine['quarantine'], 'nmID');
        $inQuarantine = [];
        $clear = [];

        foreach ($nmIds as $nmId) {
            if (in_array((int)$nmId, $quarantineNmIds)) {
                // Найдём детали карантина
                $idx = array_search((int)$nmId, $quarantineNmIds);
                $inQuarantine[] = $quarantine['quarantine'][$idx];
            } else {
                $clear[] = $nmId;
            }
        }

        error_log("[WB checkQuarantine] Проверено: " . count($nmIds) . ", в карантине: " . count($inQuarantine) . ", чистых: " . count($clear));

        return [
            'inQuarantine' => $inQuarantine,
            'clear' => $clear
        ];
    }

    /**
     * Перевод причины карантина на русский
     */
    private function translateQuarantineReason(string $warningType): string
    {
        $reasons = [
            'priceDropMoreThan3Times' => 'Цена снижена более чем в 3 раза',
            'priceIncreaseMoreThan3Times' => 'Цена повышена более чем в 3 раза',
            'discountTooHigh' => 'Слишком высокая скидка',
            'priceTooLow' => 'Цена ниже минимальной',
            'suspiciousPriceChange' => 'Подозрительное изменение цены'
        ];

        return $reasons[$warningType] ?? $warningType ?: 'Неизвестная причина';
    }
}
