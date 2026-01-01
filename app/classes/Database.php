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

            // Формируем понятное сообщение об ошибке
            $errorMessage = $this->formatDbError($e, $sql);
            throw new Exception($errorMessage);
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
                    `quantity_in_pack` INT NOT NULL DEFAULT 1,
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
            'pieces_per_sheet' => "ALTER TABLE product_mappings ADD COLUMN `pieces_per_sheet` INT NOT NULL DEFAULT 1 COMMENT 'Сколько единиц получается из 1 листа/закупочной единицы' AFTER `quantity_in_pack`"
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
}
