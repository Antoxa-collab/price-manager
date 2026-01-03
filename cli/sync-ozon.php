#!/usr/bin/env php
<?php
/**
 * CLI скрипт автоматической синхронизации отзывов и вопросов с Ozon
 *
 * Использование:
 *   php cli/sync-ozon.php [--reviews] [--questions] [--user=ID] [--verbose]
 *
 * Опции:
 *   --reviews    Синхронизировать только отзывы
 *   --questions  Синхронизировать только вопросы
 *   --user=ID    Синхронизировать только для указанного пользователя
 *   --verbose    Подробный вывод
 *
 * Cron (каждые 5 минут):
 *   docker exec price-manager-php php /var/www/html/cli/sync-ozon.php
 */

// Запуск только из CLI
if (php_sapi_name() !== 'cli') {
    die("Этот скрипт можно запускать только из командной строки\n");
}

// Подключение конфигурации
require_once dirname(__DIR__) . '/app/config.php';

// Парсинг аргументов
$options = getopt('', ['reviews', 'questions', 'user:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Синхронизация отзывов и вопросов с Ozon API

Использование:
  php cli/sync-ozon.php [опции]

Опции:
  --reviews     Синхронизировать только отзывы
  --questions   Синхронизировать только вопросы
  --user=ID     Синхронизировать только для указанного пользователя
  --verbose     Подробный вывод
  --help        Показать эту справку

Примеры:
  php cli/sync-ozon.php                    # Синхронизация всего для всех пользователей
  php cli/sync-ozon.php --reviews          # Только отзывы
  php cli/sync-ozon.php --user=1 --verbose # Для пользователя ID=1 с подробным выводом

Cron (каждые 5 минут):
  */5 * * * * docker exec price-manager-php php /var/www/html/cli/sync-ozon.php >> /var/log/ozon-sync.log 2>&1

HELP;
    exit(0);
}

$syncReviews = !isset($options['questions']) || isset($options['reviews']);
$syncQuestions = !isset($options['reviews']) || isset($options['questions']);
$specificUserId = isset($options['user']) ? (int)$options['user'] : null;
$verbose = isset($options['verbose']);

/**
 * Вывод сообщения
 */
function out(string $message, bool $forceShow = false): void
{
    global $verbose;
    if ($verbose || $forceShow) {
        echo date('[Y-m-d H:i:s] ') . $message . "\n";
    }
}

/**
 * Вывод ошибки
 */
function err(string $message): void
{
    fwrite(STDERR, date('[Y-m-d H:i:s] ERROR: ') . $message . "\n");
}

out("=== Запуск синхронизации Ozon ===", true);

try {
    $db = Database::getInstance();

    // Получаем пользователей с настроенным Ozon API
    if ($specificUserId) {
        $users = $db->fetchAll(
            "SELECT id, name FROM users WHERE id = ?",
            [$specificUserId]
        );
    } else {
        // Все пользователи с настроенным Ozon API
        $users = $db->fetchAll(
            "SELECT DISTINCT u.id, u.name
             FROM users u
             INNER JOIN user_settings us ON u.id = us.user_id
             WHERE us.setting_key = 'ozon_api_key' AND us.setting_value != ''"
        );
    }

    if (empty($users)) {
        out("Нет пользователей с настроенным Ozon API", true);
        exit(0);
    }

    out("Найдено пользователей: " . count($users), true);

    $totalStats = [
        'reviews_added' => 0,
        'reviews_updated' => 0,
        'reviews_skipped' => 0,
        'questions_added' => 0,
        'questions_updated' => 0,
        'questions_skipped' => 0,
        'errors' => 0
    ];

    foreach ($users as $user) {
        $userId = $user['id'];
        $userName = $user['name'];

        out("--- Пользователь: {$userName} (ID: {$userId}) ---");

        try {
            $ozonApi = new OzonAPI($userId);

            if (!$ozonApi->isConfigured()) {
                out("  Ozon API не настроен, пропуск");
                continue;
            }

            // Синхронизация отзывов
            if ($syncReviews) {
                out("  Синхронизация отзывов...");
                $reviewStats = syncReviews($db, $ozonApi, $userId);

                $totalStats['reviews_added'] += $reviewStats['added'];
                $totalStats['reviews_updated'] += $reviewStats['updated'];
                $totalStats['reviews_skipped'] += $reviewStats['skipped'];

                out("  Отзывы: +{$reviewStats['added']} / ~{$reviewStats['updated']} / ={$reviewStats['skipped']}");
            }

            // Синхронизация вопросов
            if ($syncQuestions) {
                out("  Синхронизация вопросов...");
                $questionStats = syncQuestions($db, $ozonApi, $userId);

                $totalStats['questions_added'] += $questionStats['added'];
                $totalStats['questions_updated'] += $questionStats['updated'];
                $totalStats['questions_skipped'] += $questionStats['skipped'];

                out("  Вопросы: +{$questionStats['added']} / ~{$questionStats['updated']} / ={$questionStats['skipped']}");
            }

        } catch (Exception $e) {
            err("Ошибка для пользователя {$userId}: " . $e->getMessage());
            $totalStats['errors']++;
        }
    }

    // Итоговая статистика
    out("=== Итого ===", true);
    if ($syncReviews) {
        out("Отзывы: добавлено={$totalStats['reviews_added']}, обновлено={$totalStats['reviews_updated']}, пропущено={$totalStats['reviews_skipped']}", true);
    }
    if ($syncQuestions) {
        out("Вопросы: добавлено={$totalStats['questions_added']}, обновлено={$totalStats['questions_updated']}, пропущено={$totalStats['questions_skipped']}", true);
    }
    if ($totalStats['errors'] > 0) {
        out("Ошибок: {$totalStats['errors']}", true);
    }
    out("=== Завершено ===", true);

} catch (Exception $e) {
    err("Критическая ошибка: " . $e->getMessage());
    exit(1);
}

/**
 * Синхронизация отзывов для пользователя
 */
function syncReviews(Database $db, OzonAPI $ozonApi, int $userId): array
{
    $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

    // Получаем все отзывы (максимум 20 страниц = 2000 отзывов)
    $result = $ozonApi->getAllReviews(20, 'ALL');

    if (!$result['success']) {
        throw new Exception('Ошибка Ozon API: ' . ($result['error'] ?? 'Unknown'));
    }

    out("    Получено с Ozon: " . count($result['reviews']) . " отзывов");

    foreach ($result['reviews'] as $review) {
        // Проверяем существование в локальной БД
        $existing = $db->fetchOne(
            "SELECT id, status FROM ai_reviews WHERE marketplace = 'ozon' AND marketplace_review_id = ?",
            [$review['marketplace_review_id']]
        );

        if ($existing) {
            // Не обновляем если уже обработан (approved/sent)
            if (in_array($existing['status'], ['approved', 'sent'])) {
                $stats['skipped']++;
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
            $stats['updated']++;
        } else {
            // Пропускаем если уже есть ответ на Ozon (comments_amount > 0)
            if ($review['comments_amount'] > 0) {
                $stats['skipped']++;
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
            $stats['added']++;
        }
    }

    return $stats;
}

/**
 * Синхронизация вопросов для пользователя
 */
function syncQuestions(Database $db, OzonAPI $ozonApi, int $userId): array
{
    $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

    // ВАЖНО: API возвращает до 10 вопросов за раз!
    // maxPages = 100 даст максимум 1000 вопросов
    $result = $ozonApi->getAllQuestions(100, 'ALL');

    if (!$result['success']) {
        throw new Exception('Ошибка Ozon API: ' . ($result['error'] ?? 'Unknown'));
    }

    out("    Получено с Ozon: " . count($result['questions']) . " вопросов");

    foreach ($result['questions'] as $question) {
        $existing = $db->fetchOne(
            "SELECT id, status FROM ai_questions WHERE marketplace = 'ozon' AND marketplace_question_id = ?",
            [$question['marketplace_question_id']]
        );

        if ($existing) {
            if (in_array($existing['status'], ['approved', 'sent'])) {
                $stats['skipped']++;
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
            $stats['updated']++;
        } else {
            // Пропускаем если уже есть ответ
            if ($question['answers_count'] > 0) {
                $stats['skipped']++;
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
            $stats['added']++;
        }
    }

    return $stats;
}
