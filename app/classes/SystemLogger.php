<?php
/**
 * Системный логгер для Price Manager
 * Записывает логи в БД для отображения на странице диагностики
 */
class SystemLogger
{
    // Уровни логов
    const ERROR = 'ERROR';
    const WARN = 'WARN';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';
    const OK = 'OK';

    // Категории
    const CALC = 'CALC';           // Калькулятор цен
    const OZON_API = 'OZON_API';   // Ozon API
    const WB_API = 'WB_API';       // Wildberries API
    const YM_API = 'YM_API';       // Яндекс Маркет API
    const DB = 'DB';               // База данных
    const AUTH = 'AUTH';           // Авторизация
    const SYS = 'SYS';             // Системные события
    const USER = 'USER';           // Действия пользователя

    /** @var string|null UUID текущего запроса для группировки логов */
    private static ?string $requestId = null;

    /** @var bool Включено ли логирование */
    private static bool $enabled = true;

    /** @var array Буфер логов для batch-записи */
    private static array $buffer = [];

    /** @var int Максимальный размер буфера перед flush */
    private static int $bufferSize = 10;

    /**
     * Получить или создать request_id для группировки логов
     */
    public static function getRequestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = self::generateUuid();
        }
        return self::$requestId;
    }

    /**
     * Генерация UUID v4
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Основной метод логирования
     */
    public static function log(
        string $level,
        string $category,
        string $message,
        array $context = [],
        ?int $durationMs = null
    ): bool {
        if (!self::$enabled) {
            return false;
        }

        try {
            $db = Database::getInstance();

            // Получаем информацию о пользователе и запросе
            $userId = null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $url = $_SERVER['REQUEST_URI'] ?? null;

            // Попробуем получить user_id из сессии
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }

            // Определяем источник вызова
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $source = null;
            if (isset($backtrace[1])) {
                $file = basename($backtrace[1]['file'] ?? '');
                $line = $backtrace[1]['line'] ?? 0;
                $source = "{$file}:{$line}";
            }

            // Подготовка context
            $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;

            // Вставка в БД
            $db->query(
                "INSERT INTO system_logs
                (level, category, message, context, source, url, user_id, ip_address, user_agent, duration_ms, request_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $level,
                    $category,
                    $message,
                    $contextJson,
                    $source,
                    $url ? substr($url, 0, 500) : null,
                    $userId,
                    $ipAddress,
                    $userAgent ? substr($userAgent, 0, 500) : null,
                    $durationMs,
                    self::getRequestId()
                ]
            );

            return true;

        } catch (Exception $e) {
            // Если не удалось записать в БД - пишем в error_log
            error_log("[SystemLogger] Failed to log: " . $e->getMessage());
            error_log("[SystemLogger] Original message: [{$level}] [{$category}] {$message}");
            return false;
        }
    }

    // ==================== Удобные методы по уровням ====================

    /**
     * Логировать ошибку
     */
    public static function error(string $category, string $message, array $context = []): bool
    {
        return self::log(self::ERROR, $category, $message, $context);
    }

    /**
     * Логировать предупреждение
     */
    public static function warn(string $category, string $message, array $context = []): bool
    {
        return self::log(self::WARN, $category, $message, $context);
    }

    /**
     * Логировать информацию
     */
    public static function info(string $category, string $message, array $context = []): bool
    {
        return self::log(self::INFO, $category, $message, $context);
    }

    /**
     * Логировать отладочную информацию
     */
    public static function debug(string $category, string $message, array $context = []): bool
    {
        return self::log(self::DEBUG, $category, $message, $context);
    }

    /**
     * Логировать успешную операцию
     */
    public static function ok(string $category, string $message, array $context = [], ?int $durationMs = null): bool
    {
        return self::log(self::OK, $category, $message, $context, $durationMs);
    }

    // ==================== Удобные методы по категориям ====================

    /**
     * Лог калькулятора
     */
    public static function calc(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::CALC, $message, $context);
    }

    /**
     * Лог Ozon API
     */
    public static function ozonApi(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::OZON_API, $message, $context);
    }

    /**
     * Лог Wildberries API
     */
    public static function wbApi(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::WB_API, $message, $context);
    }

    /**
     * Лог Яндекс Маркет API
     */
    public static function ymApi(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::YM_API, $message, $context);
    }

    /**
     * Лог БД
     */
    public static function db(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::DB, $message, $context);
    }

    /**
     * Лог авторизации
     */
    public static function auth(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::AUTH, $message, $context);
    }

    /**
     * Лог действий пользователя
     */
    public static function user(string $message, array $context = [], string $level = self::INFO): bool
    {
        return self::log($level, self::USER, $message, $context);
    }

    // ==================== Управление логгером ====================

    /**
     * Включить/выключить логирование
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Получить статистику логов
     */
    public static function getStats(?string $period = '24h'): array
    {
        try {
            $db = Database::getInstance();

            // Определяем период
            $periodCondition = '';
            switch ($period) {
                case '1h':
                    $periodCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
                    break;
                case '24h':
                    $periodCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                    break;
                case '7d':
                    $periodCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case '30d':
                    $periodCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
                default:
                    $periodCondition = '';
            }

            // Общее количество
            $total = $db->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE 1=1 {$periodCondition}");

            // По уровням
            $byLevel = $db->fetchAll(
                "SELECT level, COUNT(*) as count FROM system_logs WHERE 1=1 {$periodCondition} GROUP BY level"
            );
            $levels = [];
            foreach ($byLevel as $row) {
                $levels[$row['level']] = (int)$row['count'];
            }

            // По категориям
            $byCategory = $db->fetchAll(
                "SELECT category, COUNT(*) as count FROM system_logs WHERE 1=1 {$periodCondition} GROUP BY category ORDER BY count DESC LIMIT 10"
            );

            // За последние 24 часа
            $today = $db->fetchColumn(
                "SELECT COUNT(*) FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );

            // Последняя активность
            $lastActivity = $db->fetchColumn("SELECT MAX(created_at) FROM system_logs");

            // Процент успешных
            $okCount = $levels['OK'] ?? 0;
            $errorCount = $levels['ERROR'] ?? 0;
            $successRate = $total > 0 ? round((($total - $errorCount) / $total) * 100, 1) : 100;

            return [
                'total' => (int)$total,
                'today' => (int)$today,
                'errors' => $errorCount,
                'warnings' => $levels['WARN'] ?? 0,
                'info' => $levels['INFO'] ?? 0,
                'debug' => $levels['DEBUG'] ?? 0,
                'ok' => $okCount,
                'success_rate' => $successRate,
                'last_activity' => $lastActivity,
                'by_category' => $byCategory
            ];

        } catch (Exception $e) {
            error_log("[SystemLogger] getStats failed: " . $e->getMessage());
            return [
                'total' => 0,
                'today' => 0,
                'errors' => 0,
                'warnings' => 0,
                'success_rate' => 100,
                'last_activity' => null,
                'by_category' => []
            ];
        }
    }

    /**
     * Получить логи с фильтрацией
     */
    public static function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        try {
            $db = Database::getInstance();

            $where = ['1=1'];
            $params = [];

            // Фильтр по уровню
            if (!empty($filters['level'])) {
                $levels = is_array($filters['level']) ? $filters['level'] : explode(',', $filters['level']);
                $placeholders = implode(',', array_fill(0, count($levels), '?'));
                $where[] = "level IN ({$placeholders})";
                $params = array_merge($params, $levels);
            }

            // Фильтр по категории
            if (!empty($filters['category'])) {
                $categories = is_array($filters['category']) ? $filters['category'] : explode(',', $filters['category']);
                $placeholders = implode(',', array_fill(0, count($categories), '?'));
                $where[] = "category IN ({$placeholders})";
                $params = array_merge($params, $categories);
            }

            // Фильтр по периоду
            if (!empty($filters['from'])) {
                $where[] = "created_at >= ?";
                $params[] = $filters['from'];
            }
            if (!empty($filters['to'])) {
                $where[] = "created_at <= ?";
                $params[] = $filters['to'];
            }

            // Поиск по тексту
            if (!empty($filters['search'])) {
                $where[] = "(message LIKE ? OR context LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Фильтр по user_id
            if (!empty($filters['user_id'])) {
                $where[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }

            $whereClause = implode(' AND ', $where);

            // Получаем общее количество
            $totalCount = $db->fetchColumn(
                "SELECT COUNT(*) FROM system_logs WHERE {$whereClause}",
                $params
            );

            // Получаем логи
            $params[] = $limit;
            $params[] = $offset;

            $logs = $db->fetchAll(
                "SELECT id, created_at, level, category, message, context, source, url, user_id, ip_address, duration_ms, request_id
                 FROM system_logs
                 WHERE {$whereClause}
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?",
                $params
            );

            // Декодируем JSON context
            foreach ($logs as &$log) {
                if ($log['context']) {
                    $log['context'] = json_decode($log['context'], true);
                }
            }

            return [
                'logs' => $logs,
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset
            ];

        } catch (Exception $e) {
            error_log("[SystemLogger] getLogs failed: " . $e->getMessage());
            return ['logs' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }
    }

    /**
     * Очистка старых логов
     */
    public static function cleanup(int $daysOld = 30): int
    {
        try {
            $db = Database::getInstance();
            return $db->execute(
                "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$daysOld]
            );
        } catch (Exception $e) {
            error_log("[SystemLogger] cleanup failed: " . $e->getMessage());
            return 0;
        }
    }
}
