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
