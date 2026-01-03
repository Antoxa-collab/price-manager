<?php
/**
 * Точка входа приложения Price Manager
 * Роутинг и обработка запросов
 */

// Подключение конфигурации
require_once dirname(__DIR__) . '/app/config.php';

// Подключение глобального обработчика ошибок
require_once dirname(__DIR__) . '/app/error_handler.php';

// Проверяем и создаём таблицы при необходимости
try {
    Database::getInstance()->ensureTables();
} catch (Exception $e) {
    error_log('Ошибка инициализации БД: ' . $e->getMessage());
}

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

        // Страница товаров
        case '/products':
            $auth->requireLogin();
            view('products/index', ['auth' => $auth]);
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

        // Ozon Калькулятор
        case '/ozon':
            $auth->requireLogin();
            $calculator = new Calculator();
            $categories = $calculator->getCategories();
            view('ozon/index', ['auth' => $auth, 'categories' => $categories]);
            break;

        // Ozon Сопоставления
        case '/ozon/mapping':
            $auth->requireLogin();
            view('ozon/mapping', ['auth' => $auth]);
            break;

        // Wildberries Калькулятор
        case '/wildberries':
        case '/wildberries/calculator':
            $auth->requireLogin();
            view('wildberries/index', ['auth' => $auth]);
            break;

        // Wildberries Сопоставления
        case '/wildberries/mapping':
            $auth->requireLogin();
            view('wildberries/mapping', ['auth' => $auth]);
            break;

        // Логи ошибок (только для админов)
        case '/logs':
            $auth->requireLogin();
            if ($auth->getUserRole() !== 'admin') {
                redirect('/');
            }
            view('logs/index', ['auth' => $auth]);
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

        // ==================== Ozon Calculator API ====================

        // Получение товаров для калькулятора Ozon
        case '/api/ozon/products':
            $auth->requireLogin();

            $calculator = new Calculator();
            $filters = [
                'category' => get('category', ''),
                'grade' => get('grade', ''),
                'thickness' => get('thickness', ''),
                'search' => get('search', '')
            ];

            $products = $calculator->getProductsForOzonCalculator('ozon', $filters);
            $calculatedProducts = $calculator->calculateOzonPricesBulk($products);

            jsonResponse(['success' => true, 'products' => $calculatedProducts]);
            break;

        // Получение статистики Ozon
        case '/api/ozon/statistics':
            $auth->requireLogin();

            try {
                $mapping = new ProductMapping();
                $cache = new OzonProductCache();

                $stats = $mapping->getStatistics('ozon');
                $stats['last_sync'] = $cache->getLastSyncTime();
                $stats['success'] = true;

                jsonResponse($stats);
            } catch (Exception $e) {
                ErrorLogger::error('Ozon statistics error', ['error' => $e->getMessage()]);
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'error_type' => 'database'
                ], 500);
            }
            break;

        // Получение наших товаров (не сопоставленных)
        case '/api/ozon/our-products':
            $auth->requireLogin();

            try {
                $calculator = new Calculator();
                $products = $calculator->getProducts(1000, 0);

                jsonResponse(['success' => true, 'products' => $products]);
            } catch (Exception $e) {
                ErrorLogger::error('Get our products error', ['error' => $e->getMessage()]);
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'error_type' => 'database'
                ], 500);
            }
            break;

        // Получение товаров из кэша Ozon
        case '/api/ozon/cached-products':
            $auth->requireLogin();

            try {
                $cache = new OzonProductCache();
                $products = $cache->getAll();

                jsonResponse(['success' => true, 'products' => $products]);
            } catch (Exception $e) {
                ErrorLogger::error('Get cached products error', ['error' => $e->getMessage()]);
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'error_type' => 'database'
                ], 500);
            }
            break;

        // Получение сопоставлений
        case '/api/ozon/mappings':
            $auth->requireLogin();

            try {
                $mapping = new ProductMapping();
                $mappings = $mapping->getMappedProducts('ozon');

                jsonResponse(['success' => true, 'mappings' => $mappings]);
            } catch (Exception $e) {
                ErrorLogger::error('Get mappings error', ['error' => $e->getMessage()]);
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'error_type' => 'database'
                ], 500);
            }
            break;

        // Синхронизация товаров с Ozon
        case '/api/ozon/sync':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            try {
                $ozonApi = new OzonAPI($auth->getUserId());

                if (!$ozonApi->isConfigured()) {
                    jsonResponse(['success' => false, 'message' => 'API Ozon не настроен', 'error_type' => 'config']);
                }

                $cache = new OzonProductCache();
                $result = $cache->syncFromApi($ozonApi);

                if ($result['success']) {
                    ErrorLogger::info('Ozon sync completed', [
                        'created' => $result['created'] ?? 0,
                        'updated' => $result['updated'] ?? 0,
                        'total' => $result['total'] ?? 0
                    ]);

                    jsonResponse([
                        'success' => true,
                        'message' => sprintf(
                            'Синхронизировано: %d товаров (новых: %d, обновлённых: %d)',
                            $result['total'],
                            $result['created'],
                            $result['updated']
                        )
                    ]);
                } else {
                    jsonResponse(['success' => false, 'message' => implode(', ', $result['errors']), 'error_type' => 'sync']);
                }
            } catch (PDOException $e) {
                ErrorLogger::dbError('ozon sync', $e->getMessage());
                jsonResponse([
                    'success' => false,
                    'error' => 'Ошибка БД: ' . $e->getMessage(),
                    'error_type' => 'database',
                    'error_code' => $e->getCode()
                ], 500);
            } catch (Exception $e) {
                ErrorLogger::error('Ozon sync failed', ['error' => $e->getMessage()]);
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'error_type' => 'general'
                ], 500);
            }
            break;

        // Получение списка складов Ozon
        case '/api/ozon/warehouses':
            $auth->requireLogin();

            try {
                $ozonApi = new OzonAPI($auth->getUserId());

                if (!$ozonApi->isConfigured()) {
                    jsonResponse(['success' => false, 'message' => 'API Ozon не настроен']);
                }

                $result = $ozonApi->getWarehouses();

                // Добавляем информацию о статусах для UI
                if ($result['success'] && !empty($result['warehouses'])) {
                    foreach ($result['warehouses'] as &$wh) {
                        $status = $wh['status'] ?? 'unknown';
                        $wh['is_active'] = in_array($status, ['enabled', 'working', 'created']);
                        $wh['status_text'] = match($status) {
                            'enabled', 'working' => 'Активен',
                            'created' => 'Создан',
                            'disabled' => 'Отключён',
                            'archived' => 'В архиве',
                            default => $status
                        };
                    }
                    unset($wh);
                }

                jsonResponse($result);

            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Создание сопоставления
        case '/api/ozon/create-mapping':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('product_id');
            $marketplaceProductId = post('marketplace_product_id');

            // Валидация обязательных полей
            if ($productId <= 0) {
                ErrorLogger::error('Create mapping: product_id is missing', ['received' => post('product_id')]);
                jsonResponse(['success' => false, 'message' => 'Не указан наш товар (product_id)'], 400);
            }

            if (empty($marketplaceProductId)) {
                ErrorLogger::error('Create mapping: marketplace_product_id is missing', [
                    'product_id' => $productId,
                    'post_data' => $_POST,
                    'raw_input' => file_get_contents('php://input')
                ]);
                jsonResponse(['success' => false, 'message' => 'Не указан ID товара Ozon (marketplace_product_id)'], 400);
            }

            $marketplaceData = [
                'product_id' => $marketplaceProductId,
                'sku' => post('marketplace_sku'),
                'offer_id' => post('marketplace_offer_id'),
                'name' => post('marketplace_name'),
                'quantity_in_pack' => (int)post('quantity_in_pack', 1)
            ];

            $mapping = new ProductMapping();
            $mappingId = $mapping->addMapping($productId, 'ozon', $marketplaceData);

            jsonResponse([
                'success' => true,
                'message' => 'Сопоставление создано',
                'mapping_id' => $mappingId
            ]);
            break;

        // Удаление сопоставления
        case '/api/ozon/delete-mapping':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $mappingId = (int)post('mapping_id');

            $mapping = new ProductMapping();
            $result = $mapping->deleteMapping($mappingId);

            jsonResponse([
                'success' => $result,
                'message' => $result ? 'Сопоставление удалено' : 'Ошибка удаления'
            ]);
            break;

        // Обновление количества в упаковке
        case '/api/ozon/update-quantity':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $mappingId = (int)post('mapping_id');
            $quantity = (int)post('quantity_in_pack', 1);

            $mapping = new ProductMapping();
            $result = $mapping->updateQuantityInPack($mappingId, $quantity);

            jsonResponse([
                'success' => $result,
                'message' => $result ? 'Сохранено' : 'Ошибка сохранения'
            ]);
            break;

        // Обновление параметров упаковки (pieces_per_sheet и quantity_in_pack)
        case '/api/ozon/update-pack-settings':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $mappingId = (int)post('mapping_id');
            $piecesPerSheet = (int)post('pieces_per_sheet', 1);
            $quantityInPack = (int)post('quantity_in_pack', 1);

            $mapping = new ProductMapping();
            $result = $mapping->updatePackSettings($mappingId, $quantityInPack, $piecesPerSheet);

            jsonResponse([
                'success' => $result,
                'message' => $result ? 'Сохранено' : 'Ошибка сохранения'
            ]);
            break;

        // Обновление сопоставления и наценок
        case '/api/ozon/update-mapping':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $mappingId = (int)post('mapping_id');
            $productId = (int)post('product_id');
            $quantity = (int)post('quantity_in_pack', 1);
            $markupMin = (float)post('markup_min_price', 0);
            $markupYour = (float)post('markup_your_price', 0);

            $mapping = new ProductMapping();
            $mapping->updateQuantityInPack($mappingId, $quantity);

            $calculator = new Calculator();
            $calculator->updateProductMarkups($productId, $markupMin, $markupYour);

            jsonResponse(['success' => true, 'message' => 'Сохранено']);
            break;

        // Автозаполнение pieces_per_sheet и quantity_in_pack из названий артикулов
        case '/api/ozon/auto-fill-pieces':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('product_id', 0);
            $baseWidth = (int)post('base_width', 1520);
            $baseHeight = (int)post('base_height', 1520);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Не указан product_id']);
            }

            try {
                $cache = new OzonProductCache();
                $updated = $cache->autoFillPiecesPerSheet($productId, $baseWidth, $baseHeight);

                jsonResponse([
                    'success' => true,
                    'updated' => $updated,
                    'message' => "Обновлено {$updated} артикулов"
                ]);
            } catch (Exception $e) {
                jsonResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
            break;

        // Массовое обновление наценок
        case '/api/ozon/bulk-markups':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productIds = post('product_ids', []);
            $markupMin = (float)post('markup_min_price', 0);
            $markupYour = (float)post('markup_your_price', 0);

            if (empty($productIds)) {
                jsonResponse(['success' => false, 'message' => 'Не выбраны товары']);
            }

            $calculator = new Calculator();
            $updated = $calculator->bulkUpdateMarkups($productIds, $markupMin, $markupYour);

            jsonResponse([
                'success' => true,
                'message' => "Наценки обновлены для {$updated} товаров"
            ]);
            break;

        // Информация о кэше Ozon
        case '/api/ozon/cache-info':
            $auth->requireLogin();

            $cache = new OzonProductCache();
            $count = $cache->getCount();
            $lastSync = $cache->getLastSyncTime();

            jsonResponse([
                'success' => true,
                'count' => $count,
                'last_sync' => $lastSync
            ]);
            break;

        // Получение товаров с сопоставлениями (для калькулятора)
        case '/api/ozon/products-with-mappings':
            $auth->requireLogin();

            $calculator = new Calculator();
            $products = $calculator->getProductsWithMappings('ozon');

            jsonResponse(['success' => true, 'products' => $products]);
            break;

        // Получение артикулов товара
        case '/api/ozon/product-articles':
            $auth->requireLogin();

            $productId = (int)get('product_id', 0);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите product_id']);
            }

            $mapping = new ProductMapping();
            $articles = $mapping->getByProduct($productId, 'ozon');

            jsonResponse(['success' => true, 'articles' => $articles]);
            break;

        // Сохранение наценок для товара
        case '/api/ozon/save-markups':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('product_id');
            $markupMin = (float)post('markup_min_price', 0);
            $markupYour = (float)post('markup_your_price', 0);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите product_id']);
            }

            $calculator = new Calculator();
            $calculator->updateProductMarkups($productId, $markupMin, $markupYour);

            jsonResponse(['success' => true, 'message' => 'Наценки сохранены']);
            break;

        // Сохранение нового товара
        case '/api/ozon/save-product':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $name = post('name', '');
            $costPrice = (float)post('cost_price', 0);

            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Укажите название товара']);
            }

            if ($costPrice <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите себестоимость']);
            }

            $data = [
                'name' => $name,
                'sku' => post('sku', ''),
                'category' => post('category', ''),
                'material_type' => post('material_type', ''),
                'grade' => post('grade', ''),
                'thickness' => post('thickness') ? (float)post('thickness') : null,
                'cost_price' => $costPrice,
                'base_price' => (float)post('base_price', $costPrice),
                'created_by' => $auth->getUserId()
            ];

            $calculator = new Calculator();
            $productId = $calculator->createProduct($data);

            jsonResponse([
                'success' => true,
                'message' => 'Товар добавлен',
                'product_id' => $productId
            ]);
            break;

        // ==================== Products API ====================

        // Получение списка всех товаров
        case '/api/products':
            $auth->requireLogin();
            $userId = $auth->getUserId();

            if (!$userId) {
                jsonResponse(['success' => false, 'error' => 'Пользователь не авторизован'], 401);
                break;
            }

            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    SELECT id, name, sku, barcode, category, description,
                           base_price, cost_price, markup_percent, final_price,
                           wb_article, ozon_article, wb_price, ozon_price,
                           stock_quantity, markup_min_price, markup_your_price,
                           is_active, created_by, created_at, updated_at
                    FROM products
                    WHERE created_by = ? AND is_active = 1
                    ORDER BY name ASC
                ");
                $stmt->execute([$userId]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                jsonResponse(['success' => true, 'products' => $products, 'count' => count($products)]);
            } catch (Exception $e) {
                error_log("[/api/products] Error: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Сохранение товара (обновление)
        case '/api/products/save':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('id', 0);
            $costPrice = (float)post('cost_price', 0);
            $markupMin = (float)post('markup_min_price', 20);
            $markupYour = (float)post('markup_your_price', 5);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите ID товара']);
            }

            $db = Database::getInstance();
            $db->execute(
                "UPDATE products SET cost_price = ?, markup_min_price = ?, markup_your_price = ?, updated_at = NOW() WHERE id = ?",
                [$costPrice, $markupMin, $markupYour, $productId]
            );

            jsonResponse(['success' => true, 'message' => 'Товар сохранён']);
            break;

        // Создание нового товара
        case '/api/products/create':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $name = trim(post('name', ''));
            $sku = trim(post('sku', ''));
            $category = trim(post('category', ''));
            $costPrice = (float)post('cost_price', 0);
            $markupMin = (float)post('markup_min_price', 20);
            $markupYour = (float)post('markup_your_price', 5);

            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Укажите название товара']);
            }

            if ($costPrice <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите закупочную цену']);
            }

            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO products (name, sku, category, cost_price, base_price, markup_min_price, markup_your_price, created_by, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [$name, $sku, $category, $costPrice, $costPrice, $markupMin, $markupYour, $auth->getUserId()]
            );

            $productId = $db->lastInsertId();

            jsonResponse([
                'success' => true,
                'message' => 'Товар создан',
                'product_id' => $productId
            ]);
            break;

        // Удаление товара
        case '/api/products/delete':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('id', 0);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите ID товара']);
            }

            $db = Database::getInstance();

            // Мягкое удаление - помечаем как неактивный
            $db->execute(
                "UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?",
                [$productId]
            );

            // Также деактивируем сопоставления
            $db->execute(
                "UPDATE product_mappings SET is_active = 0 WHERE product_id = ?",
                [$productId]
            );

            jsonResponse(['success' => true, 'message' => 'Товар удалён']);
            break;

        // Получение товара по ID
        case '/api/products/get':
            $auth->requireLogin();

            $productId = (int)get('id', 0);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите ID товара']);
            }

            $db = Database::getInstance();
            $product = $db->fetchOne(
                "SELECT id, name, sku, category, cost_price, markup_min_price, markup_your_price
                 FROM products WHERE id = ? AND is_active = 1",
                [$productId]
            );

            if (!$product) {
                jsonResponse(['success' => false, 'message' => 'Товар не найден']);
            }

            jsonResponse(['success' => true, 'product' => $product]);
            break;

        // Обновление товара (полное редактирование)
        case '/api/products/update':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('id', 0);
            $name = trim(post('name', ''));
            $sku = trim(post('sku', ''));
            $category = trim(post('category', ''));
            $costPrice = (float)post('cost_price', 0);
            $markupMin = (float)post('markup_min_price', 20);
            $markupYour = (float)post('markup_your_price', 5);

            if ($productId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Укажите ID товара']);
            }

            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => 'Укажите название товара']);
            }

            $db = Database::getInstance();
            $db->execute(
                "UPDATE products SET name = ?, sku = ?, category = ?, cost_price = ?,
                 markup_min_price = ?, markup_your_price = ?, updated_at = NOW()
                 WHERE id = ?",
                [$name, $sku, $category, $costPrice, $markupMin, $markupYour, $productId]
            );

            jsonResponse(['success' => true, 'message' => 'Товар обновлён']);
            break;

        // Загрузка цен на Ozon (устаревший endpoint, оставлен для совместимости)
        case '/api/ozon/upload-prices':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            // Убедимся что все таблицы созданы (включая price_upload_history)
            Database::getInstance()->ensureTables();

            $products = post('products', []);

            if (empty($products)) {
                jsonResponse(['success' => false, 'message' => 'Нет товаров для загрузки']);
            }

            $ozonApi = new OzonAPI($auth->getUserId());

            if (!$ozonApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Ozon не настроен']);
            }

            // Проверяем подключение к Ozon
            $testResult = $ozonApi->testConnection();
            if (!$testResult['success']) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Нет подключения к Ozon: ' . ($testResult['message'] ?? 'Неизвестная ошибка')
                ]);
            }

            $result = $ozonApi->updatePricesWithMinPrice($products);
            jsonResponse($result);
            break;

        // Загрузка цен И остатков на Ozon
        case '/api/ozon/upload-prices-and-stocks':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            // Убедимся что все таблицы созданы
            Database::getInstance()->ensureTables();

            $products = post('products', []);

            if (empty($products)) {
                jsonResponse(['success' => false, 'message' => 'Нет товаров для загрузки']);
            }

            $ozonApi = new OzonAPI($auth->getUserId());

            if (!$ozonApi->isConfigured()) {
                jsonResponse(['success' => false, 'message' => 'API Ozon не настроен']);
            }

            // Проверяем подключение к Ozon
            $testResult = $ozonApi->testConnection();
            if (!$testResult['success']) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Нет подключения к Ozon: ' . ($testResult['message'] ?? 'Неизвестная ошибка')
                ]);
            }

            $pricesUpdated = 0;
            $stocksUpdated = 0;
            $errors = [];

            // 1. Обновляем цены
            $priceResult = $ozonApi->updatePricesWithMinPrice($products);
            if ($priceResult['success']) {
                $pricesUpdated = $priceResult['success_count'] ?? count($products);
            } else {
                $errors[] = 'Ошибка обновления цен: ' . ($priceResult['message'] ?? 'Неизвестная ошибка');
            }

            // 2. Обновляем остатки (только если есть товары с остатками)
            $warehouseId = null;
            $stocksToUpdate = array_filter($products, fn($p) => isset($p['stock']) && (int)$p['stock'] >= 0);
            if (!empty($stocksToUpdate)) {
                $stockResult = $ozonApi->updateStocks($stocksToUpdate);
                $warehouseId = $stockResult['warehouse_id'] ?? null;
                if ($stockResult['success']) {
                    $stocksUpdated = $stockResult['updated'] ?? count($stocksToUpdate);
                } else {
                    $errors[] = 'Ошибка обновления остатков: ' . ($stockResult['error'] ?? 'Неизвестная ошибка');
                }
                // Добавляем ошибки по отдельным товарам
                if (!empty($stockResult['errors'])) {
                    $errors = array_merge($errors, $stockResult['errors']);
                }
            }

            // Логируем в историю
            $db = Database::getInstance();
            foreach ($products as $product) {
                try {
                    $db->execute(
                        "INSERT INTO price_upload_history
                         (user_id, marketplace, marketplace_product_id, new_price, new_min_price, status, created_at)
                         VALUES (?, 'ozon', ?, ?, ?, 'success', NOW())",
                        [
                            $auth->getUserId(),
                            $product['offer_id'] ?? $product['product_id'] ?? '',
                            $product['price'] ?? 0,
                            $product['min_price'] ?? 0
                        ]
                    );
                } catch (Exception $e) {
                    // Игнорируем ошибки логирования
                }
            }

            jsonResponse([
                'success' => empty($errors),
                'prices_updated' => $pricesUpdated,
                'stocks_updated' => $stocksUpdated,
                'warehouse_id' => $warehouseId,
                'errors' => $errors,
                'error_count' => count($errors)
            ]);
            break;

        // Загрузка ТОЛЬКО остатков на Ozon (без цен)
        case '/api/ozon/upload-stocks-only':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'message' => 'Метод не разрешён'], 405);
            }

            $stocks = post('stocks', []);

            if (empty($stocks)) {
                jsonResponse(['success' => false, 'error' => 'Нет остатков для загрузки'], 400);
            }

            try {
                $ozonApi = new OzonAPI($auth->getUserId());

                if (!$ozonApi->isConfigured()) {
                    jsonResponse(['success' => false, 'error' => 'API Ozon не настроен']);
                }

                $result = $ozonApi->updateStocks($stocks);

                jsonResponse([
                    'success' => $result['success'] ?? false,
                    'updated' => $result['updated'] ?? 0,
                    'total' => $result['total'] ?? count($stocks),
                    'warehouse_id' => $result['warehouse_id'] ?? null,
                    'errors' => $result['errors'] ?? [],
                    'message' => ($result['updated'] ?? 0) > 0
                        ? "Обновлено {$result['updated']} остатков"
                        : ($result['error'] ?? 'Остатки не обновлены')
                ]);

            } catch (Exception $e) {
                jsonResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
            break;

        // ==================== Logs API (только для админов) ====================

        // Получение списка логов
        case '/api/logs':
            $auth->requireLogin();
            if ($auth->getUserRole() !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
            }

            $level = get('level', '');
            $limit = (int)get('limit', 50);
            $search = get('search', '');

            $logs = ErrorLogger::getRecent($limit, $level ?: null, $search ?: null);
            jsonResponse(['success' => true, 'data' => $logs]);
            break;

        // Получение статистики логов
        case '/api/logs/stats':
            $auth->requireLogin();
            if ($auth->getUserRole() !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
            }

            $stats = ErrorLogger::getStatistics();
            jsonResponse(['success' => true, 'data' => $stats]);
            break;

        // Очистка старых логов (старше 7 дней)
        case '/api/logs/cleanup':
            $auth->requireLogin();
            if ($auth->getUserRole() !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
            }

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $deleted = ErrorLogger::cleanup(7);
            jsonResponse(['success' => true, 'deleted' => $deleted]);
            break;

        // Очистка всех логов
        case '/api/logs/clear':
            $auth->requireLogin();
            if ($auth->getUserRole() !== 'admin') {
                jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
            }

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $deleted = ErrorLogger::clearAll();
            jsonResponse(['success' => true, 'deleted' => $deleted]);
            break;

        // ==================== AI Assistant API ====================

        // Страница AI Assistant
        case '/ai':
        case '/ai/reviews':
            $auth->requireLogin();
            view('ai/reviews', ['auth' => $auth]);
            break;

        case '/ai/questions':
            $auth->requireLogin();
            view('ai/questions', ['auth' => $auth]);
            break;

        case '/ai/prompts':
            $auth->requireLogin();
            view('ai/prompts', ['auth' => $auth]);
            break;

        case '/ai/settings':
            $auth->requireLogin();
            view('ai/settings', ['auth' => $auth]);
            break;

        // Получение отзывов
        case '/api/ai/reviews':
            $auth->requireLogin();

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $filters = [
                'status' => get('status', ''),
                'rating' => get('rating', ''),
                'product_id' => get('product_id', ''),
                'search' => get('search', ''),
                'limit' => (int)get('limit', 50),
                'offset' => (int)get('offset', 0)
            ];

            $reviews = $ai->getReviews(array_filter($filters));
            jsonResponse(['success' => true, 'reviews' => $reviews]);
            break;

        // Получение одного отзыва
        case '/api/ai/review':
            $auth->requireLogin();

            $id = (int)get('id', 0);
            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID отзыва']);
            }

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);
            $review = $ai->getReview($id);

            if (!$review) {
                jsonResponse(['success' => false, 'error' => 'Отзыв не найден'], 404);
            }

            jsonResponse(['success' => true, 'review' => $review]);
            break;

        // Генерация ответа на отзыв
        case '/api/ai/generate-review-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $reviewId = (int)post('review_id', 0);
            $promptId = post('prompt_id') ? (int)post('prompt_id') : null;

            if ($reviewId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID отзыва']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->generateReviewResponse($reviewId, $promptId);
            jsonResponse($result);
            break;

        // Одобрение ответа на отзыв
        case '/api/ai/approve-review':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $reviewId = (int)post('review_id', 0);
            $editedResponse = post('edited_response');

            if ($reviewId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID отзыва']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->approveReviewResponse($reviewId, $editedResponse);
            jsonResponse(['success' => $result]);
            break;

        // Пропуск отзыва
        case '/api/ai/skip-review':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $reviewId = (int)post('review_id', 0);

            if ($reviewId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID отзыва']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->skipReview($reviewId);
            jsonResponse(['success' => $result]);
            break;

        // Получение вопросов
        case '/api/ai/questions':
            $auth->requireLogin();

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $filters = [
                'status' => get('status', ''),
                'product_id' => get('product_id', ''),
                'search' => get('search', ''),
                'limit' => (int)get('limit', 50),
                'offset' => (int)get('offset', 0)
            ];

            $questions = $ai->getQuestions(array_filter($filters));
            jsonResponse(['success' => true, 'questions' => $questions]);
            break;

        // Получение одного вопроса
        case '/api/ai/question':
            $auth->requireLogin();

            $id = (int)get('id', 0);
            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID вопроса']);
            }

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);
            $question = $ai->getQuestion($id);

            if (!$question) {
                jsonResponse(['success' => false, 'error' => 'Вопрос не найден'], 404);
            }

            jsonResponse(['success' => true, 'question' => $question]);
            break;

        // Генерация ответа на вопрос
        case '/api/ai/generate-question-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $questionId = (int)post('question_id', 0);
            $promptId = post('prompt_id') ? (int)post('prompt_id') : null;

            if ($questionId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID вопроса']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->generateQuestionResponse($questionId, $promptId);
            jsonResponse($result);
            break;

        // Одобрение ответа на вопрос
        case '/api/ai/approve-question':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $questionId = (int)post('question_id', 0);
            $editedResponse = post('edited_response');

            if ($questionId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID вопроса']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->approveQuestionResponse($questionId, $editedResponse);
            jsonResponse(['success' => $result]);
            break;

        // Пропуск вопроса
        case '/api/ai/skip-question':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $questionId = (int)post('question_id', 0);

            if ($questionId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID вопроса']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->skipQuestion($questionId);
            jsonResponse(['success' => $result]);
            break;

        // Получение промптов
        case '/api/ai/prompts':
            $auth->requireLogin();

            $marketplace = get('marketplace', 'ozon');
            $type = get('type', '');

            $ai = new AIAssistant($marketplace);
            $prompts = $ai->getPrompts($type ?: null);

            jsonResponse(['success' => true, 'prompts' => $prompts]);
            break;

        // Получение одного промпта
        case '/api/ai/prompt':
            $auth->requireLogin();

            $id = (int)get('id', 0);
            if ($id <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID промпта']);
            }

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);
            $prompt = $ai->getPrompt($id);

            if (!$prompt) {
                jsonResponse(['success' => false, 'error' => 'Промпт не найден'], 404);
            }

            // Получаем примеры для промпта
            $examples = $ai->getExamples($id);

            jsonResponse(['success' => true, 'prompt' => $prompt, 'examples' => $examples]);
            break;

        // Сохранение промпта
        case '/api/ai/save-prompt':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $data = [
                'id' => post('id') ? (int)post('id') : null,
                'type' => post('type', 'review'),
                'sentiment' => post('sentiment') ?: null,
                'name' => post('name', ''),
                'system_prompt' => post('system_prompt', ''),
                'user_prompt_template' => post('user_prompt_template', ''),
                'is_active' => post('is_active', 1) ? 1 : 0,
                'is_default' => post('is_default', 0) ? 1 : 0
            ];

            if (empty($data['name'])) {
                jsonResponse(['success' => false, 'error' => 'Укажите название промпта']);
            }

            $promptId = $ai->savePrompt($data);
            jsonResponse(['success' => true, 'prompt_id' => $promptId]);
            break;

        // Удаление промпта
        case '/api/ai/delete-prompt':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $promptId = (int)post('id', 0);

            if ($promptId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID промпта']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->deletePrompt($promptId);
            jsonResponse(['success' => $result]);
            break;

        // Сохранение примера
        case '/api/ai/save-example':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $data = [
                'id' => post('id') ? (int)post('id') : null,
                'prompt_id' => (int)post('prompt_id', 0),
                'input_text' => post('input_text', ''),
                'output_text' => post('output_text', ''),
                'is_active' => post('is_active', 1) ? 1 : 0
            ];

            if ($data['prompt_id'] <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID промпта']);
            }

            $exampleId = $ai->saveExample($data);
            jsonResponse(['success' => true, 'example_id' => $exampleId]);
            break;

        // Удаление примера
        case '/api/ai/delete-example':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $exampleId = (int)post('id', 0);

            if ($exampleId <= 0) {
                jsonResponse(['success' => false, 'error' => 'Укажите ID примера']);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $result = $ai->deleteExample($exampleId);
            jsonResponse(['success' => $result]);
            break;

        // Получение настроек AI
        case '/api/ai/settings':
            $auth->requireLogin();

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $settings = $ai->getSettings();
            $models = ClaudeAPI::getAvailableModels();

            jsonResponse(['success' => true, 'settings' => $settings, 'models' => $models]);
            break;

        // Сохранение настройки AI
        case '/api/ai/save-setting':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $key = post('key', '');
            $value = post('value', '');
            $marketplace = post('marketplace', 'all');

            if (empty($key)) {
                jsonResponse(['success' => false, 'error' => 'Укажите ключ настройки']);
            }

            $ai = new AIAssistant($marketplace);
            $result = $ai->saveSetting($key, $value, $marketplace);

            jsonResponse(['success' => $result]);
            break;

        // Сохранение API ключа Claude
        case '/api/ai/save-claude-key':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $apiKey = post('api_key', '');

            if (empty($apiKey)) {
                jsonResponse(['success' => false, 'error' => 'Укажите API ключ']);
            }

            // Проверяем валидность ключа
            try {
                $claude = new ClaudeAPI($apiKey);
                $valid = $claude->validateApiKey();

                if (!$valid) {
                    jsonResponse(['success' => false, 'error' => 'Неверный API ключ: ' . $claude->getLastError()]);
                }
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => 'Ошибка проверки ключа: ' . $e->getMessage()]);
            }

            // Сохраняем ключ
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO user_api_keys (user_id, service, api_key, is_active, created_at)
                 VALUES (?, 'claude', ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE api_key = ?, is_active = 1, updated_at = NOW()",
                [$auth->getUserId(), $apiKey, $apiKey]
            );

            jsonResponse(['success' => true, 'message' => 'API ключ сохранён и проверен']);
            break;

        // Проверка подключения Claude
        case '/api/ai/test-claude':
            $auth->requireLogin();

            $ai = new AIAssistant('ozon');

            if (!$ai->initClaude()) {
                jsonResponse(['success' => false, 'error' => 'Claude API не настроен']);
            }

            // Пробуем сделать тестовый запрос
            try {
                $claude = new ClaudeAPI($ai->getSettings()['api_key'] ?? '');
                $valid = $claude->validateApiKey();

                jsonResponse([
                    'success' => $valid,
                    'error' => $valid ? null : $claude->getLastError()
                ]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // Статистика AI
        case '/api/ai/statistics':
            $auth->requireLogin();

            $marketplace = get('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $stats = $ai->getStatistics();
            jsonResponse(['success' => true, 'statistics' => $stats]);
            break;

        // Создание промптов по умолчанию
        case '/api/ai/create-default-prompts':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $marketplace = post('marketplace', 'ozon');
            $ai = new AIAssistant($marketplace);

            $ai->createDefaultPrompts();
            jsonResponse(['success' => true, 'message' => 'Промпты созданы']);
            break;

        // ============================================
        // AI АССИСТЕНТ - СИНХРОНИЗАЦИЯ С OZON
        // ============================================

        // Синхронизация отзывов с Ozon
        case '/api/ai/sync-reviews':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $marketplace = post('marketplace', 'ozon');
            $userId = $auth->getUserId();

            error_log("[AI Sync Reviews] Start, user: {$userId}");

            try {
                if ($marketplace !== 'ozon') {
                    throw new Exception("Маркетплейс {$marketplace} пока не поддерживается");
                }

                // Инициализируем OzonAPI
                $ozonApi = new OzonAPI($userId);

                if (!$ozonApi->isConfigured()) {
                    throw new Exception('API Ozon не настроен. Проверьте настройки.');
                }

                // Статистика с Ozon
                $countResult = $ozonApi->getReviewsCount();
                error_log("[AI Sync Reviews] Ozon count: " . json_encode($countResult));

                // Получаем все отзывы (максимум 20 страниц = 2000 отзывов)
                $result = $ozonApi->getAllReviews(20, 'ALL');

                if (!$result['success']) {
                    throw new Exception('Ошибка Ozon API: ' . ($result['error'] ?? 'Unknown'));
                }

                error_log("[AI Sync Reviews] Got " . count($result['reviews']) . " reviews from Ozon");

                $db = Database::getInstance();
                $added = 0;
                $updated = 0;
                $skipped = 0;

                foreach ($result['reviews'] as $review) {
                    // Проверяем существование в локальной БД
                    $existing = $db->fetchOne(
                        "SELECT id, status FROM ai_reviews WHERE marketplace = 'ozon' AND marketplace_review_id = ?",
                        [$review['marketplace_review_id']]
                    );

                    if ($existing) {
                        // Не обновляем если уже обработан (approved/sent)
                        if (in_array($existing['status'], ['approved', 'sent'])) {
                            $skipped++;
                            continue;
                        }

                        // Обновляем существующий
                        $db->execute(
                            "UPDATE ai_reviews SET
                                marketplace_product_id = ?,
                                rating = ?,
                                review_text = ?,
                                review_date = ?,
                                ozon_status = ?,
                                comments_amount = ?,
                                updated_at = NOW()
                            WHERE id = ?",
                            [
                                $review['sku'],
                                $review['rating'],
                                $review['review_text'],
                                $review['review_date'],
                                $review['status'],
                                $review['comments_amount'],
                                $existing['id']
                            ]
                        );
                        $updated++;
                    } else {
                        // Пропускаем если уже есть ответ на Ozon (comments_amount > 0)
                        if ($review['comments_amount'] > 0) {
                            $skipped++;
                            continue;
                        }

                        // Добавляем новый
                        $db->execute(
                            "INSERT INTO ai_reviews
                            (user_id, marketplace, marketplace_review_id, marketplace_product_id,
                             rating, review_text, review_date, ozon_status, comments_amount,
                             status, created_at)
                            VALUES (?, 'ozon', ?, ?, ?, ?, ?, ?, ?, 'new', NOW())",
                            [
                                $userId,
                                $review['marketplace_review_id'],
                                $review['sku'],
                                $review['rating'],
                                $review['review_text'],
                                $review['review_date'],
                                $review['status'],
                                $review['comments_amount']
                            ]
                        );
                        $added++;
                    }
                }

                error_log("[AI Sync Reviews] Done: added=$added, updated=$updated, skipped=$skipped");

                jsonResponse([
                    'success' => true,
                    'message' => "Синхронизация отзывов завершена",
                    'stats' => [
                        'total_from_ozon' => count($result['reviews']),
                        'pages_loaded' => $result['pages_loaded'] ?? 1,
                        'added' => $added,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'ozon_total' => $countResult['total'] ?? 0,
                        'ozon_unprocessed' => $countResult['unprocessed'] ?? 0
                    ]
                ]);

            } catch (Exception $e) {
                error_log("[AI Sync Reviews] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Синхронизация вопросов с Ozon
        case '/api/ai/sync-questions':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $marketplace = post('marketplace', 'ozon');
            $userId = $auth->getUserId();

            error_log("[AI Sync Questions] Start, user: {$userId}");

            try {
                if ($marketplace !== 'ozon') {
                    throw new Exception("Маркетплейс {$marketplace} пока не поддерживается");
                }

                $ozonApi = new OzonAPI($userId);

                if (!$ozonApi->isConfigured()) {
                    throw new Exception('API Ozon не настроен. Проверьте настройки.');
                }

                // Статистика
                $countResult = $ozonApi->getQuestionsCount();
                error_log("[AI Sync Questions] Ozon count: " . json_encode($countResult));

                // ВАЖНО: API возвращает до 10 вопросов за раз!
                // maxPages = 100 даст максимум 1000 вопросов
                $result = $ozonApi->getAllQuestions(100, 'ALL');

                if (!$result['success']) {
                    throw new Exception('Ошибка Ozon API: ' . ($result['error'] ?? 'Unknown'));
                }

                error_log("[AI Sync Questions] Got " . count($result['questions']) . " questions from Ozon");

                $db = Database::getInstance();
                $added = 0;
                $updated = 0;
                $skipped = 0;

                foreach ($result['questions'] as $question) {
                    $existing = $db->fetchOne(
                        "SELECT id, status FROM ai_questions WHERE marketplace = 'ozon' AND marketplace_question_id = ?",
                        [$question['marketplace_question_id']]
                    );

                    if ($existing) {
                        if (in_array($existing['status'], ['approved', 'sent'])) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "UPDATE ai_questions SET
                                marketplace_product_id = ?,
                                author_name = ?,
                                question_text = ?,
                                question_date = ?,
                                ozon_status = ?,
                                answers_count = ?,
                                updated_at = NOW()
                            WHERE id = ?",
                            [
                                $question['sku'],
                                $question['author_name'],
                                $question['question_text'],
                                $question['question_date'],
                                $question['status'],
                                $question['answers_count'],
                                $existing['id']
                            ]
                        );
                        $updated++;
                    } else {
                        // Пропускаем если уже есть ответ
                        if ($question['answers_count'] > 0) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "INSERT INTO ai_questions
                            (user_id, marketplace, marketplace_question_id, marketplace_product_id,
                             author_name, question_text, question_date, ozon_status, answers_count,
                             status, created_at)
                            VALUES (?, 'ozon', ?, ?, ?, ?, ?, ?, ?, 'new', NOW())",
                            [
                                $userId,
                                $question['marketplace_question_id'],
                                $question['sku'],
                                $question['author_name'],
                                $question['question_text'],
                                $question['question_date'],
                                $question['status'],
                                $question['answers_count']
                            ]
                        );
                        $added++;
                    }
                }

                error_log("[AI Sync Questions] Done: added=$added, updated=$updated, skipped=$skipped");

                jsonResponse([
                    'success' => true,
                    'message' => "Синхронизация вопросов завершена",
                    'stats' => [
                        'total_from_ozon' => count($result['questions']),
                        'pages_loaded' => $result['pages_loaded'] ?? 1,
                        'added' => $added,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'ozon_total' => $countResult['all'] ?? 0,
                        'ozon_new' => $countResult['new'] ?? 0
                    ]
                ]);

            } catch (Exception $e) {
                error_log("[AI Sync Questions] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Отправить ответ на отзыв в Ozon
        case '/api/ai/send-review-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $reviewId = (int)post('review_id');
            $userId = $auth->getUserId();

            try {
                $db = Database::getInstance();

                // Получаем отзыв из БД (user_id не используется, система однопользовательская)
                $review = $db->fetchOne(
                    "SELECT * FROM ai_reviews WHERE id = ?",
                    [$reviewId]
                );

                if (!$review) {
                    throw new Exception('Отзыв не найден');
                }

                if ($review['status'] !== 'approved') {
                    throw new Exception('Ответ должен быть сначала одобрен');
                }

                $responseText = $review['edited_response'] ?: $review['generated_response'];
                if (empty($responseText)) {
                    throw new Exception('Нет текста ответа');
                }

                $ozonApi = new OzonAPI($userId);

                if (!$ozonApi->isConfigured()) {
                    throw new Exception('API Ozon не настроен');
                }

                $result = $ozonApi->replyToReview(
                    $review['marketplace_review_id'],
                    $responseText,
                    true // mark_review_as_processed
                );

                if (!$result['success']) {
                    throw new Exception('Ошибка Ozon API: ' . $result['error']);
                }

                // Обновляем статус в БД
                $db->execute(
                    "UPDATE ai_reviews SET
                        status = 'sent',
                        sent_response = ?,
                        sent_at = NOW(),
                        ozon_comment_id = ?
                    WHERE id = ?",
                    [$responseText, $result['comment_id'], $reviewId]
                );

                jsonResponse([
                    'success' => true,
                    'message' => 'Ответ отправлен на Ozon',
                    'comment_id' => $result['comment_id']
                ]);

            } catch (Exception $e) {
                error_log("[AI Send Review] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Отправить ответ на вопрос в Ozon
        case '/api/ai/send-question-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $questionId = (int)post('question_id');
            $userId = $auth->getUserId();

            try {
                $db = Database::getInstance();

                // user_id не используется, система однопользовательская
                $question = $db->fetchOne(
                    "SELECT * FROM ai_questions WHERE id = ?",
                    [$questionId]
                );

                if (!$question) {
                    throw new Exception('Вопрос не найден');
                }

                if ($question['status'] !== 'approved') {
                    throw new Exception('Ответ должен быть сначала одобрен');
                }

                $responseText = $question['edited_response'] ?: $question['generated_response'];
                if (empty($responseText)) {
                    throw new Exception('Нет текста ответа');
                }

                // SKU обязателен для ответа на вопрос!
                $sku = (int)$question['marketplace_product_id'];
                if ($sku <= 0) {
                    throw new Exception('Не указан SKU товара');
                }

                $ozonApi = new OzonAPI($userId);

                if (!$ozonApi->isConfigured()) {
                    throw new Exception('API Ozon не настроен');
                }

                $result = $ozonApi->answerQuestion(
                    $question['marketplace_question_id'],
                    $sku,
                    $responseText
                );

                if (!$result['success']) {
                    throw new Exception('Ошибка Ozon API: ' . $result['error']);
                }

                $db->execute(
                    "UPDATE ai_questions SET
                        status = 'sent',
                        sent_response = ?,
                        sent_at = NOW(),
                        ozon_answer_id = ?
                    WHERE id = ?",
                    [$responseText, $result['answer_id'], $questionId]
                );

                jsonResponse([
                    'success' => true,
                    'message' => 'Ответ отправлен на Ozon',
                    'answer_id' => $result['answer_id']
                ]);

            } catch (Exception $e) {
                error_log("[AI Send Question] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // ============================================
        // ОТПРАВКА ОТВЕТОВ НА WILDBERRIES
        // ============================================

        // Отправить ответ на отзыв в Wildberries
        case '/api/ai/send-wb-review-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $reviewId = (int)post('review_id');
            $userId = $auth->getUserId();

            try {
                $db = Database::getInstance();

                // Получаем отзыв из БД
                $review = $db->fetchOne(
                    "SELECT * FROM ai_reviews WHERE id = ? AND marketplace = 'wildberries'",
                    [$reviewId]
                );

                if (!$review) {
                    throw new Exception('Отзыв не найден');
                }

                if ($review['status'] !== 'approved') {
                    throw new Exception('Ответ должен быть сначала одобрен');
                }

                $responseText = $review['edited_response'] ?: $review['generated_response'];
                if (empty($responseText)) {
                    throw new Exception('Нет текста ответа');
                }

                $wbApi = new WildberriesAPI($userId);

                if (!$wbApi->isConfigured()) {
                    throw new Exception('API Wildberries не настроен');
                }

                $result = $wbApi->replyToFeedback(
                    $review['marketplace_review_id'],
                    $responseText
                );

                if (!$result['success']) {
                    throw new Exception('Ошибка WB API: ' . ($result['error'] ?? 'Unknown'));
                }

                // Обновляем статус в БД
                $db->execute(
                    "UPDATE ai_reviews SET
                        status = 'sent',
                        sent_response = ?,
                        sent_at = NOW()
                    WHERE id = ?",
                    [$responseText, $reviewId]
                );

                error_log("[AI Send WB Review] SUCCESS: Review #{$reviewId} sent to WB");

                jsonResponse([
                    'success' => true,
                    'message' => 'Ответ отправлен на Wildberries'
                ]);

            } catch (Exception $e) {
                error_log("[AI Send WB Review] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Отправить ответ на вопрос в Wildberries
        case '/api/ai/send-wb-question-response':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $questionId = (int)post('question_id');
            $userId = $auth->getUserId();

            try {
                $db = Database::getInstance();

                $question = $db->fetchOne(
                    "SELECT * FROM ai_questions WHERE id = ? AND marketplace = 'wildberries'",
                    [$questionId]
                );

                if (!$question) {
                    throw new Exception('Вопрос не найден');
                }

                if ($question['status'] !== 'approved') {
                    throw new Exception('Ответ должен быть сначала одобрен');
                }

                $responseText = $question['edited_response'] ?: $question['generated_response'];
                if (empty($responseText)) {
                    throw new Exception('Нет текста ответа');
                }

                $wbApi = new WildberriesAPI($userId);

                if (!$wbApi->isConfigured()) {
                    throw new Exception('API Wildberries не настроен');
                }

                $result = $wbApi->replyToQuestion(
                    $question['marketplace_question_id'],
                    $responseText
                );

                if (!$result['success']) {
                    throw new Exception('Ошибка WB API: ' . ($result['error'] ?? 'Unknown'));
                }

                $db->execute(
                    "UPDATE ai_questions SET
                        status = 'sent',
                        sent_response = ?,
                        sent_at = NOW()
                    WHERE id = ?",
                    [$responseText, $questionId]
                );

                error_log("[AI Send WB Question] SUCCESS: Question #{$questionId} sent to WB");

                jsonResponse([
                    'success' => true,
                    'message' => 'Ответ отправлен на Wildberries'
                ]);

            } catch (Exception $e) {
                error_log("[AI Send WB Question] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // ============================================
        // WILDBERRIES API
        // ============================================

        // Проверка подключения WB
        case '/api/wb/test-connection':
            $auth->requireLogin();
            try {
                $wbApi = new WildberriesAPI($auth->getUserId());

                if (!$wbApi->isConfigured()) {
                    jsonResponse(['success' => false, 'error' => 'API токен Wildberries не настроен']);
                    break;
                }

                $result = $wbApi->testConnection();

                if ($result['success']) {
                    $sellerInfo = $wbApi->getSellerInfo();
                    $result['seller'] = $sellerInfo;
                }

                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить склады WB
        case '/api/wb/warehouses':
            $auth->requireLogin();
            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                jsonResponse($wbApi->getWarehouses());
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Синхронизация товаров WB
        case '/api/wb/sync-products':
            $auth->requireLogin();
            try {
                $cache = new WBProductCache($auth->getUserId());
                $result = $cache->syncAllProducts();

                // Также подтянем цены
                if ($result['success']) {
                    $pricesResult = $cache->syncPrices();
                    $result['prices_updated'] = $pricesResult['updated'] ?? 0;
                }

                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить товары WB из кэша (для страницы сопоставления)
        case '/api/wb/products':
            $auth->requireLogin();
            try {
                $cache = new WBProductCache($auth->getUserId());
                $search = get('search');
                $limit = (int)(get('limit') ?: 10000);
                $offset = (int)(get('offset') ?: 0);

                $products = $cache->getCachedProducts($search, $limit, $offset);
                $stats = $cache->getStats();

                jsonResponse([
                    'success' => true,
                    'products' => $products,
                    'count' => count($products),
                    'stats' => $stats
                ]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получение НАШИХ товаров с сопоставлениями (для калькулятора WB)
        case '/api/wb/products-with-mappings':
            $auth->requireLogin();
            try {
                $calculator = new Calculator();
                $products = $calculator->getProductsWithMappings('wildberries');

                jsonResponse(['success' => true, 'products' => $products]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получение артикулов WB для товара (для калькулятора)
        case '/api/wb/product-articles':
            $auth->requireLogin();
            try {
                $productId = (int)get('product_id', 0);

                if ($productId <= 0) {
                    jsonResponse(['success' => false, 'message' => 'Укажите product_id']);
                }

                $mapping = new ProductMapping();
                $mappings = $mapping->getByProduct($productId, 'wildberries');

                // Дополняем данными из кэша WB
                $cache = new WBProductCache($auth->getUserId());
                $articles = [];

                foreach ($mappings as $m) {
                    $nmId = (int)$m['marketplace_product_id'];
                    $wbProduct = $cache->getByNmId($nmId);

                    $articles[] = [
                        'mapping_id' => $m['id'],
                        'nm_id' => $nmId,
                        'vendor_code' => $wbProduct['vendor_code'] ?? $m['marketplace_offer_id'] ?? '',
                        'wb_name' => $wbProduct['title'] ?? $m['marketplace_name'] ?? '',
                        'wb_price' => $wbProduct['price'] ?? 0,
                        'wb_discount' => $wbProduct['discount'] ?? 0,
                        'pieces_per_sheet' => $m['pieces_per_sheet'] ?? 1,
                        'quantity_in_pack' => $m['quantity_in_pack'] ?? 1,
                        'cost_price' => $m['cost_price'] ?? 0,
                        'stock' => 0
                    ];
                }

                jsonResponse(['success' => true, 'mappings' => $articles]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Поиск товаров WB для сопоставления
        case '/api/wb/search':
            $auth->requireLogin();
            try {
                $cache = new WBProductCache($auth->getUserId());
                $query = get('q') ?: get('query') ?: '';

                if (strlen($query) < 2) {
                    jsonResponse(['success' => true, 'products' => []]);
                    break;
                }

                $products = $cache->searchForMapping($query);
                jsonResponse([
                    'success' => true,
                    'products' => $products
                ]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Сопоставление товаров WB
        case '/api/wb/mapping':
            $auth->requireLogin();
            $userId = $auth->getUserId();

            if (isMethod('POST')) {
                $input = json_decode(file_get_contents('php://input'), true);
                $action = $input['action'] ?? 'create';

                try {
                    $db = Database::getInstance();

                    // Обновление параметров упаковки
                    if ($action === 'update_pack') {
                        $mappingId = (int)($input['mapping_id'] ?? 0);
                        $piecesPerSheet = (int)($input['pieces_per_sheet'] ?? 1);
                        $quantityInPack = (int)($input['quantity_in_pack'] ?? 1);

                        if ($mappingId <= 0) {
                            jsonResponse(['success' => false, 'error' => 'Не указан mapping_id']);
                        }

                        $result = $db->execute(
                            "UPDATE product_mappings SET pieces_per_sheet = ?, quantity_in_pack = ?, updated_at = NOW() WHERE id = ?",
                            [$piecesPerSheet, $quantityInPack, $mappingId]
                        );

                        jsonResponse(['success' => $result]);
                    }

                    // Сохранение наценок для товара
                    if ($action === 'save_markups') {
                        $productId = (int)($input['product_id'] ?? 0);
                        $markupMinPrice = (float)($input['markup_min_price'] ?? 0);
                        $wbDiscount = (float)($input['wb_discount'] ?? 0);

                        if ($productId <= 0) {
                            jsonResponse(['success' => false, 'error' => 'Не указан product_id']);
                        }

                        $result = $db->execute(
                            "UPDATE products SET markup_min_price = ?, wb_discount = ?, updated_at = NOW() WHERE id = ?",
                            [$markupMinPrice, $wbDiscount, $productId]
                        );

                        jsonResponse(['success' => $result]);
                    }

                    // Создание сопоставления (по умолчанию)
                    $cache = new WBProductCache($userId);
                    $result = $cache->createMapping(
                        (int)$input['product_id'],
                        (int)$input['nm_id'],
                        $input['chrt_id'] ?? null,
                        (int)($input['pieces_per_sheet'] ?? 1),
                        (int)($input['pieces_in_pack'] ?? 1),
                        (float)($input['cost_price'] ?? 0)
                    );
                    jsonResponse(['success' => $result]);
                } catch (Exception $e) {
                    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
                }
            } elseif (isMethod('DELETE')) {
                // Удалить сопоставление
                $input = json_decode(file_get_contents('php://input'), true);
                $mappingId = (int)($input['mapping_id'] ?? get('mapping_id') ?? 0);

                try {
                    $db = Database::getInstance();

                    if ($mappingId > 0) {
                        // Удаление по mapping_id
                        $result = $db->execute(
                            "DELETE FROM product_mappings WHERE id = ? AND marketplace = 'wildberries'",
                            [$mappingId]
                        );
                        jsonResponse(['success' => $result]);
                    } else {
                        // Старый способ - по product_id и nm_id
                        $productId = (int)get('product_id');
                        $nmId = (int)get('nm_id');
                        $cache = new WBProductCache($userId);
                        $result = $cache->deleteMapping($productId, $nmId);
                        jsonResponse(['success' => $result]);
                    }
                } catch (Exception $e) {
                    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
                }
            } else {
                // Получить сопоставления для товара
                $productId = (int)get('product_id');
                try {
                    $cache = new WBProductCache($userId);

                    if ($productId) {
                        $mappings = $cache->getMappedProducts($productId);
                    } else {
                        $mappings = $cache->getAllMappings();
                    }

                    jsonResponse(['success' => true, 'mappings' => $mappings]);
                } catch (Exception $e) {
                    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
                }
            }
            break;

        // Парсинг артикула WB
        case '/api/wb/parse-article':
            $auth->requireLogin();
            try {
                // Поддерживаем оба параметра: article и vendor_code
                $vendorCode = get('article') ?: get('vendor_code') ?: post('vendor_code') ?: '';
                if (empty($vendorCode)) {
                    jsonResponse(['success' => false, 'error' => 'Не указан артикул']);
                }
                $cache = new WBProductCache($auth->getUserId());
                $parsed = $cache->parseArticle($vendorCode);
                jsonResponse(['success' => true, 'data' => $parsed]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Загрузить цены на WB
        case '/api/wb/upload-prices':
            $auth->requireLogin();
            $input = json_decode(file_get_contents('php://input'), true);
            $prices = $input['prices'] ?? [];

            if (empty($prices)) {
                jsonResponse(['success' => false, 'error' => 'Пустой массив цен'], 400);
                break;
            }

            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $result = $wbApi->uploadPrices($prices);
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Загрузить остатки на WB
        case '/api/wb/upload-stocks':
            $auth->requireLogin();
            $input = json_decode(file_get_contents('php://input'), true);
            $warehouseId = (int)($input['warehouse_id'] ?? 0);
            $stocks = $input['stocks'] ?? [];

            if (!$warehouseId || empty($stocks)) {
                jsonResponse(['success' => false, 'error' => 'Укажите склад и остатки'], 400);
                break;
            }

            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $result = $wbApi->updateStocksV3($warehouseId, $stocks);
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить отзывы WB
        case '/api/wb/feedbacks':
            $auth->requireLogin();
            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $take = (int)(get('take') ?: 100);
                $skip = (int)(get('skip') ?: 0);
                $isAnswered = get('is_answered');

                if ($isAnswered !== null) {
                    $isAnswered = $isAnswered === 'true';
                }

                $result = $wbApi->getFeedbacks($take, $skip, $isAnswered);
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Ответить на отзыв WB
        case '/api/wb/feedbacks/reply':
            $auth->requireLogin();
            $input = json_decode(file_get_contents('php://input'), true);

            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $result = $wbApi->replyToFeedback(
                    $input['feedback_id'],
                    $input['text']
                );
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить вопросы WB
        case '/api/wb/questions':
            $auth->requireLogin();
            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $take = (int)(get('take') ?: 100);
                $skip = (int)(get('skip') ?: 0);
                $isAnswered = get('is_answered');

                if ($isAnswered !== null) {
                    $isAnswered = $isAnswered === 'true';
                }

                $result = $wbApi->getQuestions($take, $skip, $isAnswered);
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Ответить на вопрос WB
        case '/api/wb/questions/reply':
            $auth->requireLogin();
            $input = json_decode(file_get_contents('php://input'), true);

            try {
                $wbApi = new WildberriesAPI($auth->getUserId());
                $result = $wbApi->replyToQuestion(
                    $input['question_id'],
                    $input['text']
                );
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Синхронизация отзывов WB в AI
        case '/api/ai/sync-wb-reviews':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $userId = $auth->getUserId();

            try {
                $wbApi = new WildberriesAPI($userId);

                if (!$wbApi->isConfigured()) {
                    throw new Exception('API Wildberries не настроен');
                }

                $result = $wbApi->getAllUnansweredFeedbacks();

                if (!$result['success']) {
                    throw new Exception('Ошибка WB API: ' . ($result['error'] ?? 'Unknown'));
                }

                $db = Database::getInstance();
                $added = 0;
                $updated = 0;
                $skipped = 0;

                foreach ($result['feedbacks'] as $feedback) {
                    // Конвертируем дату из ISO 8601 в MySQL формат
                    $reviewDate = convertIsoDateToMysql($feedback['createdDate'] ?? null);

                    $existing = $db->fetchOne(
                        "SELECT id, status FROM ai_reviews WHERE marketplace = 'wildberries' AND marketplace_review_id = ?",
                        [$feedback['id']]
                    );

                    if ($existing) {
                        if (in_array($existing['status'], ['approved', 'sent'])) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "UPDATE ai_reviews SET
                                marketplace_product_id = ?,
                                product_name = ?,
                                product_article = ?,
                                rating = ?,
                                review_text = ?,
                                review_date = ?,
                                updated_at = NOW()
                            WHERE id = ?",
                            [
                                $feedback['nmId'],
                                $feedback['productName'] ?? null,
                                $feedback['supplierArticle'] ?? null,
                                $feedback['rating'],
                                $feedback['text'],
                                $reviewDate,
                                $existing['id']
                            ]
                        );
                        $updated++;
                    } else {
                        if ($feedback['isAnswered']) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "INSERT INTO ai_reviews
                            (user_id, marketplace, marketplace_review_id, marketplace_product_id,
                             product_name, product_article, rating, review_text, review_date, status, created_at)
                            VALUES (?, 'wildberries', ?, ?, ?, ?, ?, ?, ?, 'new', NOW())",
                            [
                                $userId,
                                $feedback['id'],
                                $feedback['nmId'],
                                $feedback['productName'] ?? null,
                                $feedback['supplierArticle'] ?? null,
                                $feedback['rating'],
                                $feedback['text'],
                                $reviewDate
                            ]
                        );
                        $added++;
                    }
                }

                jsonResponse([
                    'success' => true,
                    'message' => "Синхронизация отзывов WB завершена",
                    'stats' => [
                        'total_from_wb' => count($result['feedbacks']),
                        'added' => $added,
                        'updated' => $updated,
                        'skipped' => $skipped
                    ]
                ]);

            } catch (Exception $e) {
                error_log("[AI Sync WB Reviews] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Синхронизация вопросов WB в AI
        case '/api/ai/sync-wb-questions':
            $auth->requireLogin();

            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $userId = $auth->getUserId();

            try {
                $wbApi = new WildberriesAPI($userId);

                if (!$wbApi->isConfigured()) {
                    throw new Exception('API Wildberries не настроен');
                }

                $result = $wbApi->getAllUnansweredQuestions();

                if (!$result['success']) {
                    throw new Exception('Ошибка WB API: ' . ($result['error'] ?? 'Unknown'));
                }

                $db = Database::getInstance();
                $added = 0;
                $updated = 0;
                $skipped = 0;

                foreach ($result['questions'] as $question) {
                    // Конвертируем дату из ISO 8601 в MySQL формат
                    $questionDate = convertIsoDateToMysql($question['createdDate'] ?? null);

                    $existing = $db->fetchOne(
                        "SELECT id, status FROM ai_questions WHERE marketplace = 'wildberries' AND marketplace_question_id = ?",
                        [$question['id']]
                    );

                    if ($existing) {
                        if (in_array($existing['status'], ['approved', 'sent'])) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "UPDATE ai_questions SET
                                marketplace_product_id = ?,
                                product_name = ?,
                                product_article = ?,
                                author_name = ?,
                                question_text = ?,
                                question_date = ?,
                                updated_at = NOW()
                            WHERE id = ?",
                            [
                                $question['nmId'],
                                $question['productName'] ?? null,
                                $question['supplierArticle'] ?? null,
                                $question['userName'],
                                $question['text'],
                                $questionDate,
                                $existing['id']
                            ]
                        );
                        $updated++;
                    } else {
                        if ($question['isAnswered']) {
                            $skipped++;
                            continue;
                        }

                        $db->execute(
                            "INSERT INTO ai_questions
                            (user_id, marketplace, marketplace_question_id, marketplace_product_id,
                             product_name, product_article, author_name, question_text, question_date, status, created_at)
                            VALUES (?, 'wildberries', ?, ?, ?, ?, ?, ?, ?, 'new', NOW())",
                            [
                                $userId,
                                $question['id'],
                                $question['nmId'],
                                $question['productName'] ?? null,
                                $question['supplierArticle'] ?? null,
                                $question['userName'],
                                $question['text'],
                                $questionDate
                            ]
                        );
                        $added++;
                    }
                }

                jsonResponse([
                    'success' => true,
                    'message' => "Синхронизация вопросов WB завершена",
                    'stats' => [
                        'total_from_wb' => count($result['questions']),
                        'added' => $added,
                        'updated' => $updated,
                        'skipped' => $skipped
                    ]
                ]);

            } catch (Exception $e) {
                error_log("[AI Sync WB Questions] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // ==================== БАЗА ЗНАНИЙ О ТОВАРАХ ====================

        // Синхронизировать карточки товаров с WB Content API
        case '/api/ai/sync-product-knowledge':
            $auth->requireLogin();
            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $userId = $auth->getUserId();

            try {
                require_once APP_PATH . '/classes/ProductKnowledge.php';

                $wbApi = new WildberriesAPI($userId);
                if (!$wbApi->isConfigured()) {
                    throw new Exception('API токен Wildberries не настроен');
                }

                $productKnowledge = new ProductKnowledge();
                $productKnowledge->setWildberriesAPI($wbApi);

                // Синхронизировать
                $result = $productKnowledge->syncAllFromWildberries($userId);

                error_log("[Sync Product Knowledge] Результат: " . json_encode($result));

                jsonResponse([
                    'success' => true,
                    'message' => "Синхронизировано товаров: {$result['synced']}, обновлено: {$result['updated']}",
                    'data' => $result
                ]);

            } catch (Exception $e) {
                error_log("[Sync Product Knowledge] ERROR: " . $e->getMessage());
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить список товаров из базы знаний
        case '/api/ai/product-knowledge':
            $auth->requireLogin();
            $userId = $auth->getUserId();

            try {
                require_once APP_PATH . '/classes/ProductKnowledge.php';
                $productKnowledge = new ProductKnowledge();

                $limit = (int)(get('limit') ?? 100);
                $offset = (int)(get('offset') ?? 0);
                $search = get('search') ?? null;

                $products = $productKnowledge->getAll($userId, $limit, $offset, $search);
                $total = $productKnowledge->getCount($userId, $search);

                jsonResponse([
                    'success' => true,
                    'data' => $products,
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Обновить заметки для товара
        case '/api/ai/product-knowledge/notes':
            $auth->requireLogin();
            if (!isMethod('POST')) {
                jsonResponse(['success' => false, 'error' => 'Метод не разрешён'], 405);
            }

            $productId = (int)post('product_id');
            $notes = post('notes') ?? '';

            try {
                require_once APP_PATH . '/classes/ProductKnowledge.php';
                $productKnowledge = new ProductKnowledge();

                $result = $productKnowledge->updateCustomNotes($productId, $notes);

                jsonResponse(['success' => $result]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // Получить контекст товара для AI (для отладки)
        case '/api/ai/product-context':
            $auth->requireLogin();

            $nmId = get('nm_id');
            if (!$nmId) {
                jsonResponse(['success' => false, 'error' => 'nm_id обязателен'], 400);
            }

            try {
                require_once APP_PATH . '/classes/ProductKnowledge.php';
                $productKnowledge = new ProductKnowledge();

                $product = $productKnowledge->getByNmId($nmId);
                $context = $productKnowledge->getProductContextForAI($nmId);

                jsonResponse([
                    'success' => true,
                    'product' => $product,
                    'ai_context' => $context
                ]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
            break;

        // ==================== 404 ====================
        default:
            // Проверяем динамические роуты
            // Получение лога по ID: /api/logs/123
            if (preg_match('#^/api/logs/(\d+)$#', $requestUri, $matches)) {
                $auth->requireLogin();
                if ($auth->getUserRole() !== 'admin') {
                    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
                }

                $logId = (int)$matches[1];
                $log = ErrorLogger::getById($logId);

                if ($log) {
                    jsonResponse(['success' => true, 'data' => $log]);
                } else {
                    jsonResponse(['success' => false, 'error' => 'Лог не найден'], 404);
                }
            }

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
