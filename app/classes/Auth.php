<?php
/**
 * Класс авторизации пользователей
 * Управление сессиями и проверка доступа
 */
class Auth
{
    private Database $db;
    private ?array $user = null;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadUser();
    }

    /**
     * Загрузка пользователя из сессии
     */
    private function loadUser(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->user = $this->db->fetchOne(
                "SELECT id, username, email, role, is_active, last_login FROM users WHERE id = ? AND is_active = 1",
                [$_SESSION['user_id']]
            );

            // Если пользователь не найден или деактивирован, очищаем сессию
            if (!$this->user) {
                $this->logout();
            }
        }
    }

    /**
     * Попытка авторизации
     * @param string $username Имя пользователя
     * @param string $password Пароль
     * @return bool Результат авторизации
     */
    public function login(string $username, string $password): bool
    {
        $username = sanitize($username);

        if (empty($username) || empty($password)) {
            return false;
        }

        // Поиск пользователя по имени или email
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
            [$username, $username]
        );

        if (!$user) {
            // Логируем неудачную попытку
            logError('Failed login attempt', ['username' => $username, 'ip' => getUserIP()]);
            return false;
        }

        // Проверка пароля
        if (!password_verify($password, $user['password'])) {
            logError('Failed login attempt - wrong password', ['username' => $username, 'ip' => getUserIP()]);
            return false;
        }

        // Успешная авторизация
        $this->setSession($user);

        // Обновляем время последнего входа
        $this->db->update(
            'users',
            ['last_login' => date('Y-m-d H:i:s')],
            'id = ?',
            [$user['id']]
        );

        // Логируем успешный вход
        $log = new OperationsLog();
        $log->add('login', 'user', $user['id'], null, ['ip' => getUserIP()]);

        return true;
    }

    /**
     * Установка сессии пользователя
     * @param array $user Данные пользователя
     */
    private function setSession(array $user): void
    {
        // Регенерируем ID сессии для безопасности
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();

        $this->user = $user;
    }

    /**
     * Выход из системы
     */
    public function logout(): void
    {
        if ($this->user) {
            $log = new OperationsLog();
            $log->add('logout', 'user', $this->user['id']);
        }

        $this->user = null;

        // Очищаем сессию
        $_SESSION = [];

        // Удаляем cookie сессии
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        // Уничтожаем сессию
        session_destroy();

        // Запускаем новую сессию
        session_start();
        session_regenerate_id(true);
    }

    /**
     * Проверка авторизации
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    /**
     * Проверка роли пользователя
     * @param string|array $roles Роль или массив ролей
     * @return bool
     */
    public function hasRole(string|array $roles): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        if (is_string($roles)) {
            $roles = [$roles];
        }

        return in_array($this->user['role'], $roles);
    }

    /**
     * Проверка, является ли пользователь администратором
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Получение текущего пользователя
     * @return array|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Получение ID текущего пользователя
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    /**
     * Получение имени текущего пользователя
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return $this->user['username'] ?? null;
    }

    /**
     * Получение роли текущего пользователя
     * @return string|null
     */
    public function getUserRole(): ?string
    {
        return $this->user['role'] ?? null;
    }

    /**
     * Требование авторизации
     * Редирект на страницу входа если не авторизован
     */
    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            setFlash('warning', 'Для доступа к этой странице необходимо авторизоваться');
            redirect('/login');
        }
    }

    /**
     * Требование определённой роли
     * @param string|array $roles Требуемая роль или массив ролей
     */
    public function requireRole(string|array $roles): void
    {
        $this->requireLogin();

        if (!$this->hasRole($roles)) {
            setFlash('error', 'У вас нет доступа к этой странице');
            redirect('/');
        }
    }

    /**
     * Изменение пароля
     * @param int $userId ID пользователя
     * @param string $currentPassword Текущий пароль
     * @param string $newPassword Новый пароль
     * @return bool
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->db->fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return false;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);

        $this->db->update(
            'users',
            ['password' => $hashedPassword],
            'id = ?',
            [$userId]
        );

        $log = new OperationsLog();
        $log->add('password_change', 'user', $userId);

        return true;
    }

    /**
     * Создание хеша пароля
     * @param string $password Пароль
     * @return string Хеш пароля
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    }
}
