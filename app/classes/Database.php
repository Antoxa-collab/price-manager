<?php
/**
 * Класс для работы с базой данных
 * Реализует паттерн Singleton для PDO подключения
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    /**
     * Приватный конструктор для Singleton
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Запрет клонирования
     */
    private function __clone() {}

    /**
     * Запрет десериализации
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Получение экземпляра класса
     * @return Database
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Подключение к базе данных
     */
    private function connect(): void
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            logError('Database connection failed: ' . $e->getMessage());
            throw new Exception('Ошибка подключения к базе данных');
        }
    }

    /**
     * Получение PDO объекта
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Получение PDO соединения напрямую (для совместимости)
     * @return PDO
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Выполнение SQL запроса с параметрами
     * @param string $sql SQL запрос
     * @param array $params Параметры запроса
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Детальное логирование ошибки БД
            ErrorLogger::dbError($sql, $e->getMessage(), $params);

            // Логируем параметры для отладки MySQL 22003
            error_log("[Database::query] PDOException: code={$e->getCode()}, msg={$e->getMessage()}");
            error_log("[Database::query] SQL: " . mb_substr($sql, 0, 500));
            error_log("[Database::query] Params: " . json_encode($params, JSON_UNESCAPED_UNICODE));

            // Перебрасываем оригинальный PDOException для catch в вызывающем коде
            throw $e;
        }
    }

    /**
     * Форматирование ошибки БД для пользователя
     * @param PDOException $e Исключение
     * @param string $sql SQL запрос
     * @return string
     */
    private function formatDbError(PDOException $e, string $sql): string
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Определяем тип ошибки для понятного сообщения
        if (stripos($message, "doesn't exist") !== false) {
            preg_match("/Table '([^']+)'/", $message, $matches);
            $table = $matches[1] ?? 'unknown';
            return "Таблица не найдена: {$table}";
        }

        if (stripos($message, 'Unknown column') !== false) {
            preg_match("/Unknown column '([^']+)'/", $message, $matches);
            $column = $matches[1] ?? 'unknown';
            return "Неизвестный столбец: {$column}";
        }

        if (stripos($message, 'Duplicate entry') !== false) {
            return "Запись уже существует (дубликат)";
        }

        if (stripos($message, 'foreign key constraint') !== false) {
            return "Нарушение связи между таблицами";
        }

        if (stripos($message, 'Data too long') !== false) {
            return "Данные слишком длинные для поля";
        }

        // Для режима отладки показываем полную ошибку
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            return "Ошибка БД [{$code}]: {$message} | SQL: " . mb_substr($sql, 0, 200);
        }

        return "Ошибка базы данных (код: {$code})";
    }

    /**
     * Получение одной записи
     * @param string $sql SQL запрос
     * @param array $params Параметры запроса
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Получение всех записей
     * @param string $sql SQL запрос
     * @param array $params Параметры запроса
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Получение одного значения
     * @param string $sql SQL запрос
     * @param array $params Параметры запроса
     * @return mixed
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Вставка записи
     * @param string $table Имя таблицы
     * @param array $data Данные для вставки
     * @return int ID вставленной записи
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Обновление записи
     * @param string $table Имя таблицы
     * @param array $data Данные для обновления
     * @param string $where Условие WHERE
     * @param array $whereParams Параметры для WHERE
     * @return int Количество затронутых строк
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Удаление записи
     * @param string $table Имя таблицы
     * @param string $where Условие WHERE
     * @param array $params Параметры для WHERE
     * @return int Количество удалённых строк
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Начало транзакции
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Подтверждение транзакции
     * @return bool
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Откат транзакции
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Проверка активности транзакции
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Экранирование значения
     * @param string $value Значение для экранирования
     * @return string
     */
    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * Проверить и создать необходимые таблицы
     * Вызывается при инициализации для автосоздания таблиц
     */
    public function ensureTables(): void
    {
        $tables = [
            'marketplace_products_cache' => "
                CREATE TABLE IF NOT EXISTS `marketplace_products_cache` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
                    `product_id` VARCHAR(100) NOT NULL,
                    `sku` VARCHAR(100) NULL,
                    `offer_id` VARCHAR(100) NULL,
                    `name` VARCHAR(500) NOT NULL,
                    `price` DECIMAL(12,2) NULL,
                    `min_price` DECIMAL(12,2) NULL,
                    `old_price` DECIMAL(12,2) NULL,
                    `stock` INT NULL DEFAULT 0,
                    `is_visible` TINYINT(1) NULL DEFAULT 1,
                    `raw_data` JSON NULL,
                    `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_cache` (`marketplace`, `product_id`),
                    KEY `idx_offer` (`offer_id`),
                    KEY `idx_name` (`name`(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'product_mappings' => "
                CREATE TABLE IF NOT EXISTS `product_mappings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `product_id` INT UNSIGNED NOT NULL,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
                    `marketplace_product_id` VARCHAR(100) NOT NULL,
                    `marketplace_sku` VARCHAR(100) NULL,
                    `marketplace_offer_id` VARCHAR(100) NULL,
                    `marketplace_name` VARCHAR(500) NULL,
                    `quantity_in_pack` INT UNSIGNED NOT NULL DEFAULT 1,
                    `pieces_per_sheet` INT UNSIGNED NOT NULL DEFAULT 1,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_mapping` (`product_id`, `marketplace`, `marketplace_product_id`),
                    KEY `idx_marketplace` (`marketplace`),
                    KEY `idx_product` (`product_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'error_logs' => "
                CREATE TABLE IF NOT EXISTS `error_logs` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `level` VARCHAR(20) NOT NULL DEFAULT 'ERROR',
                    `message` TEXT NOT NULL,
                    `context` JSON NULL,
                    `url` VARCHAR(500) NULL,
                    `method` VARCHAR(10) NULL,
                    `user_id` INT UNSIGNED NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `user_agent` VARCHAR(500) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_level` (`level`),
                    KEY `idx_created` (`created_at`),
                    KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'price_upload_history' => "
                CREATE TABLE IF NOT EXISTS `price_upload_history` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NULL,
                    `marketplace` VARCHAR(50) NOT NULL DEFAULT 'ozon',
                    `product_id` INT UNSIGNED NULL COMMENT 'Наш product_id',
                    `mapping_id` INT UNSIGNED NULL COMMENT 'ID сопоставления',
                    `marketplace_product_id` VARCHAR(100) NULL COMMENT 'ID товара на маркетплейсе',
                    `old_price` DECIMAL(12,2) NULL,
                    `new_price` DECIMAL(12,2) NULL,
                    `old_min_price` DECIMAL(12,2) NULL,
                    `new_min_price` DECIMAL(12,2) NULL,
                    `status` VARCHAR(50) DEFAULT 'pending',
                    `error_message` TEXT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user` (`user_id`),
                    KEY `idx_marketplace` (`marketplace`),
                    KEY `idx_created_at` (`created_at`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            'user_api_keys' => "
                CREATE TABLE IF NOT EXISTS `user_api_keys` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `service` VARCHAR(50) NOT NULL COMMENT 'Сервис (claude, openai, etc)',
                    `api_key` TEXT NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_service` (`user_id`, `service`),
                    KEY `idx_service` (`service`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            'calculator_settings' => "
                CREATE TABLE IF NOT EXISTS `calculator_settings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `marketplace` ENUM('ozon', 'wildberries') NOT NULL,
                    `markup_min` DECIMAL(10,2) DEFAULT 0 COMMENT 'Минимальная наценка',
                    `markup_extra` DECIMAL(5,2) DEFAULT 0 COMMENT 'Дополнительная наценка (%)',
                    `discount` DECIMAL(5,2) DEFAULT 0 COMMENT 'Скидка (%)',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_marketplace` (`user_id`, `marketplace`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Настройки калькулятора цен'
            ",

            // AI Assistant tables
            'ai_prompts' => "
                CREATE TABLE IF NOT EXISTS `ai_prompts` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
                    `type` ENUM('review', 'question') NOT NULL DEFAULT 'review',
                    `sentiment` ENUM('positive', 'negative', 'neutral') NULL COMMENT 'Для отзывов: тональность',
                    `name` VARCHAR(100) NOT NULL COMMENT 'Название промпта',
                    `system_prompt` TEXT NOT NULL COMMENT 'Системный промпт',
                    `user_prompt_template` TEXT NOT NULL COMMENT 'Шаблон пользовательского промпта',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_marketplace_type` (`marketplace`, `type`),
                    KEY `idx_sentiment` (`sentiment`),
                    KEY `idx_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_examples' => "
                CREATE TABLE IF NOT EXISTS `ai_examples` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `prompt_id` INT UNSIGNED NOT NULL,
                    `input_text` TEXT NOT NULL COMMENT 'Пример входящего текста (отзыв/вопрос)',
                    `output_text` TEXT NOT NULL COMMENT 'Пример ответа',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_prompt` (`prompt_id`),
                    KEY `idx_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_product_knowledge' => "
                CREATE TABLE IF NOT EXISTS `ai_product_knowledge` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `product_id` INT UNSIGNED NULL COMMENT 'Ссылка на наш товар (NULL = глобальное знание)',
                    `marketplace_product_id` VARCHAR(100) NULL COMMENT 'ID товара на маркетплейсе',
                    `knowledge_type` ENUM('description', 'faq', 'specs', 'note') NOT NULL DEFAULT 'note',
                    `title` VARCHAR(255) NULL,
                    `content` TEXT NOT NULL COMMENT 'Информация о товаре для AI',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_product` (`product_id`),
                    KEY `idx_marketplace_product` (`marketplace_product_id`),
                    KEY `idx_type` (`knowledge_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_reviews' => "
                CREATE TABLE IF NOT EXISTS `ai_reviews` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
                    `marketplace_review_id` VARCHAR(100) NOT NULL COMMENT 'ID отзыва на маркетплейсе',
                    `marketplace_product_id` VARCHAR(100) NULL,
                    `product_id` INT UNSIGNED NULL COMMENT 'Наш product_id',
                    `author_name` VARCHAR(255) NULL,
                    `rating` TINYINT UNSIGNED NULL COMMENT 'Оценка 1-5',
                    `review_text` TEXT NULL COMMENT 'Текст отзыва',
                    `review_pros` TEXT NULL COMMENT 'Достоинства',
                    `review_cons` TEXT NULL COMMENT 'Недостатки',
                    `review_date` DATETIME NULL,
                    `status` ENUM('new', 'generating', 'generated', 'approved', 'sent', 'skipped', 'error') NOT NULL DEFAULT 'new',
                    `generated_response` TEXT NULL COMMENT 'Сгенерированный ответ',
                    `edited_response` TEXT NULL COMMENT 'Отредактированный ответ',
                    `sent_response` TEXT NULL COMMENT 'Отправленный ответ',
                    `sent_at` DATETIME NULL,
                    `error_message` TEXT NULL,
                    `prompt_id` INT UNSIGNED NULL COMMENT 'Какой промпт использовался',
                    `tokens_used` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_review` (`marketplace`, `marketplace_review_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_marketplace_product` (`marketplace_product_id`),
                    KEY `idx_product` (`product_id`),
                    KEY `idx_rating` (`rating`),
                    KEY `idx_date` (`review_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_questions' => "
                CREATE TABLE IF NOT EXISTS `ai_questions` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex') NOT NULL DEFAULT 'ozon',
                    `marketplace_question_id` VARCHAR(100) NOT NULL COMMENT 'ID вопроса на маркетплейсе',
                    `marketplace_product_id` VARCHAR(100) NULL,
                    `product_id` INT UNSIGNED NULL COMMENT 'Наш product_id',
                    `author_name` VARCHAR(255) NULL,
                    `question_text` TEXT NOT NULL,
                    `question_date` DATETIME NULL,
                    `status` ENUM('new', 'generating', 'generated', 'approved', 'sent', 'skipped', 'error') NOT NULL DEFAULT 'new',
                    `generated_response` TEXT NULL,
                    `edited_response` TEXT NULL,
                    `sent_response` TEXT NULL,
                    `sent_at` DATETIME NULL,
                    `error_message` TEXT NULL,
                    `prompt_id` INT UNSIGNED NULL,
                    `tokens_used` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_question` (`marketplace`, `marketplace_question_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_marketplace_product` (`marketplace_product_id`),
                    KEY `idx_product` (`product_id`),
                    KEY `idx_date` (`question_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_settings' => "
                CREATE TABLE IF NOT EXISTS `ai_settings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `setting_key` VARCHAR(100) NOT NULL,
                    `setting_value` TEXT NULL,
                    `marketplace` ENUM('ozon', 'wildberries', 'yandex', 'all') NOT NULL DEFAULT 'all',
                    `description` VARCHAR(255) NULL,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_setting` (`setting_key`, `marketplace`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'ai_generation_log' => "
                CREATE TABLE IF NOT EXISTS `ai_generation_log` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `type` ENUM('review', 'question') NOT NULL,
                    `item_id` INT UNSIGNED NOT NULL COMMENT 'ID из ai_reviews или ai_questions',
                    `prompt_id` INT UNSIGNED NULL,
                    `model` VARCHAR(100) NULL COMMENT 'Модель Claude',
                    `input_tokens` INT UNSIGNED NULL,
                    `output_tokens` INT UNSIGNED NULL,
                    `total_tokens` INT UNSIGNED NULL,
                    `generation_time_ms` INT UNSIGNED NULL,
                    `status` ENUM('success', 'error') NOT NULL DEFAULT 'success',
                    `error_message` TEXT NULL,
                    `request_data` JSON NULL COMMENT 'Отправленный запрос (для отладки)',
                    `response_data` JSON NULL COMMENT 'Полный ответ API',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_type_item` (`type`, `item_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];

        foreach ($tables as $tableName => $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                error_log("Ошибка создания таблицы {$tableName}: " . $e->getMessage());
            }
        }

        // Добавляем недостающие колонки в products (для совместимости со старой схемой)
        $this->ensureProductColumns();

        // Добавляем недостающие колонки в product_mappings
        $this->ensureMappingColumns();

        // Добавляем недостающие колонки в wb_products_cache (barcode для остатков)
        $this->ensureWbCacheColumns();

        // Добавляем недостающие колонки в AI таблицы
        $this->ensureAIColumns();

        // Добавляем few-shot примеры для WB промптов
        $this->ensureFewShotExamples();
    }

    /**
     * Проверить и добавить недостающие колонки в таблицу products
     */
    private function ensureProductColumns(): void
    {
        // Проверяем существование таблицы products
        try {
            $result = $this->pdo->query("SHOW TABLES LIKE 'products'")->fetch();
            if (!$result) {
                return; // Таблица не существует, пропускаем
            }
        } catch (PDOException $e) {
            return;
        }

        // Получаем список существующих колонок
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return;
        }

        // Колонки для добавления
        $columnsToAdd = [
            'markup_min_price' => "ALTER TABLE products ADD COLUMN `markup_min_price` DECIMAL(5,2) NOT NULL DEFAULT 20.00 COMMENT 'Наценка для минимальной цены (%)'",
            'markup_your_price' => "ALTER TABLE products ADD COLUMN `markup_your_price` DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Доп.наценка для вашей цены (%)'",
            'cost_price' => "ALTER TABLE products ADD COLUMN `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Себестоимость'"
        ];

        foreach ($columnsToAdd as $column => $sql) {
            if (!in_array($column, $columns)) {
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException $e) {
                    error_log("Ошибка добавления колонки {$column}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Проверить и добавить недостающие колонки в таблицу product_mappings
     */
    private function ensureMappingColumns(): void
    {
        // Проверяем существование таблицы
        try {
            $result = $this->pdo->query("SHOW TABLES LIKE 'product_mappings'")->fetch();
            if (!$result) {
                return;
            }
        } catch (PDOException $e) {
            return;
        }

        // Получаем список существующих колонок
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM product_mappings")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return;
        }

        // Колонки для добавления
        $columnsToAdd = [
            'user_id' => "ALTER TABLE product_mappings ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ID пользователя' AFTER `id`",
            'pieces_per_sheet' => "ALTER TABLE product_mappings ADD COLUMN `pieces_per_sheet` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Сколько единиц получается из 1 листа/закупочной единицы' AFTER `quantity_in_pack`",
            'custom_min_price' => "ALTER TABLE product_mappings ADD COLUMN `custom_min_price` DECIMAL(10,2) DEFAULT NULL COMMENT 'Ручная минимальная цена' AFTER `pieces_per_sheet`",
            'is_min_price_edited' => "ALTER TABLE product_mappings ADD COLUMN `is_min_price_edited` TINYINT(1) DEFAULT 0 COMMENT 'Флаг ручного редактирования мин. цены' AFTER `custom_min_price`",
            'custom_discount' => "ALTER TABLE product_mappings ADD COLUMN `custom_discount` DECIMAL(5,2) DEFAULT 90 COMMENT 'Скидка на WB (по умолчанию 90%)' AFTER `is_min_price_edited`",
            'is_discount_edited' => "ALTER TABLE product_mappings ADD COLUMN `is_discount_edited` TINYINT(1) DEFAULT 0 COMMENT 'Флаг ручного редактирования скидки' AFTER `custom_discount`"
        ];

        // Добавляем индекс для user_id если колонка добавлена
        if (!in_array('user_id', $columns)) {
            try {
                $this->pdo->exec("ALTER TABLE product_mappings ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ID пользователя' AFTER `id`");
                $this->pdo->exec("CREATE INDEX idx_pm_user_id ON product_mappings(user_id)");
                error_log("[Database] Added user_id column and index to product_mappings");
            } catch (PDOException $e) {
                error_log("Ошибка добавления колонки user_id: " . $e->getMessage());
            }
            // Удаляем из списка, так как уже добавили
            unset($columnsToAdd['user_id']);
        }

        foreach ($columnsToAdd as $column => $sql) {
            if (!in_array($column, $columns)) {
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException $e) {
                    error_log("Ошибка добавления колонки {$column}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Проверить и добавить недостающие колонки в таблицу wb_products_cache
     * Добавляет поле barcode для корректной загрузки остатков на WB
     */
    private function ensureWbCacheColumns(): void
    {
        // Проверяем существование таблицы
        try {
            $result = $this->pdo->query("SHOW TABLES LIKE 'wb_products_cache'")->fetch();
            if (!$result) {
                return;
            }
        } catch (PDOException $e) {
            return;
        }

        // Получаем список существующих колонок
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM wb_products_cache")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return;
        }

        // Добавляем barcode если его нет
        if (!in_array('barcode', $columns)) {
            try {
                $this->pdo->exec("ALTER TABLE wb_products_cache ADD COLUMN `barcode` VARCHAR(255) NULL AFTER `vendor_code`");
                error_log("[Database] Добавлена колонка barcode в wb_products_cache");

                // Создаём индекс
                $this->pdo->exec("CREATE INDEX idx_wb_products_barcode ON wb_products_cache(barcode)");
                error_log("[Database] Создан индекс idx_wb_products_barcode");
            } catch (PDOException $e) {
                error_log("Ошибка добавления колонки barcode: " . $e->getMessage());
            }
        }

        // Добавляем is_oversized для фильтрации КГТ-товаров
        if (!in_array('is_oversized', $columns)) {
            try {
                $this->pdo->exec("ALTER TABLE wb_products_cache ADD COLUMN `is_oversized` TINYINT(1) DEFAULT 0 COMMENT 'КГТ-товар (любая сторона > 1200мм)'");
                error_log("[Database] Добавлена колонка is_oversized в wb_products_cache");
            } catch (PDOException $e) {
                error_log("Ошибка добавления колонки is_oversized: " . $e->getMessage());
            }
        }

        // НЕ сбрасываем и не обновляем is_oversized автоматически —
        // флаг устанавливается только при ошибке 409 от WB API (CargoWarehouseRestrictionSGTKGTPlus)
        // Это гарантирует, что мы не потеряем флаги, установленные из реального ответа API
    }

    /**
     * Выполнение SQL запроса без возврата результата
     * @param string $sql SQL запрос
     * @param array $params Параметры запроса
     * @return int Количество затронутых строк
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Получение последнего вставленного ID
     * @return int
     */
    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Проверить и добавить недостающие колонки в AI таблицы (ai_reviews, ai_questions)
     * Добавлены для синхронизации с Ozon API
     */
    private function ensureAIColumns(): void
    {
        // ===== ai_reviews =====
        try {
            $result = $this->pdo->query("SHOW TABLES LIKE 'ai_reviews'")->fetch();
            if ($result) {
                $columns = $this->pdo->query("SHOW COLUMNS FROM ai_reviews")->fetchAll(PDO::FETCH_COLUMN);

                // user_id не добавляем - система однопользовательская
                $columnsToAdd = [
                    'ozon_status' => "ALTER TABLE ai_reviews ADD COLUMN `ozon_status` VARCHAR(20) NULL COMMENT 'Статус на Ozon: PROCESSED/UNPROCESSED' AFTER `review_date`",
                    'comments_amount' => "ALTER TABLE ai_reviews ADD COLUMN `comments_amount` INT DEFAULT 0 COMMENT 'Количество комментариев на Ozon' AFTER `ozon_status`",
                    'ozon_comment_id' => "ALTER TABLE ai_reviews ADD COLUMN `ozon_comment_id` VARCHAR(100) NULL COMMENT 'ID нашего комментария на Ozon' AFTER `sent_at`"
                ];

                foreach ($columnsToAdd as $column => $sql) {
                    if (!in_array($column, $columns)) {
                        try {
                            $this->pdo->exec($sql);
                        } catch (PDOException $e) {
                            error_log("Ошибка добавления колонки ai_reviews.{$column}: " . $e->getMessage());
                        }
                    }
                }

            }
        } catch (PDOException $e) {
            error_log("Ошибка обновления ai_reviews: " . $e->getMessage());
        }

        // ===== ai_questions =====
        try {
            $result = $this->pdo->query("SHOW TABLES LIKE 'ai_questions'")->fetch();
            if ($result) {
                $columns = $this->pdo->query("SHOW COLUMNS FROM ai_questions")->fetchAll(PDO::FETCH_COLUMN);

                // user_id не добавляем - система однопользовательская
                $columnsToAdd = [
                    'ozon_status' => "ALTER TABLE ai_questions ADD COLUMN `ozon_status` VARCHAR(20) NULL COMMENT 'Статус на Ozon: NEW/VIEWED/PROCESSED' AFTER `question_date`",
                    'answers_count' => "ALTER TABLE ai_questions ADD COLUMN `answers_count` INT DEFAULT 0 COMMENT 'Количество ответов на Ozon' AFTER `ozon_status`",
                    'ozon_answer_id' => "ALTER TABLE ai_questions ADD COLUMN `ozon_answer_id` VARCHAR(100) NULL COMMENT 'ID нашего ответа на Ozon' AFTER `sent_at`"
                ];

                foreach ($columnsToAdd as $column => $sql) {
                    if (!in_array($column, $columns)) {
                        try {
                            $this->pdo->exec($sql);
                        } catch (PDOException $e) {
                            error_log("Ошибка добавления колонки ai_questions.{$column}: " . $e->getMessage());
                        }
                    }
                }

            }
        } catch (PDOException $e) {
            error_log("Ошибка обновления ai_questions: " . $e->getMessage());
        }
    }

    /**
     * Добавить few-shot примеры для WB промптов
     */
    private function ensureFewShotExamples(): void
    {
        try {
            // Проверяем, есть ли уже примеры для WB
            $count = $this->pdo->query(
                "SELECT COUNT(*) FROM ai_examples e
                 JOIN ai_prompts p ON e.prompt_id = p.id
                 WHERE p.marketplace = 'wildberries'"
            )->fetchColumn();

            if ($count > 0) {
                return; // Примеры уже есть
            }

            // Примеры для каждого типа промпта
            $examples = [
                'Ответ на положительный отзыв WB' => [
                    ['Отзыв: Отличная фанера, ровная, без сучков. Использовал для полок в гараже - идеально подошла. Буду заказывать ещё!', 'Благодарим за отзыв! Рады, что фанера подошла для ваших полок. Будем рады видеть вас снова!'],
                    ['Отзыв: Хорошее качество за эту цену. Доставили быстро, упаковано надёжно.', 'Спасибо за оценку! Стараемся поддерживать баланс качества и цены. Надёжная упаковка — наш приоритет при доставке.'],
                    ['Отзыв: Супер! Заказываю уже третий раз, всегда отличное качество.', 'Благодарим за доверие и постоянство! Приятно, что качество соответствует вашим ожиданиям.']
                ],
                'Ответ на негативный отзыв WB' => [
                    ['Отзыв: Пришла фанера с трещиной на углу. Очень расстроен, пришлось отпиливать часть.', 'Приносим извинения за доставленное неудобство. Повреждение могло произойти при транспортировке. Пожалуйста, свяжитесь с нами — поможем решить вопрос.'],
                    ['Отзыв: Размеры не соответствуют заявленным, на 2 см меньше.', 'Благодарим за обратную связь. Допуск по ГОСТ составляет ±3мм. Если расхождение больше — готовы рассмотреть замену. Напишите нам для уточнения.']
                ],
                'Ответ на нейтральный отзыв WB' => [
                    ['Отзыв: Нормальная фанера, ничего особенного. Для дачи сойдёт.', 'Спасибо за отзыв! Рады, что товар подошёл для ваших задач.'],
                    ['Отзыв: Качество среднее, но за такую цену ожидал большего.', 'Благодарим за обратную связь. Учтём ваше мнение. Если есть конкретные пожелания — будем рады услышать.']
                ],
                'Ответ на вопрос WB' => [
                    ['Вопрос: Подойдёт ли эта фанера для пола в ванной комнате?', 'Для ванной рекомендуем влагостойкую фанеру марки ФСФ. Данный товар — марки ФК, подходит для сухих помещений. Посмотрите раздел "Влагостойкая фанера" в нашем каталоге.'],
                    ['Вопрос: Какой максимальный вес выдерживает полка из этой фанеры?', 'Несущая способность зависит от толщины и пролёта полки. Фанера 18мм при пролёте 60см выдерживает до 30кг. Для больших нагрузок рекомендуем увеличить толщину или добавить рёбра жёсткости.'],
                    ['Вопрос: Есть ли у вас фанера толщиной 6мм?', 'Да, фанера 6мм есть в наличии. Найдите её в нашем каталоге по фильтру "толщина". Если нужна помощь с выбором — напишите.']
                ]
            ];

            $stmt = $this->pdo->prepare(
                "INSERT INTO ai_examples (prompt_id, input_text, output_text, is_active, created_at)
                 SELECT id, ?, ?, 1, NOW()
                 FROM ai_prompts
                 WHERE name = ? AND marketplace = 'wildberries'"
            );

            foreach ($examples as $promptName => $exampleList) {
                foreach ($exampleList as $example) {
                    $stmt->execute([$example[0], $example[1], $promptName]);
                }
            }

            error_log("[Database] Добавлены few-shot примеры для WB промптов");
        } catch (PDOException $e) {
            error_log("Ошибка добавления few-shot примеров: " . $e->getMessage());
        }
    }
}
