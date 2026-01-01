<?php
/**
 * Класс для логирования ошибок
 * Записывает ошибки в файл и базу данных
 */
class ErrorLogger
{
    /**
     * Директория для логов
     */
    private static string $logDir = '/var/log/price-manager';

    /**
     * Имя файла логов
     */
    private static string $logFile = 'app.log';

    /**
     * Имя таблицы для логов
     */
    private static string $logTable = 'error_logs';

    /**
     * Флаг защиты от рекурсии
     */
    private static bool $isLogging = false;

    /**
     * Инициализация - создание директории логов
     */
    private static function init(): void
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    /**
     * Записать ошибку в лог
     *
     * @param string $level Уровень ошибки (DEBUG, INFO, WARNING, ERROR, API_ERROR, DB_ERROR, API_OK)
     * @param string $message Сообщение об ошибке
     * @param array $context Дополнительный контекст (stack trace, параметры)
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // Защита от рекурсии
        if (self::$isLogging) {
            return;
        }
        self::$isLogging = true;

        try {
            self::init();

            $entry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => strtoupper($level),
                'message' => mb_substr($message, 0, 1000), // Ограничиваем длину
                'context' => $context,
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'user_id' => $_SESSION['user_id'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ];

            // Записываем в файл
            self::logToFile($entry);

            // Записываем в error_log PHP для Docker логов
            $shortLog = sprintf("[%s] %s: %s", $entry['timestamp'], $entry['level'], mb_substr($entry['message'], 0, 200));
            error_log($shortLog);

        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Записать ошибку уровня ERROR
     *
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Записать предупреждение уровня WARNING
     *
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /**
     * Записать информационное сообщение
     *
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Записать отладочное сообщение
     *
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    /**
     * Записать в файл
     *
     * @param array $entry Запись лога
     */
    private static function logToFile(array $entry): void
    {
        $filePath = self::$logDir . '/' . self::$logFile;

        // Ротация файла если > 10MB
        if (file_exists($filePath) && filesize($filePath) > 10 * 1024 * 1024) {
            @rename($filePath, $filePath . '.' . date('Y-m-d-H-i-s'));
        }

        // Ограничиваем размер контекста
        $contextJson = '';
        if (!empty($entry['context'])) {
            $contextJson = json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($contextJson) > 5000) {
                $contextJson = '{"warning": "context truncated", "size": ' . strlen($contextJson) . '}';
            }
        }

        // Форматируем строку лога
        $logLine = sprintf(
            "[%s] [%s] %s | %s\n",
            $entry['timestamp'],
            $entry['level'],
            $entry['message'],
            $contextJson ?: '{}'
        );

        // Записываем в файл
        @file_put_contents($filePath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Записать в базу данных (вызывается отдельно, не из log())
     *
     * @param array $entry Запись лога
     */
    public static function logToDatabase(array $entry): void
    {
        try {
            $db = Database::getInstance();

            $data = [
                'level' => $entry['level'] ?? 'ERROR',
                'message' => mb_substr($entry['message'] ?? '', 0, 65535),
                'context' => !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE) : null,
                'url' => mb_substr($entry['url'] ?? '', 0, 500),
                'method' => $entry['method'] ?? '',
                'user_id' => $entry['user_id'] ?? null,
                'ip_address' => $entry['ip'] ?? '',
                'user_agent' => mb_substr($entry['user_agent'] ?? '', 0, 500)
            ];

            $db->insert(self::$logTable, $data);
        } catch (Exception $e) {
            // Игнорируем ошибки записи в БД
        }
    }

    /**
     * Получить последние ошибки из БД
     *
     * @param int $limit Количество записей
     * @param string|null $level Фильтр по уровню
     * @param string|null $search Поиск по сообщению
     * @return array
     */
    public static function getRecent(int $limit = 50, ?string $level = null, ?string $search = null): array
    {
        try {
            $db = Database::getInstance();

            $sql = "SELECT el.*, u.username
                    FROM error_logs el
                    LEFT JOIN users u ON u.id = el.user_id
                    WHERE 1=1";
            $params = [];

            // Фильтр по уровню (расширенный список)
            $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'API_ERROR', 'DB_ERROR', 'API_OK'];
            if ($level && in_array(strtoupper($level), $validLevels)) {
                $sql .= " AND el.level = ?";
                $params[] = strtoupper($level);
            }

            // Поиск по сообщению
            if ($search) {
                $sql .= " AND (el.message LIKE ? OR el.url LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $sql .= " ORDER BY el.created_at DESC LIMIT ?";
            $params[] = $limit;

            return $db->fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Получить запись по ID
     *
     * @param int $id ID записи
     * @return array|null
     */
    public static function getById(int $id): ?array
    {
        try {
            $db = Database::getInstance();

            return $db->fetchOne(
                "SELECT el.*, u.username
                 FROM error_logs el
                 LEFT JOIN users u ON u.id = el.user_id
                 WHERE el.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Получить статистику ошибок
     *
     * @return array
     */
    public static function getStatistics(): array
    {
        try {
            $db = Database::getInstance();

            $stats = [];

            // Общее количество
            $stats['total'] = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM error_logs"
            );

            // По уровням
            $stats['by_level'] = $db->fetchAll(
                "SELECT level, COUNT(*) as count
                 FROM error_logs
                 GROUP BY level
                 ORDER BY count DESC"
            );

            // За последние 24 часа
            $stats['last_24h'] = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM error_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );

            // За последний час
            $stats['last_hour'] = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM error_logs
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );

            // Последняя ошибка
            $stats['last_error'] = $db->fetchOne(
                "SELECT * FROM error_logs
                 WHERE level IN ('ERROR', 'API_ERROR', 'DB_ERROR')
                 ORDER BY created_at DESC
                 LIMIT 1"
            );

            return $stats;
        } catch (Exception $e) {
            return [
                'total' => 0,
                'by_level' => [],
                'last_24h' => 0,
                'last_hour' => 0,
                'last_error' => null
            ];
        }
    }

    /**
     * Очистить старые логи (старше указанного количества дней)
     *
     * @param int $daysOld Количество дней
     * @return int Количество удалённых записей
     */
    public static function cleanup(int $daysOld = 7): int
    {
        try {
            $db = Database::getInstance();

            return $db->delete(
                'error_logs',
                'created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$daysOld]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Очистить все логи
     *
     * @return int Количество удалённых записей
     */
    public static function clearAll(): int
    {
        try {
            $db = Database::getInstance();

            return $db->delete('error_logs', '1=1', []);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Логировать исключение
     *
     * @param Throwable $e Исключение
     * @param array $additionalContext Дополнительный контекст
     */
    public static function logException(Throwable $e, array $additionalContext = []): void
    {
        $context = array_merge([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 2000) // Ограничиваем trace
        ], $additionalContext);

        // Определяем уровень по типу исключения
        if ($e instanceof PDOException) {
            self::log('DB_ERROR', 'Database: ' . $e->getMessage(), $context);
        } elseif ($e instanceof InvalidArgumentException) {
            self::warning('Validation: ' . $e->getMessage(), $context);
        } else {
            self::error($e->getMessage(), $context);
        }
    }

    /**
     * Логировать API ошибку (с HTTP кодом)
     *
     * @param string $apiName Название API (Ozon, WB, etc.)
     * @param string $endpoint Endpoint
     * @param int $httpCode HTTP код ответа
     * @param string $response Ответ API
     * @param array $request Данные запроса
     */
    public static function logApiError(
        string $apiName,
        string $endpoint,
        int $httpCode,
        string $response,
        array $request = []
    ): void {
        self::log('API_ERROR', "API {$apiName}: HTTP {$httpCode}", [
            'api' => $apiName,
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => mb_substr($response, 0, 2000),
            'request' => $request
        ]);
    }

    /**
     * Логировать успешное подключение к API
     *
     * @param string $apiName Название API
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public static function apiSuccess(string $apiName, string $message, array $context = []): void
    {
        $context['api'] = $apiName;
        self::log('API_OK', "API {$apiName}: {$message}", $context);
    }

    /**
     * Логировать ошибку API (упрощённый метод)
     *
     * @param string $apiName Название API
     * @param string $endpoint Endpoint
     * @param string $error Текст ошибки
     * @param array $context Дополнительный контекст
     */
    public static function apiError(string $apiName, string $endpoint, string $error, array $context = []): void
    {
        $context['api'] = $apiName;
        $context['endpoint'] = $endpoint;
        self::log('API_ERROR', "API {$apiName}: {$error}", $context);
    }

    /**
     * Логировать ошибку базы данных
     *
     * @param string $query SQL запрос
     * @param string $error Текст ошибки
     * @param array $params Параметры запроса
     */
    public static function dbError(string $query, string $error, array $params = []): void
    {
        self::log('DB_ERROR', "БД: {$error}", [
            'query' => mb_substr($query, 0, 500),
            'params' => array_slice($params, 0, 10), // Ограничиваем количество параметров
            'error' => $error
        ]);
    }

    /**
     * Получить последние логи из файла (безопасно, без загрузки всего файла)
     *
     * @param int $lines Количество строк
     * @return array
     */
    public static function getRecentLogs(int $lines = 100): array
    {
        self::init();
        $filePath = self::$logDir . '/' . self::$logFile;

        if (!file_exists($filePath)) {
            return [];
        }

        // Пробуем использовать tail (Linux/Docker)
        $output = [];
        $command = "tail -n " . (int)$lines . " " . escapeshellarg($filePath) . " 2>/dev/null";
        @exec($command, $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return array_reverse($output);
        }

        // Fallback для Windows или если tail недоступен
        return self::getRecentLogsFallback($filePath, $lines);
    }

    /**
     * Fallback метод для чтения логов (для Windows)
     *
     * @param string $filePath Путь к файлу
     * @param int $lines Количество строк
     * @return array
     */
    private static function getRecentLogsFallback(string $filePath, int $lines): array
    {
        $logs = [];

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        // Ограничиваем размер файла для чтения (макс 1MB)
        $fileSize = filesize($filePath);
        $maxRead = min($fileSize, 1024 * 1024); // 1MB максимум

        if ($fileSize > $maxRead) {
            fseek($handle, -$maxRead, SEEK_END);
            fgets($handle); // Пропускаем неполную первую строку
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!empty($line)) {
                $logs[] = $line;
            }
        }

        fclose($handle);

        // Берём последние N строк
        $logs = array_slice($logs, -$lines);
        return array_reverse($logs);
    }
}
