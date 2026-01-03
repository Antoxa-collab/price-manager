<?php
/**
 * Класс для работы с Claude API (Anthropic)
 * Обеспечивает генерацию ответов на отзывы и вопросы
 */
class ClaudeAPI
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1';
    private string $model = 'claude-3-haiku-20240307';
    private int $maxTokens = 1024;
    private float $temperature = 0.7;
    private int $timeout = 60;

    // Retry настройки
    private int $maxRetries = 3;
    private int $retryDelay = 2; // базовая задержка в секундах

    // Коды ошибок для повторных попыток
    private array $retryableHttpCodes = [429, 500, 502, 503, 529];
    private array $retryableErrorTypes = ['overloaded_error', 'rate_limit_error', 'api_error'];

    // Статистика последнего запроса
    private ?array $lastUsage = null;
    private ?int $lastGenerationTime = null;
    private ?string $lastError = null;

    /**
     * Конструктор
     * @param string $apiKey API ключ Anthropic
     * @param array $options Дополнительные опции
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (empty($apiKey)) {
            throw new InvalidArgumentException('Claude API key is required');
        }

        $this->apiKey = $apiKey;

        if (isset($options['model'])) {
            $this->model = $options['model'];
        }
        if (isset($options['max_tokens'])) {
            $this->maxTokens = (int)$options['max_tokens'];
        }
        if (isset($options['temperature'])) {
            $this->temperature = (float)$options['temperature'];
        }
        if (isset($options['timeout'])) {
            $this->timeout = (int)$options['timeout'];
        }
    }

    /**
     * Установить модель
     * @param string $model
     * @return self
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Установить максимальное количество токенов
     * @param int $maxTokens
     * @return self
     */
    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    /**
     * Установить температуру
     * @param float $temperature
     * @return self
     */
    public function setTemperature(float $temperature): self
    {
        $this->temperature = max(0, min(1, $temperature));
        return $this;
    }

    /**
     * Генерация текста
     * @param string $systemPrompt Системный промпт
     * @param string $userPrompt Пользовательский промпт
     * @param array $examples Примеры для few-shot learning
     * @return string|null Сгенерированный текст или null при ошибке
     */
    public function generate(string $systemPrompt, string $userPrompt, array $examples = []): ?string
    {
        $this->lastError = null;
        $this->lastUsage = null;
        $this->lastGenerationTime = null;

        $messages = [];

        // Добавляем примеры для few-shot learning
        foreach ($examples as $example) {
            if (!empty($example['input']) && !empty($example['output'])) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $example['input']
                ];
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $example['output']
                ];
            }
        }

        // Добавляем основной запрос
        $messages[] = [
            'role' => 'user',
            'content' => $userPrompt
        ];

        $requestData = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'system' => $systemPrompt,
            'messages' => $messages
        ];

        $startTime = microtime(true);

        try {
            $response = $this->request('/messages', $requestData);

            $this->lastGenerationTime = (int)((microtime(true) - $startTime) * 1000);

            if (isset($response['usage'])) {
                $this->lastUsage = [
                    'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                    'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
                ];
            }

            // Извлекаем текст из ответа
            if (isset($response['content']) && is_array($response['content'])) {
                foreach ($response['content'] as $block) {
                    if ($block['type'] === 'text') {
                        return trim($block['text']);
                    }
                }
            }

            $this->lastError = 'No text content in response';
            return null;

        } catch (Exception $e) {
            $this->lastGenerationTime = (int)((microtime(true) - $startTime) * 1000);
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Выполнение HTTP запроса к API с retry логикой
     * @param string $endpoint Эндпоинт API
     * @param array $data Данные запроса
     * @return array Ответ API
     * @throws Exception При ошибке запроса
     */
    private function request(string $endpoint, array $data): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->doRequest($endpoint, $data, $attempt);
            } catch (Exception $e) {
                $lastException = $e;

                // Проверяем можно ли повторить запрос
                if ($this->isRetryableError($e) && $attempt < $this->maxRetries) {
                    // Экспоненциальная задержка: 2с, 4с, 8с...
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    error_log("[Claude API] Attempt {$attempt}/{$this->maxRetries} failed: {$e->getMessage()}");
                    error_log("[Claude API] Retrying in {$delay} seconds...");
                    sleep($delay);
                    continue;
                }

                // Для не-retry ошибок или последней попытки — выбрасываем исключение
                throw $e;
            }
        }

        // Все попытки исчерпаны
        throw new Exception(
            "Claude API недоступен после {$this->maxRetries} попыток: " .
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Проверить является ли ошибка временной (можно повторить)
     * @param Exception $e
     * @return bool
     */
    private function isRetryableError(Exception $e): bool
    {
        $message = $e->getMessage();

        // Проверяем HTTP коды
        foreach ($this->retryableHttpCodes as $code) {
            if (strpos($message, "HTTP {$code}") !== false || strpos($message, "(HTTP {$code})") !== false) {
                return true;
            }
        }

        // Проверяем типы ошибок
        foreach ($this->retryableErrorTypes as $errorType) {
            if (strpos($message, $errorType) !== false) {
                return true;
            }
        }

        // cURL ошибки таймаута тоже можно повторить
        if (strpos($message, 'cURL error') !== false &&
            (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false)) {
            return true;
        }

        return false;
    }

    /**
     * Выполнение одного HTTP запроса к API
     * @param string $endpoint Эндпоинт API
     * @param array $data Данные запроса
     * @param int $attempt Номер попытки
     * @return array Ответ API
     * @throws Exception При ошибке запроса
     */
    private function doRequest(string $endpoint, array $data, int $attempt = 1): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ];

        error_log("=== Claude API Request (attempt {$attempt}/{$this->maxRetries}) ===");
        error_log("URL: " . $url);
        error_log("Model in request: " . ($data['model'] ?? 'not set'));

        $ch = curl_init();

        // Прокси для обхода блокировки Claude API из России
        $proxyUrl = getenv('CLAUDE_PROXY');
        if (!empty($proxyUrl)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            error_log("Using proxy: " . $proxyUrl);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        error_log("=== Claude API Response ===");
        error_log("HTTP Code: " . $httpCode);
        if ($error) {
            error_log("cURL Error: " . $error);
        }
        error_log("Response (first 500 chars): " . substr($response, 0, 500));

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        $responseData = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMessage = $responseData['error']['message'] ?? 'Unknown API error';
            $errorType = $responseData['error']['type'] ?? 'unknown';
            error_log("API Error: [{$errorType}] {$errorMessage}");
            throw new Exception("Claude API error [{$errorType}]: {$errorMessage} (HTTP {$httpCode})");
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from API');
        }

        return $responseData;
    }

    /**
     * Получить статистику использования последнего запроса
     * @return array|null
     */
    public function getLastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * Получить время генерации последнего запроса (мс)
     * @return int|null
     */
    public function getLastGenerationTime(): ?int
    {
        return $this->lastGenerationTime;
    }

    /**
     * Получить последнюю ошибку
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Проверить валидность API ключа
     * @return bool
     */
    public function validateApiKey(): bool
    {
        try {
            error_log("=== Claude API Validation ===");
            error_log("Model: " . $this->model);
            error_log("[ClaudeAPI] API Key configured (length: " . strlen($this->apiKey) . " chars)");
            error_log("Base URL: " . $this->baseUrl);

            // Минимальный запрос для проверки ключа
            $response = $this->generate(
                'You are a helpful assistant.',
                'Say "OK" and nothing else.',
                []
            );

            error_log("Validation response: " . ($response !== null ? "SUCCESS" : "FAILED"));
            if ($this->lastError) {
                error_log("Last error: " . $this->lastError);
            }

            return $response !== null;
        } catch (Exception $e) {
            error_log("Validation exception: " . $e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Получить список доступных моделей
     * @return array
     */
    public static function getAvailableModels(): array
    {
        return [
            'claude-3-haiku-20240307' => 'Claude 3 Haiku (Fast & Cheap)',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet (Balanced)',
            'claude-3-opus-20240229' => 'Claude 3 Opus (Most Capable)',
        ];
    }

    /**
     * Получить текущую модель
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
