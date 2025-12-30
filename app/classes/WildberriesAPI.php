<?php
/**
 * Класс для работы с API Wildberries
 * Обновление цен и остатков на маркетплейсе
 */
class WildberriesAPI
{
    private Database $db;
    private string $apiToken = '';
    private string $warehouseId = '';
    private int $userId;

    /**
     * Базовый URL API
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
}
