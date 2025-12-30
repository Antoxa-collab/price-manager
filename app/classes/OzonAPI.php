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

        // Логируем запрос (всегда, для отладки)
        logError('Ozon API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'request_body' => $jsonBody,
            'http_code' => $httpCode,
            'response_length' => strlen($response ?? ''),
            'response' => substr($response ?? '', 0, 1000)
        ]);

        // Ошибка cURL (сетевая проблема)
        if ($curlErrno !== 0) {
            throw new Exception('Ошибка соединения с Ozon API: ' . $curlError);
        }

        // Пустой ответ
        if ($response === '' || $response === false) {
            if ($httpCode >= 200 && $httpCode < 300) {
                // Успешный запрос с пустым телом - это нормально для некоторых методов
                return [];
            }
            throw new Exception('Ozon API вернул пустой ответ (HTTP ' . $httpCode . ')');
        }

        // Декодируем JSON ответ
        $result = json_decode($response, true);

        // Проверяем ошибку парсинга JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ozon API вернул некорректный JSON: ' . json_last_error_msg() . '. Ответ: ' . substr($response, 0, 200));
        }

        // HTTP ошибки (4xx, 5xx)
        if ($httpCode >= 400) {
            $errorMessage = $result['message']
                ?? $result['error']
                ?? $result['error_description']
                ?? 'HTTP ошибка ' . $httpCode;

            throw new Exception('Ошибка Ozon API: ' . $errorMessage);
        }

        return $result ?? [];
    }

    /**
     * Проверка подключения к API
     * Использует endpoint /v1/seller/info для получения информации о кабинете
     *
     * @return array Результат проверки
     */
    public function testConnection(): array
    {
        try {
            // Используем простой endpoint для проверки подключения
            // POST /v1/seller/info возвращает информацию о кабинете продавца
            $result = $this->request('POST', '/v1/seller/info', []);

            // Извлекаем название компании из ответа
            $companyName = $result['company']['name'] ?? 'Неизвестно';

            return [
                'success' => true,
                'message' => 'Подключение успешно. Кабинет: ' . $companyName,
                'data' => [
                    'company_name' => $companyName,
                    'company' => $result['company'] ?? []
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Получение списка складов
     * Метод: POST /v1/warehouse/list
     *
     * @return array Список складов
     */
    public function getWarehouses(): array
    {
        try {
            $result = $this->request('POST', '/v1/warehouse/list', []);

            return [
                'success' => true,
                'message' => 'Склады получены',
                'data' => $result['result'] ?? $result
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
     * @param array $stocks Массив остатков [['product_id' => int, 'stock' => int], ...]
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
                'product_id' => (int)$item['product_id'],
                'stock' => (int)$item['stock'],
                'warehouse_id' => (int)$this->warehouseId
            ];
        }

        try {
            $result = $this->request('POST', '/v2/products/stocks', $data);

            // Логируем операцию
            $log = new OperationsLog();
            $log->add('ozon_update_stocks', 'api', null, null, [
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
}
