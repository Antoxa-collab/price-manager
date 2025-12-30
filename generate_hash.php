<?php
/**
 * Скрипт для установки/сброса пароля администратора
 * Запустить через Docker: docker-compose exec php php generate_hash.php
 *
 * Можно указать свой пароль: docker-compose exec php php generate_hash.php mypassword
 */

require_once __DIR__ . '/app/config.php';

// Пароль из аргумента командной строки или по умолчанию
$password = $argv[1] ?? 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "=== Price Manager: Установка пароля администратора ===\n\n";
echo "Password: {$password}\n";
echo "Hash: {$hash}\n\n";

// Проверка хеша
echo "Verification: " . (password_verify($password, $hash) ? 'OK' : 'FAILED') . "\n\n";

// Обновление в БД
try {
    $db = Database::getInstance();

    // Проверяем существует ли пользователь
    $user = $db->fetchOne('SELECT id FROM users WHERE username = ?', ['admin']);

    if ($user) {
        $result = $db->update(
            'users',
            ['password' => $hash],
            'username = ?',
            ['admin']
        );
        echo "SUCCESS: Пароль администратора обновлён в базе данных!\n";
    } else {
        echo "INFO: Пользователь 'admin' не найден. Создаём...\n";

        $db->insert('users', [
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => $hash,
            'role' => 'admin',
            'is_active' => 1
        ]);

        echo "SUCCESS: Пользователь 'admin' создан!\n";
    }

    echo "\nТеперь вы можете войти:\n";
    echo "  Логин: admin\n";
    echo "  Пароль: {$password}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nПопробуйте выполнить SQL вручную:\n";
    echo "UPDATE users SET password='{$hash}' WHERE username='admin';\n";
}
