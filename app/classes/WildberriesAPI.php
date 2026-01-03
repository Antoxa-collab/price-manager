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
        try {
            $result = $this->request('GET', '/api/v3/warehouses');

            return [
                'success' => true,
                'data' => $result
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
            error_log("[WB API] Response: " . substr($response, 0, 500));
        }

        if ($httpCode === 429) {
            throw new Exception("Превышен лимит запросов. Повторите позже.");
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
            foreach ($prices as $item) {
                $data[] = [
                    'nmID' => (int)$item['nmID'],
                    'price' => (int)$item['price'],
                    'discount' => (int)($item['discount'] ?? 0)
                ];
            }

            $result = $this->requestV2('POST', 'prices', '/api/v2/upload/task', [
                'data' => $data
            ]);

            return [
                'success' => true,
                'taskId' => $result['data']['id'] ?? null,
                'updated' => count($data)
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
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

        try {
            $data = [
                'stocks' => array_map(function ($item) {
                    return [
                        'sku' => (string)$item['sku'],
                        'amount' => max(0, (int)$item['amount'])
                    ];
                }, $stocks)
            ];

            $this->requestV2('PUT', 'marketplace', "/api/v3/stocks/{$warehouseId}", $data);

            return [
                'success' => true,
                'updated' => count($stocks),
                'warehouse_id' => $warehouseId
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
}
