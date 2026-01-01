<?php
/**
 * Глобальный обработчик ошибок PHP
 * Перехватывает все ошибки и исключения, логирует их
 */

// Обработчик ошибок PHP (warnings, notices, etc.)
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // Игнорируем подавленные ошибки (@)
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Маппинг уровней ошибок PHP на наши уровни
    $levels = [
        E_ERROR             => 'ERROR',
        E_WARNING           => 'WARNING',
        E_PARSE             => 'ERROR',
        E_NOTICE            => 'INFO',
        E_CORE_ERROR        => 'ERROR',
        E_CORE_WARNING      => 'WARNING',
        E_COMPILE_ERROR     => 'ERROR',
        E_COMPILE_WARNING   => 'WARNING',
        E_USER_ERROR        => 'ERROR',
        E_USER_WARNING      => 'WARNING',
        E_USER_NOTICE       => 'INFO',
        E_STRICT            => 'INFO',
        E_RECOVERABLE_ERROR => 'ERROR',
        E_DEPRECATED        => 'INFO',
        E_USER_DEPRECATED   => 'INFO',
    ];

    $level = $levels[$severity] ?? 'ERROR';

    // Логируем ошибку
    ErrorLogger::log($level, $message, [
        'file' => $file,
        'line' => $line,
        'severity' => $severity,
        'severity_name' => getSeverityName($severity)
    ]);

    // Возвращаем false чтобы продолжить стандартную обработку
    return false;
});

/**
 * Получить название уровня ошибки PHP
 */
function getSeverityName(int $severity): string
{
    $names = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];

    return $names[$severity] ?? 'UNKNOWN';
}

// Обработчик необработанных исключений
set_exception_handler(function (Throwable $e): void {
    // Логируем исключение
    ErrorLogger::logException($e);

    // Определяем, это API запрос или обычная страница
    $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApiRequest) {
        // Для API возвращаем JSON
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);

        $response = [
            'success' => false,
            'error' => $e->getMessage(),
            'error_type' => 'exception'
        ];

        // В режиме отладки добавляем детали
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $response['debug'] = [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Для обычных страниц показываем страницу ошибки
    http_response_code(500);

    echo '<!DOCTYPE html>
    <html lang="ru" data-bs-theme="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ошибка сервера</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-dark text-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="text-center">
            <h1 class="display-1 text-danger">500</h1>
            <p class="lead">Произошла ошибка сервера</p>';

    // В режиме отладки показываем детали
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo '<div class="alert alert-danger mt-3 text-start" style="max-width: 800px;">
            <strong>Ошибка:</strong> ' . htmlspecialchars($e->getMessage()) . '<br>
            <strong>Файл:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '
            <details class="mt-2">
                <summary>Stack Trace</summary>
                <pre class="mt-2 small" style="max-height: 300px; overflow: auto;">' .
                    htmlspecialchars($e->getTraceAsString()) . '
                </pre>
            </details>
        </div>';
    }

    echo '<a href="/" class="btn btn-primary mt-3">На главную</a>
        </div>
    </body>
    </html>';

    exit;
});

// Обработчик фатальных ошибок (при завершении скрипта)
register_shutdown_function(function (): void {
    $error = error_get_last();

    // Обрабатываем только фатальные ошибки
    $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if ($error && in_array($error['type'], $fatalErrors)) {
        // Логируем фатальную ошибку
        ErrorLogger::error('Fatal: ' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type'],
            'type_name' => getSeverityName($error['type'])
        ]);

        // Если буфер вывода ещё не отправлен, показываем страницу ошибки
        if (!headers_sent()) {
            $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

            if ($isApiRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);

                echo json_encode([
                    'success' => false,
                    'error' => 'Критическая ошибка сервера',
                    'error_type' => 'fatal'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo '<!DOCTYPE html>
                <html lang="ru" data-bs-theme="dark">
                <head>
                    <meta charset="UTF-8">
                    <title>Критическая ошибка</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                </head>
                <body class="bg-dark text-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
                    <div class="text-center">
                        <h1 class="display-1 text-danger">500</h1>
                        <p class="lead">Критическая ошибка сервера</p>
                        <a href="/" class="btn btn-primary mt-3">На главную</a>
                    </div>
                </body>
                </html>';
            }
        }
    }
});
