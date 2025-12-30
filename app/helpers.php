<?php
/**
 * Вспомогательные функции приложения
 */

/**
 * Безопасный вывод HTML
 * @param string|null $string Строка для экранирования
 * @return string Экранированная строка
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Генерация CSRF токена
 * @return string CSRF токен
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Проверка CSRF токена
 * @param string|null $token Токен для проверки
 * @return bool Результат проверки
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Вывод скрытого поля с CSRF токеном
 * @return string HTML код поля
 */
function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(generateCsrfToken()) . '">';
}

/**
 * Редирект на указанный URL
 * @param string $url URL для редиректа
 * @return never
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Проверка, является ли запрос AJAX
 * @return bool
 */
function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Отправка JSON ответа
 * @param mixed $data Данные для отправки
 * @param int $statusCode HTTP статус код
 * @return never
 */
function jsonResponse(mixed $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Получение значения из POST с валидацией
 * @param string $key Ключ параметра
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

/**
 * Получение значения из GET с валидацией
 * @param string $key Ключ параметра
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

/**
 * Установка flash-сообщения
 * @param string $type Тип сообщения (success, error, warning, info)
 * @param string $message Текст сообщения
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Получение и очистка flash-сообщений
 * @return array Массив сообщений
 */
function getFlash(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Проверка наличия flash-сообщений
 * @return bool
 */
function hasFlash(): bool
{
    return !empty($_SESSION['flash_messages']);
}

/**
 * Форматирование цены
 * @param float $price Цена
 * @param string $currency Валюта
 * @return string Отформатированная цена
 */
function formatPrice(float $price, string $currency = 'RUB'): string
{
    $formatted = number_format($price, 2, ',', ' ');
    $symbols = [
        'RUB' => '₽',
        'USD' => '$',
        'EUR' => '€'
    ];
    return $formatted . ' ' . ($symbols[$currency] ?? $currency);
}

/**
 * Форматирование даты
 * @param string|null $date Дата в формате Y-m-d H:i:s
 * @param string $format Формат вывода
 * @return string Отформатированная дата
 */
function formatDate(?string $date, string $format = 'd.m.Y H:i'): string
{
    if (empty($date)) {
        return '-';
    }
    $dateTime = new DateTime($date);
    return $dateTime->format($format);
}

/**
 * Получение IP адреса пользователя
 * @return string IP адрес
 */
function getUserIP(): string
{
    $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return trim($ip);
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Получение User Agent
 * @return string User Agent
 */
function getUserAgent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Валидация email
 * @param string $email Email для проверки
 * @return bool
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Очистка строки от лишних пробелов и опасных символов
 * @param string $string Строка для очистки
 * @return string Очищенная строка
 */
function sanitize(string $string): string
{
    return trim(strip_tags($string));
}

/**
 * Валидация числа
 * @param mixed $value Значение для проверки
 * @param float|null $min Минимальное значение
 * @param float|null $max Максимальное значение
 * @return bool
 */
function isValidNumber(mixed $value, ?float $min = null, ?float $max = null): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    $num = (float)$value;
    if ($min !== null && $num < $min) {
        return false;
    }
    if ($max !== null && $num > $max) {
        return false;
    }
    return true;
}

/**
 * Генерация случайной строки
 * @param int $length Длина строки
 * @return string
 */
function randomString(int $length = 16): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Логирование ошибки в файл
 * @param string $message Сообщение об ошибке
 * @param array $context Контекст ошибки
 */
function logError(string $message, array $context = []): void
{
    $logFile = LOGS_PATH . '/error_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}" . PHP_EOL;

    if (!is_dir(LOGS_PATH)) {
        mkdir(LOGS_PATH, 0755, true);
    }

    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Подключение view файла
 * @param string $view Путь к view (например, 'calculator/index')
 * @param array $data Данные для передачи в view
 */
function view(string $view, array $data = []): void
{
    extract($data);
    $viewFile = VIEWS_PATH . '/' . $view . '.php';

    if (!file_exists($viewFile)) {
        throw new Exception("View file not found: {$view}");
    }

    require $viewFile;
}

/**
 * Проверка метода запроса
 * @param string $method Метод (GET, POST, PUT, DELETE)
 * @return bool
 */
function isMethod(string $method): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD']) === strtoupper($method);
}

/**
 * Получение текущего URL без query string
 * @return string
 */
function currentUrl(): string
{
    return strtok($_SERVER['REQUEST_URI'], '?');
}

/**
 * Проверка активности пункта меню
 * @param string $path Путь для проверки
 * @return string CSS класс 'active' или пустая строка
 */
function isActive(string $path): string
{
    return currentUrl() === $path ? 'active' : '';
}
