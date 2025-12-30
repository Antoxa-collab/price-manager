<?php
/**
 * Конфигурация приложения Price Manager
 * Настройки базы данных и константы
 */

// Режим отладки (в продакшене установить false)
define('DEBUG_MODE', true);

// Настройка отображения ошибок
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Настройки базы данных
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_DATABASE') ?: 'price_manager');
define('DB_USER', getenv('DB_USERNAME') ?: 'price_user');
define('DB_PASS', getenv('DB_PASSWORD') ?: 'price_password');
define('DB_CHARSET', 'utf8mb4');

// Настройки сессии
define('SESSION_NAME', 'price_manager_session');
define('SESSION_LIFETIME', 86400); // 24 часа

// Пути приложения
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEWS_PATH', APP_PATH . '/views');
define('CLASSES_PATH', APP_PATH . '/classes');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOGS_PATH', STORAGE_PATH . '/logs');

// URL приложения
define('BASE_URL', '/');

// API URLs маркетплейсов
define('WB_API_URL', 'https://suppliers-api.wildberries.ru');
define('OZON_API_URL', 'https://api-seller.ozon.ru');

// Настройки безопасности
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_COST', 12); // Стоимость хеширования bcrypt

// Настройки приложения
define('APP_NAME', 'Price Manager');
define('APP_VERSION', '1.0.0');

// Часовой пояс
date_default_timezone_set('Europe/Moscow');

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Автозагрузка классов (простая версия без PSR-4)
spl_autoload_register(function ($class) {
    $file = CLASSES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Подключение хелперов
require_once APP_PATH . '/helpers.php';
