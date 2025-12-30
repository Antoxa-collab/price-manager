<?php
/**
 * Точка входа приложения Price Manager
 * Роутинг и обработка запросов
 */

// Подключение конфигурации
require_once dirname(__DIR__) . '/app/config.php';

// Получаем путь запроса
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Инициализация авторизации
$auth = new Auth();

// Публичные маршруты (без авторизации)
$publicRoutes = ['/login'];

// Проверка авторизации для защищённых маршрутов
if (!in_array($requestUri, $publicRoutes) && !$auth->isLoggedIn()) {
    // AJAX запросы возвращают JSON
    if (isAjax()) {
        jsonResponse(['success' => false, 'message' => 'Требуется авторизация'], 401);
    }
    redirect('/login');
}

// Роутинг
try {
    switch ($requestUri) {
        // ==================== Страницы ====================

        // Главная (калькулятор)
        case '/':
        case '/calculator':
            $auth->requireLogin();
            view('calculator/index', ['auth' => $auth]);
            break;

        // Страница входа
        case '/login':
            if ($auth->isLoggedIn()) {
                redirect('/');
            }

            $error = '';
            $username = '';

            if (isMethod('POST')) {
                // Проверка CSRF
                if (!verifyCsrfToken(post(CSRF_TOKEN_NAME))) {
                    $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
                } else {
                    $username = post('username', '');
                    $password = post('password', '');

                    if ($auth->login($username, $password)) {
                        setFlash('success', 'Добро пожаловать!');
                        redirect('/');
                    } else {
                        $error = 'Неверный логин или пароль';
                    }
                }
            }

            view('auth/login', ['error' => $error, 'username' => $username]);
            break;

        // Выход
        case '/logout':
            $auth->logout();
            setFlash('info', 'Вы вышли из системы');
            redirect('/login');
            break;

        // Товары
        case '/products':
            $auth->requireLogin();
            $calculator = new Calculator();
            $products = $calculator->getProducts();
            view('calculator/index', ['auth' => $auth, 'products' => $products]);
            break;

        // История операций
        case '/history':
            $auth->requireLogin();
            $log = new OperationsLog();
            $history = $log->getEntries([], 100);
            view('history/index', ['auth' => $auth, 'history' => $history]);
            break;

        // Настройки
        case '/settings':
            $auth->requireLogin();
            view('settings/index', ['auth' => $auth]);
            break;

        // ==================== API Endpoints ====================

        // Расчёт цен
        case '/api/calculate':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $basePrice = (float)post('base_price', 0);
            $markups = [
                'retail' => (float)post('markup_retail', 0),
                'medium' => (float)post('markup_medium', 0),
                'wholesale' => (float)post('markup_wholesale', 0)
            ];

            if ($basePrice <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите корректную цену']);
            }

            $calculator = new Calculator();
            $results = $calculator->calculate($basePrice, $markups);

            jsonResponse(['success' => true, 'data' => $results]);
            break;

        // Сохранение товара
        case '/api/product/save':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            if (!verifyCsrfToken(post(CSRF_TOKEN_NAME))) {
                jsonResponse(['success' => false, 'message' => 'Ошибка безопасности'], 403);
            }

            $data = [
                'material_name' => post('material_name'),
                'material_type' => post('material'),
                'grade' => post('grade'),
                'thickness' => post('thickness'),
                'base_price' => post('base_price'),
                'markup_retail' => post('markup_retail'),
                'seller_article' => post('seller_article'),
                'wb_article' => post('wb_article'),
                'ozon_article' => post('ozon_article'),
                'stock' => post('stock'),
                'price_rounded' => post('price_rounded'),
                'wb_price' => post('wb_price'),
                'ozon_price' => post('ozon_price'),
                'user_id' => $auth->getUserId()
            ];

            $calculator = new Calculator();
            $productId = $calculator->saveProduct($data);

            jsonResponse([
                'success' => true,
                'message' => 'Товар сохранён',
                'product_id' => $productId
            ]);
            break;

        // Загрузка цен на WB
        case '/api/wb/prices':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $nmId = post('nm_id');
            $price = (float)post('price');

            if (empty($nmId) || $price <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите артикул WB и цену']);
            }

            $wbApi = new WildberriesAPI($auth->getUserId());

            if (!$wbApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Wildberries не настроен']);
            }

            $result = $wbApi->updatePrices([
                ['nmId' => $nmId, 'price' => $price]
            ]);

            jsonResponse($result);
            break;

        // Загрузка цен на Ozon
        case '/api/ozon/prices':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = post('product_id');
            $price = (float)post('price');

            if (empty($productId) || $price <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите артикул Ozon и цену']);
            }

            $ozonApi = new OzonAPI($auth->getUserId());

            if (!$ozonApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Ozon не настроен']);
            }

            $result = $ozonApi->updatePrices([
                ['product_id' => $productId, 'price' => $price]
            ]);

            jsonResponse($result);
            break;

        // Обнуление остатков WB
        case '/api/wb/zero-stock':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $sku = post('sku');

            if (empty($sku)) {
                jsonResponse(['success' => false, 'message' => 'Укажите артикул продавца']);
            }

            $wbApi = new WildberriesAPI($auth->getUserId());

            if (!$wbApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Wildberries не настроен']);
            }

            $result = $wbApi->zeroStock($sku);
            jsonResponse($result);
            break;

        // Обнуление остатков Ozon
        case '/api/ozon/zero-stock':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = post('product_id');

            if (empty($productId)) {
                jsonResponse(['success' => false, 'message' => 'Укажите артикул Ozon']);
            }

            $ozonApi = new OzonAPI($auth->getUserId());

            if (!$ozonApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Ozon не настроен']);
            }

            $result = $ozonApi->zeroStock((int)$productId);
            jsonResponse($result);
            break;

        // Сохранение настроек WB
        case '/api/settings/wb':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $apiToken = post('api_token', '');
            $warehouseId = post('warehouse_id', '');

            $wbApi = new WildberriesAPI($auth->getUserId());
            $wbApi->saveSettings($apiToken, $warehouseId);

            jsonResponse(['success' => true, 'message' => 'Настройки сохранены']);
            break;

        // Сохранение настроек Ozon
        case '/api/settings/ozon':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $clientId = post('client_id', '');
            $apiKey = post('api_key', '');
            $warehouseId = post('warehouse_id', '');

            $ozonApi = new OzonAPI($auth->getUserId());
            $ozonApi->saveSettings($clientId, $apiKey, $warehouseId);

            jsonResponse(['success' => true, 'message' => 'Настройки сохранены']);
            break;

        // Проверка подключения WB
        case '/api/test/wb':
            $auth->requireLogin();

            $wbApi = new WildberriesAPI($auth->getUserId());

            if (!$wbApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API не настроен']);
            }

            $result = $wbApi->getWarehouses();
            jsonResponse($result);
            break;

        // Проверка подключения Ozon
        case '/api/test/ozon':
            $auth->requireLogin();

            $ozonApi = new OzonAPI($auth->getUserId());

            if (!$ozonApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API не настроен']);
            }

            // Используем testConnection() для проверки - вызывает /v1/seller/info
            $result = $ozonApi->testConnection();
            jsonResponse($result);
            break;

        // ==================== 404 ====================
        default:
            http_response_code(404);

            if (isAjax()) {
                jsonResponse(['success' => false, 'message' => 'Страница не найдена'], 404);
            }

            echo '<!DOCTYPE html>
            <html lang="ru" data-bs-theme="dark">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>404 - Страница не найдена</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body class="bg-dark text-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
                <div class="text-center">
                    <h1 class="display-1">404</h1>
                    <p class="lead">Страница не найдена</p>
                    <a href="/" class="btn btn-primary">На главную</a>
                </div>
            </body>
            </html>';
            break;
    }
} catch (Exception $e) {
    logError('Router error: ' . $e->getMessage());

    if (isAjax()) {
        jsonResponse([
            'success' => false,
            'message' => DEBUG_MODE ? $e->getMessage() : 'Произошла ошибка'
        ], 500);
    }

    http_response_code(500);
    echo '<!DOCTYPE html>
    <html lang="ru" data-bs-theme="dark">
    <head>
        <meta charset="UTF-8">
        <title>Ошибка</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-dark text-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="text-center">
            <h1 class="display-1">500</h1>
            <p class="lead">Произошла ошибка сервера</p>';

    if (DEBUG_MODE) {
        echo '<div class="alert alert-danger mt-3 text-start"><pre>' . e($e->getMessage()) . '</pre></div>';
    }

    echo '<a href="/" class="btn btn-primary mt-3">На главную</a>
        </div>
    </body>
    </html>';
}
