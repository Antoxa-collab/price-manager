<?php

/**
 * Класс для работы с API Яндекс.Маркет
 * Документация: https://yandex.ru/dev/market/partner-api/doc/
 */
class YandexMarketAPI
{
    private Database $db;
    private string $apiKey = '';
    private string $businessId = '';
    private string $campaignId = '';
    private string $warehouseId = '';
    private int $userId;

    // Базовый URL API Яндекс.Маркет
    const API_BASE_URL = 'https://api.partner.market.yandex.ru';

    // Лимиты API
    const LIMIT_PRICES_PER_REQUEST = 500;      // Макс товаров в запросе цен
    const LIMIT_PRICES_PER_MINUTE = 10000;     // Макс товаров в минуту
    const LIMIT_TARIFFS_PER_REQUEST = 200;     // Макс товаров для расчёта тарифов
    const LIMIT_TARIFFS_PER_MINUTE = 100;      // Макс запросов тарифов в минуту

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
     * Загрузка настроек из БД
     */
    private function loadSettings(): void
    {
        $settings = $this->db->fetchOne(
            "SELECT api_key, client_id, shop_id, warehouse_id
             FROM api_settings
             WHERE user_id = ? AND platform = 'yandex_market' AND is_active = 1",
            [$this->userId]
        );

        if ($settings) {
            $this->apiKey = $settings['api_key'] ?? '';
            $this->businessId = $settings['client_id'] ?? '';      // business_id хранится в client_id
            $this->campaignId = $settings['shop_id'] ?? '';        // campaign_id хранится в shop_id
            $this->warehouseId = $settings['warehouse_id'] ?? '';
        }
    }

    /**
     * Сохранение настроек в БД
     */
    public function saveSettings(string $apiKey, string $businessId, string $campaignId, string $warehouseId): bool
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM api_settings WHERE user_id = ? AND platform = 'yandex_market'",
            [$this->userId]
        );

        $data = [
            'user_id' => $this->userId,
            'platform' => 'yandex_market',
            'api_key' => $apiKey,
            'client_id' => $businessId,      // business_id
            'shop_id' => $campaignId,        // campaign_id
            'warehouse_id' => $warehouseId,
            'is_active' => 1
        ];

        if ($existing) {
            $this->db->update('api_settings', $data, 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('api_settings', $data);
        }

        // Обновляем текущие настройки
        $this->apiKey = $apiKey;
        $this->businessId = $businessId;
        $this->campaignId = $campaignId;
        $this->warehouseId = $warehouseId;

        // Логируем изменение
        $log = new OperationsLog();
        $log->add('update_settings', 'api_settings', null, null, [
            'platform' => 'yandex_market',
            'business_id' => $businessId,
            'campaign_id' => $campaignId,
            'warehouse_id' => $warehouseId
        ]);

        return true;
    }

    /**
     * Получение текущих настроек
     */
    public function getSettings(): array
    {
        return [
            'api_key' => $this->apiKey,
            'business_id' => $this->businessId,
            'campaign_id' => $this->campaignId,
            'warehouse_id' => $this->warehouseId,
            'is_active' => !empty($this->apiKey)
        ];
    }

    /**
     * Проверка настроен ли API
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->businessId);
    }

    // ==================== HTTP ЗАПРОСЫ ====================

    /**
     * Выполнение HTTP запроса к API Яндекс.Маркет
     *
     * @param string $method HTTP метод (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint API (без базового URL)
     * @param array|null $data Данные для отправки
     * @param array $queryParams GET параметры
     * @return array Результат запроса
     */
    private function request(string $method, string $endpoint, ?array $data = null, array $queryParams = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('API Яндекс.Маркет не настроен');
        }

        $url = self::API_BASE_URL . $endpoint;

        // Добавляем GET параметры
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Api-Key: ' . $this->apiKey
        ];

        // ЛОГИРОВАНИЕ ЗАПРОСА
        $logBody = ($data === null || $data === []) ? '{}' : json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log("=== YM API REQUEST ===");
        error_log("[YM API] Method: {$method}");
        error_log("[YM API] URL: {$url}");
        error_log("[YM API] Body: {$logBody}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        // Настройка метода
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                // ЯМ API требует тело запроса даже для пустых POST
                // Пустой массив [] -> "{}", не null
                $jsonBody = ($data === null || $data === []) ? '{}' : json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                $jsonBody = ($data === null || $data === []) ? '{}' : json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // ЛОГИРОВАНИЕ ОТВЕТА
        error_log("=== YM API RESPONSE ===");
        error_log("[YM API] HTTP Code: {$httpCode}");
        error_log("[YM API] CURL Error: " . ($error ?: 'none'));
        error_log("[YM API] Response (first 2000 chars): " . mb_substr($response, 0, 2000));

        if ($error) {
            error_log("[YM API] CURL error: {$error}");
            throw new Exception("Ошибка соединения: {$error}");
        }

        $result = json_decode($response, true);

        // ЛОГИРОВАНИЕ СТРУКТУРЫ
        if ($result) {
            error_log("[YM API] Response keys: " . implode(', ', array_keys($result)));
            if (isset($result['result'])) {
                error_log("[YM API] Result keys: " . implode(', ', array_keys($result['result'])));
            }
        } else {
            error_log("[YM API] JSON decode failed! Raw response: " . mb_substr($response, 0, 500));
            $result = [];
        }

        // Обработка ошибок HTTP
        if ($httpCode >= 400) {
            $errorMsg = $this->parseErrorMessage($result, $httpCode);
            error_log("[YM API] Error: {$errorMsg}");
            error_log("[YM API] Full Response: " . substr($response, 0, 1000));
            throw new Exception($errorMsg);
        }

        return $result;
    }

    /**
     * Парсинг сообщения об ошибке из ответа API
     */
    private function parseErrorMessage(?array $result, int $httpCode): string
    {
        if ($result === null) {
            return "HTTP {$httpCode}: Пустой ответ";
        }

        // Формат ошибки ЯМ: {"status": "ERROR", "errors": [{"code": "...", "message": "..."}]}
        if (isset($result['errors']) && is_array($result['errors'])) {
            $messages = [];
            foreach ($result['errors'] as $err) {
                $messages[] = ($err['code'] ?? '') . ': ' . ($err['message'] ?? 'Unknown');
            }
            return implode('; ', $messages);
        }

        // Альтернативный формат
        if (isset($result['error']['message'])) {
            return $result['error']['message'];
        }

        if (isset($result['message'])) {
            return $result['message'];
        }

        return "HTTP {$httpCode}";
    }

    // ==================== МЕТОДЫ API: ОБЩИЕ ====================

    /**
     * Проверка подключения к API
     * Использует GET /v2/campaigns для проверки токена
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'API не настроен. Укажите API-Key и Business ID'];
        }

        try {
            $result = $this->getCampaigns();
            $campaigns = $result['campaigns'] ?? [];
            return [
                'success' => true,
                'message' => 'Подключение успешно. Найдено кампаний: ' . count($campaigns),
                'campaigns' => $campaigns
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получение списка кампаний (магазинов)
     * GET /v2/campaigns
     */
    public function getCampaigns(): array
    {
        return $this->request('GET', '/v2/campaigns');
    }

    /**
     * Получение информации о кампании
     * GET /v2/campaigns/{campaignId}
     */
    public function getCampaignInfo(?string $campaignId = null): array
    {
        $id = $campaignId ?? $this->campaignId;
        if (empty($id)) {
            return ['success' => false, 'error' => 'Campaign ID не указан'];
        }

        try {
            return ['success' => true, 'data' => $this->request('GET', "/v2/campaigns/{$id}")];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получение настроек кабинета
     * POST /v2/businesses/{businessId}/settings
     */
    public function getBusinessSettings(): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        try {
            return ['success' => true, 'data' => $this->request('POST', "/v2/businesses/{$this->businessId}/settings", [])];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получение складов Маркета
     * GET /v2/warehouses
     */
    public function getWarehouses(): array
    {
        try {
            $result = $this->request('GET', '/v2/warehouses');
            return ['success' => true, 'warehouses' => $result['result']['warehouses'] ?? []];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'warehouses' => []];
        }
    }

    /**
     * Получение дерева категорий
     * POST /v2/categories/tree
     */
    public function getCategoriesTree(): array
    {
        try {
            return ['success' => true, 'data' => $this->request('POST', '/v2/categories/tree', [])];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== МЕТОДЫ API: ТОВАРЫ ====================

    /**
     * Получение товаров с офферами (постранично)
     * POST /v2/businesses/{businessId}/offer-mappings
     *
     * @param string|null $pageToken Токен для пагинации
     * @param int $limit Лимит товаров (макс 200)
     * @return array
     */
    public function getOfferMappings(?string $pageToken = null, int $limit = 200): array
    {
        if (empty($this->businessId)) {
            error_log("[YM API] getOfferMappings: Business ID is empty!");
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        error_log("[YM API] getOfferMappings: businessId={$this->businessId}, pageToken=" . ($pageToken ?? 'null') . ", limit={$limit}");

        // Согласно документации ЯМ API v2, пагинация передаётся через query параметры
        $queryParams = ['limit' => min($limit, 200)];
        if ($pageToken) {
            $queryParams['page_token'] = $pageToken;
        }

        try {
            $result = $this->request(
                'POST',
                "/v2/businesses/{$this->businessId}/offer-mappings",
                [],  // пустое тело
                $queryParams
            );

            // Логируем структуру ответа для диагностики
            error_log("[YM API] getOfferMappings response structure:");
            error_log("[YM API] - Top level keys: " . implode(', ', array_keys($result)));

            if (isset($result['result'])) {
                error_log("[YM API] - result keys: " . implode(', ', array_keys($result['result'])));

                if (isset($result['result']['offerMappings'])) {
                    $count = count($result['result']['offerMappings']);
                    error_log("[YM API] - offerMappings count: {$count}");

                    // Показать первый товар для примера структуры
                    if (!empty($result['result']['offerMappings'])) {
                        $first = $result['result']['offerMappings'][0];
                        error_log("[YM API] - First item keys: " . implode(', ', array_keys($first)));
                        if (isset($first['offer'])) {
                            error_log("[YM API] - First offer keys: " . implode(', ', array_keys($first['offer'])));
                        }
                        error_log("[YM API] - First item: " . json_encode($first, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                } else {
                    error_log("[YM API] - offerMappings NOT FOUND in result!");
                    error_log("[YM API] - Available keys in result: " . implode(', ', array_keys($result['result'])));
                }
            } else {
                error_log("[YM API] - 'result' key NOT FOUND!");
            }

            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            error_log("[YM API] getOfferMappings exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Получение ВСЕХ товаров (с автоматической пагинацией)
     *
     * @return array ['success' => bool, 'data' => array товаров]
     */
    public function getAllOffers(): array
    {
        $allOffers = [];
        $pageToken = null;
        $page = 0;

        error_log("=== YM getAllOffers START ===");

        do {
            $page++;
            error_log("[YM API] getAllOffers: Loading page {$page}...");

            $result = $this->getOfferMappings($pageToken);

            if (!$result['success']) {
                error_log("[YM API] getAllOffers: ERROR on page {$page}: " . ($result['error'] ?? 'unknown'));
                if ($page === 1) {
                    return $result;
                }
                break;
            }

            // ВАЖНО: Проверяем разные варианты структуры ответа
            $mappings = [];

            // Вариант 1: result.offerMappings (основной формат ЯМ)
            if (isset($result['data']['result']['offerMappings'])) {
                $mappings = $result['data']['result']['offerMappings'];
                error_log("[YM API] Found offerMappings in result.offerMappings: " . count($mappings));
            }
            // Вариант 2: result.offers (альтернативная структура)
            elseif (isset($result['data']['result']['offers'])) {
                $mappings = $result['data']['result']['offers'];
                error_log("[YM API] Found offers in result.offers: " . count($mappings));
            }
            // Вариант 3: offerMappings на верхнем уровне
            elseif (isset($result['data']['offerMappings'])) {
                $mappings = $result['data']['offerMappings'];
                error_log("[YM API] Found offerMappings at top level: " . count($mappings));
            }
            // Вариант 4: offers на верхнем уровне
            elseif (isset($result['data']['offers'])) {
                $mappings = $result['data']['offers'];
                error_log("[YM API] Found offers at top level: " . count($mappings));
            }
            else {
                error_log("[YM API] NO OFFERS FOUND! Response structure: " . json_encode(array_keys($result['data'] ?? []), JSON_UNESCAPED_UNICODE));
                error_log("[YM API] Full data response: " . json_encode($result['data'] ?? [], JSON_UNESCAPED_UNICODE));
            }

            $allOffers = array_merge($allOffers, $mappings);
            error_log("[YM API] getAllOffers: Page {$page} loaded " . count($mappings) . " items, total: " . count($allOffers));

            // Пагинация - проверяем разные варианты
            // ВАЖНО: сбрасываем только после проверки, иначе цикл остановится
            $nextPageToken = null;

            // Логируем структуру paging для диагностики
            if (isset($result['data']['result']['paging'])) {
                error_log("[YM API] Paging structure: " . json_encode($result['data']['result']['paging'], JSON_UNESCAPED_UNICODE));
            } else {
                error_log("[YM API] WARNING: No 'paging' in result!");
                error_log("[YM API] Result keys: " . implode(', ', array_keys($result['data']['result'] ?? [])));
            }

            // Пробуем разные пути к nextPageToken
            if (isset($result['data']['result']['paging']['nextPageToken'])) {
                $nextPageToken = $result['data']['result']['paging']['nextPageToken'];
                error_log("[YM API] Found nextPageToken in result.paging.nextPageToken");
            } elseif (isset($result['data']['paging']['nextPageToken'])) {
                $nextPageToken = $result['data']['paging']['nextPageToken'];
                error_log("[YM API] Found nextPageToken in data.paging.nextPageToken");
            } else {
                error_log("[YM API] No nextPageToken found - this is the last page");
            }

            $pageToken = $nextPageToken;
            error_log("[YM API] Page {$page} summary: loaded=" . count($mappings) . ", total=" . count($allOffers) . ", nextPageToken=" . ($pageToken ? mb_substr($pageToken, 0, 50) . '...' : 'NO'));

            // Защита от бесконечного цикла
            if ($page > 100) {
                error_log("[YM API] Too many pages, stopping at {$page}");
                break;
            }

            // Небольшая пауза между запросами
            if ($pageToken) {
                usleep(100000); // 100ms
            }

        } while ($pageToken);

        error_log("=== YM getAllOffers COMPLETE: " . count($allOffers) . " total ===");

        return ['success' => true, 'data' => $allOffers, 'total' => count($allOffers)];
    }

    // ==================== МЕТОДЫ API: ЦЕНЫ ====================

    /**
     * Получение текущих цен на товары (для всех магазинов)
     * POST /v2/businesses/{businessId}/offer-prices
     *
     * @param array $offerIds Список offerId для фильтрации (опционально)
     * @return array
     */
    public function getOfferPrices(array $offerIds = []): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        $data = [];
        if (!empty($offerIds)) {
            $data['offerIds'] = $offerIds;
        }

        try {
            $result = $this->request('POST', "/v2/businesses/{$this->businessId}/offer-prices", $data);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Установка цен на товары (для всех магазинов)
     * POST /v2/businesses/{businessId}/offer-prices/updates
     *
     * Лимит: 500 товаров за запрос, 10000 товаров в минуту
     *
     * @param array $offers Массив товаров с ценами:
     *   [
     *     [
     *       'offerId' => 'SKU123',
     *       'price' => [
     *         'value' => 1500.00,
     *         'currencyId' => 'RUR',
     *         'discountBase' => 2000.00  // зачёркнутая цена (опционально)
     *       ]
     *     ],
     *     ...
     *   ]
     * @return array
     */
    public function uploadPrices(array $offers): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        if (empty($offers)) {
            return ['success' => false, 'error' => 'Нет товаров для обновления'];
        }

        $errors = [];
        $successCount = 0;

        // Разбиваем на батчи по 500 товаров
        $chunks = array_chunk($offers, self::LIMIT_PRICES_PER_REQUEST);

        foreach ($chunks as $index => $chunk) {
            error_log("[YM API] Uploading prices batch " . ($index + 1) . "/" . count($chunks));

            try {
                $data = ['offers' => $chunk];
                $this->request(
                    'POST',
                    "/v2/businesses/{$this->businessId}/offer-prices/updates",
                    $data
                );
                $successCount += count($chunk);
            } catch (Exception $e) {
                $errors[] = "Batch " . ($index + 1) . ": " . $e->getMessage();
            }

            // Пауза между батчами для соблюдения лимита
            if ($index < count($chunks) - 1) {
                usleep(200000); // 200ms
            }
        }

        // Логируем операцию
        $log = new OperationsLog();
        $log->add('ym_upload_prices', 'api', null, null, [
            'total' => count($offers),
            'updated' => $successCount,
            'errors' => count($errors)
        ]);

        if (empty($errors)) {
            return [
                'success' => true,
                'updated' => $successCount,
                'message' => "Загружено цен: {$successCount}"
            ];
        }

        return [
            'success' => count($errors) < count($chunks), // частичный успех
            'updated' => $successCount,
            'errors' => $errors,
            'message' => "Загружено: {$successCount}, ошибок: " . count($errors)
        ];
    }

    /**
     * Установка цен в конкретном магазине
     * POST /v2/campaigns/{campaignId}/offer-prices/updates
     *
     * Лимит: 2000 товаров за запрос
     */
    public function uploadCampaignPrices(array $offers, ?string $campaignId = null): array
    {
        $id = $campaignId ?? $this->campaignId;
        if (empty($id)) {
            return ['success' => false, 'error' => 'Campaign ID не указан'];
        }

        if (empty($offers)) {
            return ['success' => false, 'error' => 'Нет товаров для обновления'];
        }

        $chunks = array_chunk($offers, 2000);
        $errors = [];
        $successCount = 0;

        foreach ($chunks as $index => $chunk) {
            try {
                $data = ['offers' => $chunk];
                $this->request(
                    'POST',
                    "/v2/campaigns/{$id}/offer-prices/updates",
                    $data
                );
                $successCount += count($chunk);
            } catch (Exception $e) {
                $errors[] = "Batch " . ($index + 1) . ": " . $e->getMessage();
            }

            if ($index < count($chunks) - 1) {
                usleep(200000);
            }
        }

        return [
            'success' => empty($errors),
            'updated' => $successCount,
            'errors' => $errors
        ];
    }

    // ==================== МЕТОДЫ API: КАРАНТИН ЦЕН ====================

    /**
     * Получение товаров в карантине по цене (кабинет)
     * POST /v2/businesses/{businessId}/price-quarantine
     */
    public function getQuarantineGoods(): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        try {
            $result = $this->request('POST', "/v2/businesses/{$this->businessId}/price-quarantine", []);
            $quarantine = $result['result']['offers'] ?? [];
            return [
                'success' => true,
                'quarantine' => $quarantine,
                'total' => count($quarantine)
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'quarantine' => []];
        }
    }

    /**
     * Удаление товаров из карантина (подтверждение цены)
     * POST /v2/businesses/{businessId}/price-quarantine/confirm
     *
     * @param array $offerIds Массив offerId для подтверждения
     */
    public function confirmQuarantine(array $offerIds): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        if (empty($offerIds)) {
            return ['success' => false, 'error' => 'Не указаны товары'];
        }

        try {
            // Формат: {"offers": [{"offerId": "SKU1"}, {"offerId": "SKU2"}]}
            $offers = array_map(fn($id) => ['offerId' => $id], $offerIds);

            $this->request(
                'POST',
                "/v2/businesses/{$this->businessId}/price-quarantine/confirm",
                ['offers' => $offers]
            );

            return ['success' => true, 'confirmed' => count($offerIds)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== МЕТОДЫ API: ОСТАТКИ ====================

    /**
     * Обновление остатков на складе
     * PUT /v2/campaigns/{campaignId}/offers/stocks
     *
     * @param array $skus Массив остатков:
     *   [
     *     [
     *       'sku' => 'SKU123',
     *       'warehouseId' => 123456,
     *       'items' => [
     *         ['count' => 10, 'type' => 'FIT', 'updatedAt' => '2024-01-15T10:30:00+03:00']
     *       ]
     *     ],
     *     ...
     *   ]
     * @return array
     */
    public function updateStocks(array $skus): array
    {
        if (empty($this->campaignId)) {
            return ['success' => false, 'error' => 'Campaign ID не указан'];
        }

        if (empty($skus)) {
            return ['success' => false, 'error' => 'Нет товаров для обновления'];
        }

        try {
            $data = ['skus' => $skus];
            $this->request(
                'PUT',
                "/v2/campaigns/{$this->campaignId}/offers/stocks",
                $data
            );

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('ym_update_stocks', 'api', null, null, [
                'campaign_id' => $this->campaignId,
                'stocks_count' => count($skus)
            ]);

            return ['success' => true, 'updated' => count($skus)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Обновление остатков (упрощённая версия)
     *
     * @param array $items Массив [['offer_id' => 'SKU', 'stock' => 10], ...]
     * @return array
     */
    public function uploadStocks(array $items): array
    {
        if (empty($this->warehouseId)) {
            return ['success' => false, 'error' => 'Warehouse ID не указан'];
        }

        $skus = [];
        $now = date('c'); // ISO 8601 формат

        foreach ($items as $item) {
            $skus[] = [
                'sku' => $item['offer_id'],
                'warehouseId' => (int)$this->warehouseId,
                'items' => [
                    [
                        'count' => max(0, (int)$item['stock']),
                        'type' => 'FIT',
                        'updatedAt' => $now
                    ]
                ]
            ];
        }

        return $this->updateStocks($skus);
    }

    /**
     * Обновление остатков с явным указанием склада
     *
     * @param array $items Массив [['offer_id' => 'SKU', 'stock' => 10], ...]
     * @param int $warehouseId ID склада
     * @return array
     */
    public function uploadStocksWithWarehouse(array $items, int $warehouseId): array
    {
        if (empty($warehouseId)) {
            return ['success' => false, 'error' => 'Warehouse ID не указан'];
        }

        $skus = [];
        $now = date('c'); // ISO 8601 формат

        foreach ($items as $item) {
            $offerId = $item['offer_id'] ?? '';
            if (empty($offerId)) continue;

            $skus[] = [
                'sku' => $offerId,
                'warehouseId' => $warehouseId,
                'items' => [
                    [
                        'count' => max(0, (int)($item['stock'] ?? 0)),
                        'type' => 'FIT',
                        'updatedAt' => $now
                    ]
                ]
            ];
        }

        if (empty($skus)) {
            return ['success' => false, 'error' => 'Нет валидных товаров'];
        }

        error_log("[YM API] uploadStocksWithWarehouse: " . count($skus) . " SKU на склад " . $warehouseId);

        return $this->updateStocks($skus);
    }

    // ==================== МЕТОДЫ API: ТАРИФЫ ====================

    /**
     * Калькулятор стоимости услуг
     * POST /v2/tariffs/calculate
     *
     * Лимит: 200 товаров за запрос, 100 запросов в минуту
     *
     * @param array $offers Товары для расчёта:
     *   [
     *     [
     *       'categoryId' => 123,
     *       'price' => 1500,
     *       'length' => 30,  // см
     *       'width' => 20,   // см
     *       'height' => 10,  // см
     *       'weight' => 0.5  // кг
     *     ],
     *     ...
     *   ]
     * @return array
     */
    public function calculateTariffs(array $offers): array
    {
        if (empty($offers)) {
            return ['success' => false, 'error' => 'Нет товаров для расчёта'];
        }

        try {
            $data = [
                'offers' => array_slice($offers, 0, self::LIMIT_TARIFFS_PER_REQUEST),
                'parameters' => []
            ];

            // Используем campaignId если есть, иначе sellingProgram
            if (!empty($this->campaignId)) {
                $data['parameters']['campaignId'] = (int)$this->campaignId;
            } else {
                $data['parameters']['sellingProgram'] = 'FBS'; // По умолчанию FBS
            }

            $result = $this->request('POST', '/v2/tariffs/calculate', $data);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== МЕТОДЫ API: РЕКОМЕНДАЦИИ ====================

    /**
     * Получение рекомендаций по ценам
     * POST /v2/businesses/{businessId}/offers/recommendations
     */
    public function getPriceRecommendations(array $offerIds = []): array
    {
        if (empty($this->businessId)) {
            return ['success' => false, 'error' => 'Business ID не указан'];
        }

        try {
            $data = [];
            if (!empty($offerIds)) {
                $data['offerIds'] = $offerIds;
            }

            $result = $this->request(
                'POST',
                "/v2/businesses/{$this->businessId}/offers/recommendations",
                $data
            );
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== ГЕТТЕРЫ ====================

    public function getBusinessId(): string { return $this->businessId ?? ''; }
    public function getCampaignId(): string { return $this->campaignId ?? ''; }
    public function getWarehouseId(): string { return $this->warehouseId ?? ''; }

    // ==================== ОТЛАДКА ====================

    /**
     * Отладочный метод для тестирования разных endpoints
     */
    public function debugGetOffers(): array
    {
        $results = [];

        error_log("=== YM DEBUG: Testing different endpoints ===");
        error_log("[YM DEBUG] businessId: {$this->businessId}");
        error_log("[YM DEBUG] campaignId: {$this->campaignId}");

        // Тест 1: POST /v2/businesses/{businessId}/offer-mappings
        try {
            error_log("=== DEBUG: Testing offer-mappings endpoint ===");
            $result1 = $this->request('POST', "/v2/businesses/{$this->businessId}/offer-mappings", [], ['limit' => 10]);
            $offerMappings = $result1['result']['offerMappings'] ?? [];
            $firstOffer = !empty($offerMappings) ? $offerMappings[0] : null;
            $results['offer-mappings'] = [
                'success' => true,
                'keys' => array_keys($result1),
                'result_keys' => isset($result1['result']) ? array_keys($result1['result']) : [],
                'offers_count' => count($offerMappings),
                'first_offer_structure' => $firstOffer ? array_keys($firstOffer) : [],
                'first_offer' => $firstOffer // Для диагностики структуры
            ];
        } catch (Exception $e) {
            $results['offer-mappings'] = ['success' => false, 'error' => $e->getMessage()];
        }

        // Тест 2: POST /v2/businesses/{businessId}/offer-prices
        try {
            error_log("=== DEBUG: Testing offer-prices endpoint ===");
            $result2 = $this->request('POST', "/v2/businesses/{$this->businessId}/offer-prices", []);
            $results['offer-prices'] = [
                'success' => true,
                'keys' => array_keys($result2),
                'result_keys' => isset($result2['result']) ? array_keys($result2['result']) : [],
                'offers_count' => count($result2['result']['offers'] ?? [])
            ];
        } catch (Exception $e) {
            $results['offer-prices'] = ['success' => false, 'error' => $e->getMessage()];
        }

        // Тест 3: GET /v2/campaigns
        try {
            error_log("=== DEBUG: Testing campaigns endpoint ===");
            $result3 = $this->request('GET', "/v2/campaigns");
            $campaigns = $result3['campaigns'] ?? [];
            $results['campaigns'] = [
                'success' => true,
                'count' => count($campaigns),
                'campaigns' => array_map(fn($c) => [
                    'id' => $c['id'] ?? null,
                    'domain' => $c['domain'] ?? null,
                    'placementType' => $c['placementType'] ?? null
                ], $campaigns)
            ];
        } catch (Exception $e) {
            $results['campaigns'] = ['success' => false, 'error' => $e->getMessage()];
        }

        // Тест 4: GET /v2/businesses/{businessId}/offer-cards
        try {
            error_log("=== DEBUG: Testing offer-cards endpoint ===");
            $result4 = $this->request('POST', "/v2/businesses/{$this->businessId}/offer-cards", [], ['limit' => 10]);
            $results['offer-cards'] = [
                'success' => true,
                'keys' => array_keys($result4),
                'result_keys' => isset($result4['result']) ? array_keys($result4['result']) : [],
                'offers_count' => count($result4['result']['offerCards'] ?? [])
            ];
        } catch (Exception $e) {
            $results['offer-cards'] = ['success' => false, 'error' => $e->getMessage()];
        }

        return $results;
    }
}
