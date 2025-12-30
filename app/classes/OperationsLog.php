<?php
/**
 * Класс логирования операций
 * Запись всех действий пользователей в базу данных
 */
class OperationsLog
{
    private Database $db;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Добавление записи в лог
     * @param string $action Действие (login, logout, create_product, update_price, и т.д.)
     * @param string $entityType Тип сущности (user, product, api_settings, и т.д.)
     * @param int|null $entityId ID сущности
     * @param array|null $oldValues Старые значения (для обновлений)
     * @param array|null $newValues Новые значения
     * @return int ID записи лога
     */
    public function add(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): int {
        $userId = $_SESSION['user_id'] ?? null;

        $data = [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => getUserIP(),
            'user_agent' => substr(getUserAgent(), 0, 500)
        ];

        return $this->db->insert('operations_log', $data);
    }

    /**
     * Получение записей лога
     * @param array $filters Фильтры
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array
     */
    public function getEntries(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'ol.user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'ol.action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'ol.entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[] = 'ol.entity_id = ?';
            $params[] = $filters['entity_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'ol.created_at >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'ol.created_at <= ?';
            $params[] = $filters['date_to'];
        }

        $whereStr = implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "
            SELECT
                ol.*,
                u.username as user_name
            FROM operations_log ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE {$whereStr}
            ORDER BY ol.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $entries = $this->db->fetchAll($sql, $params);

        // Декодируем JSON поля
        foreach ($entries as &$entry) {
            $entry['old_values'] = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
            $entry['new_values'] = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;
        }

        return $entries;
    }

    /**
     * Получение количества записей
     * @param array $filters Фильтры
     * @return int
     */
    public function getCount(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        $whereStr = implode(' AND ', $where);

        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM operations_log WHERE {$whereStr}",
            $params
        );
    }

    /**
     * Получение последних действий пользователя
     * @param int $userId ID пользователя
     * @param int $limit Лимит записей
     * @return array
     */
    public function getUserActivity(int $userId, int $limit = 20): array
    {
        return $this->getEntries(['user_id' => $userId], $limit);
    }

    /**
     * Получение истории изменений сущности
     * @param string $entityType Тип сущности
     * @param int $entityId ID сущности
     * @param int $limit Лимит записей
     * @return array
     */
    public function getEntityHistory(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->getEntries([
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ], $limit);
    }

    /**
     * Очистка старых записей
     * @param int $daysToKeep Количество дней для хранения
     * @return int Количество удалённых записей
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        return $this->db->delete(
            'operations_log',
            'created_at < ?',
            [$cutoffDate]
        );
    }

    /**
     * Получение статистики по действиям
     * @param string|null $dateFrom Начальная дата
     * @param string|null $dateTo Конечная дата
     * @return array
     */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = ['1=1'];
        $params = [];

        if ($dateFrom) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo;
        }

        $whereStr = implode(' AND ', $where);

        // Статистика по действиям
        $byAction = $this->db->fetchAll(
            "SELECT action, COUNT(*) as count FROM operations_log WHERE {$whereStr} GROUP BY action ORDER BY count DESC",
            $params
        );

        // Статистика по пользователям
        $byUser = $this->db->fetchAll(
            "SELECT
                u.username,
                COUNT(*) as count
            FROM operations_log ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE {$whereStr}
            GROUP BY ol.user_id
            ORDER BY count DESC
            LIMIT 10",
            $params
        );

        // Статистика по типам сущностей
        $byEntityType = $this->db->fetchAll(
            "SELECT entity_type, COUNT(*) as count FROM operations_log WHERE {$whereStr} GROUP BY entity_type ORDER BY count DESC",
            $params
        );

        return [
            'by_action' => $byAction,
            'by_user' => $byUser,
            'by_entity_type' => $byEntityType,
            'total' => $this->getCount()
        ];
    }

    /**
     * Форматирование действия для отображения
     * @param string $action Код действия
     * @return string Человекочитаемое название
     */
    public static function formatAction(string $action): string
    {
        $actions = [
            'login' => 'Вход в систему',
            'logout' => 'Выход из системы',
            'create_product' => 'Создание товара',
            'update_product' => 'Обновление товара',
            'delete_product' => 'Удаление товара',
            'update_price_wb' => 'Обновление цены WB',
            'update_price_ozon' => 'Обновление цены Ozon',
            'update_stock' => 'Обновление остатков',
            'wb_update_prices' => 'Загрузка цен на WB',
            'wb_update_stocks' => 'Загрузка остатков на WB',
            'ozon_update_prices' => 'Загрузка цен на Ozon',
            'ozon_update_stocks' => 'Загрузка остатков на Ozon',
            'update_settings' => 'Изменение настроек',
            'password_change' => 'Смена пароля'
        ];

        return $actions[$action] ?? $action;
    }
}
