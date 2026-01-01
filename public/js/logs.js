/**
 * Страница логов ошибок
 * Загрузка, фильтрация, просмотр деталей
 */

$(document).ready(function () {
    // Инициализация
    loadLogs();

    // Обработчики фильтров
    $('#filterLevel').on('change', loadLogs);
    $('#filterLimit').on('change', loadLogs);
    $('#filterSearch').on('input', debounce(loadLogs, 500));

    // Быстрые фильтры по уровню
    $('.level-filter').on('click', function () {
        $('.level-filter').removeClass('active');
        $(this).addClass('active');
        $('#filterLevel').val($(this).data('level'));
        loadLogs();
    });

    // Кнопка обновления
    $('#refreshLogsBtn').on('click', function () {
        loadLogs();
        updateStatistics();
    });

    // Кнопка очистки старых логов
    $('#cleanupLogsBtn').on('click', function () {
        if (confirm('Удалить логи старше 7 дней?')) {
            cleanupLogs();
        }
    });

    // Кнопка очистки всех логов
    $('#clearAllLogsBtn').on('click', function () {
        if (confirm('Вы уверены, что хотите удалить ВСЕ логи? Это действие необратимо.')) {
            clearAllLogs();
        }
    });

    // Кнопка копирования в модальном окне
    $('#copyLogBtn').on('click', copyLogDetails);
});

/**
 * Загрузка логов
 */
function loadLogs() {
    const level = $('#filterLevel').val();
    const limit = $('#filterLimit').val();
    const search = $('#filterSearch').val();

    $('#logsTableBody').html(`
        <tr>
            <td colspan="6" class="text-center text-muted py-5">
                <div class="spinner-border text-secondary" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <p class="mt-3">Загрузка логов...</p>
            </td>
        </tr>
    `);

    $.ajax({
        url: '/api/logs',
        method: 'GET',
        data: {
            level: level,
            limit: limit,
            search: search
        },
        success: function (response) {
            if (response.success) {
                renderLogs(response.data);
                $('#logsCount').text(response.data.length);
                $('#lastUpdateTime').text(new Date().toLocaleTimeString('ru-RU'));
            } else {
                showError('Ошибка загрузки логов: ' + response.error);
            }
        },
        error: function (xhr) {
            showError('Ошибка запроса: ' + (xhr.responseJSON?.error || xhr.statusText));
        }
    });
}

/**
 * Отрисовка таблицы логов
 * @param {Array} logs Массив логов
 */
function renderLogs(logs) {
    if (!logs || logs.length === 0) {
        $('#logsTableBody').html(`
            <tr>
                <td colspan="6" class="text-center text-muted py-5">
                    <i class="bi bi-check-circle display-4 text-success"></i>
                    <p class="mt-3">Нет записей в логах</p>
                </td>
            </tr>
        `);
        return;
    }

    let html = '';
    logs.forEach(function (log) {
        const levelClass = getLevelClass(log.level);
        const levelIcon = getLevelIcon(log.level);
        const timeFormatted = formatDateTime(log.created_at);
        const messageShort = truncateText(log.message, 80);
        const urlShort = truncateText(log.url || '-', 30);

        html += `
            <tr class="log-row" data-id="${log.id}">
                <td class="small text-muted">${timeFormatted}</td>
                <td>
                    <span class="badge ${levelClass}">
                        <i class="bi ${levelIcon} me-1"></i>${log.level}
                    </span>
                </td>
                <td class="small">${escapeHtml(messageShort)}</td>
                <td class="small text-muted"><code>${escapeHtml(urlShort)}</code></td>
                <td class="small">${log.username || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary view-log-btn" data-id="${log.id}" title="Подробнее">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    $('#logsTableBody').html(html);

    // Обработчик клика по строке или кнопке просмотра
    $('.view-log-btn, .log-row').on('click', function (e) {
        if ($(e.target).closest('.view-log-btn').length || e.target === this) {
            const logId = $(this).data('id') || $(this).closest('.log-row').data('id');
            showLogDetails(logId);
        }
    });
}

/**
 * Показать детали лога
 * @param {number} logId ID лога
 */
function showLogDetails(logId) {
    $.ajax({
        url: '/api/logs/' + logId,
        method: 'GET',
        success: function (response) {
            if (response.success && response.data) {
                const log = response.data;

                $('#detailLogId').text('#' + log.id);
                $('#detailTime').text(formatDateTime(log.created_at));
                $('#detailLevel').html(`<span class="badge ${getLevelClass(log.level)}">${log.level}</span>`);
                $('#detailUrl').text(log.url || '-');
                $('#detailMethod').text(log.method || '-');
                $('#detailIp').text(log.ip_address || '-');
                $('#detailUser').text(log.username || 'Гость');
                $('#detailMessage').text(log.message);

                // Stack Trace
                if (log.context) {
                    let context;
                    try {
                        context = typeof log.context === 'string' ? JSON.parse(log.context) : log.context;
                    } catch (e) {
                        context = { raw: log.context };
                    }

                    if (context.trace) {
                        $('#detailTrace').text(context.trace);
                        $('#detailTraceBlock').show();
                    } else {
                        $('#detailTraceBlock').hide();
                    }

                    // Контекст без trace
                    const contextWithoutTrace = { ...context };
                    delete contextWithoutTrace.trace;

                    if (Object.keys(contextWithoutTrace).length > 0) {
                        $('#detailContext').text(JSON.stringify(contextWithoutTrace, null, 2));
                        $('#detailContextBlock').show();
                    } else {
                        $('#detailContextBlock').hide();
                    }
                } else {
                    $('#detailTraceBlock').hide();
                    $('#detailContextBlock').hide();
                }

                // User Agent
                if (log.user_agent) {
                    $('#detailUserAgent').text(log.user_agent);
                    $('#detailUserAgentBlock').show();
                } else {
                    $('#detailUserAgentBlock').hide();
                }

                // Сохраняем данные для копирования
                $('#logDetailModal').data('log', log);

                // Показываем модальное окно
                const modal = new bootstrap.Modal(document.getElementById('logDetailModal'));
                modal.show();
            } else {
                showError('Лог не найден');
            }
        },
        error: function (xhr) {
            showError('Ошибка загрузки деталей: ' + (xhr.responseJSON?.error || xhr.statusText));
        }
    });
}

/**
 * Копировать детали лога в буфер обмена
 */
function copyLogDetails() {
    const log = $('#logDetailModal').data('log');
    if (!log) return;

    const text = `
Лог #${log.id}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Время: ${formatDateTime(log.created_at)}
Уровень: ${log.level}
URL: ${log.url || '-'}
Метод: ${log.method || '-'}
IP: ${log.ip_address || '-'}
Пользователь: ${log.username || 'Гость'}

Сообщение:
${log.message}

${log.context ? 'Контекст:\n' + (typeof log.context === 'string' ? log.context : JSON.stringify(JSON.parse(log.context), null, 2)) : ''}

User-Agent: ${log.user_agent || '-'}
    `.trim();

    navigator.clipboard.writeText(text).then(function () {
        showSuccess('Скопировано в буфер обмена');
    }).catch(function () {
        showError('Не удалось скопировать');
    });
}

/**
 * Очистить старые логи
 */
function cleanupLogs() {
    $.ajax({
        url: '/api/logs/cleanup',
        method: 'POST',
        success: function (response) {
            if (response.success) {
                showSuccess(`Удалено ${response.deleted} старых записей`);
                loadLogs();
                updateStatistics();
            } else {
                showError('Ошибка очистки: ' + response.error);
            }
        },
        error: function (xhr) {
            showError('Ошибка запроса: ' + (xhr.responseJSON?.error || xhr.statusText));
        }
    });
}

/**
 * Очистить все логи
 */
function clearAllLogs() {
    $.ajax({
        url: '/api/logs/clear',
        method: 'POST',
        success: function (response) {
            if (response.success) {
                showSuccess(`Удалено ${response.deleted} записей`);
                loadLogs();
                updateStatistics();
            } else {
                showError('Ошибка очистки: ' + response.error);
            }
        },
        error: function (xhr) {
            showError('Ошибка запроса: ' + (xhr.responseJSON?.error || xhr.statusText));
        }
    });
}

/**
 * Обновить статистику
 */
function updateStatistics() {
    $.ajax({
        url: '/api/logs/stats',
        method: 'GET',
        success: function (response) {
            if (response.success) {
                const stats = response.data;
                $('#statTotal').text(stats.total);
                $('#statLast24h').text(stats.last_24h);
                $('#statLastHour').text(stats.last_hour);

                // Подсветка если много ошибок за час
                if (stats.last_hour > 10) {
                    $('#statLastHour').addClass('text-warning');
                } else {
                    $('#statLastHour').removeClass('text-warning');
                }

                // Количество всех ошибок (ERROR, API_ERROR, DB_ERROR)
                let errorCount = 0;
                if (stats.by_level) {
                    stats.by_level.forEach(function (item) {
                        if (item.level === 'ERROR' || item.level === 'API_ERROR' || item.level === 'DB_ERROR') {
                            errorCount += parseInt(item.count);
                        }
                    });
                }
                $('#statErrors').text(errorCount);
            }
        }
    });
}

/**
 * Получить CSS класс для уровня ошибки
 * @param {string} level Уровень
 * @returns {string} CSS класс
 */
function getLevelClass(level) {
    const classes = {
        'ERROR': 'bg-danger',
        'API_ERROR': 'bg-danger',
        'DB_ERROR': 'bg-danger',
        'WARNING': 'bg-warning text-dark',
        'INFO': 'bg-info text-dark',
        'API_OK': 'bg-success',
        'DEBUG': 'bg-secondary'
    };
    return classes[level] || 'bg-secondary';
}

/**
 * Получить иконку для уровня ошибки
 * @param {string} level Уровень
 * @returns {string} Класс иконки Bootstrap
 */
function getLevelIcon(level) {
    const icons = {
        'ERROR': 'bi-x-circle-fill',
        'API_ERROR': 'bi-cloud-slash',
        'DB_ERROR': 'bi-database-x',
        'WARNING': 'bi-exclamation-triangle-fill',
        'INFO': 'bi-info-circle-fill',
        'API_OK': 'bi-cloud-check',
        'DEBUG': 'bi-bug'
    };
    return icons[level] || 'bi-circle';
}

/**
 * Форматировать дату и время
 * @param {string} dateStr Строка даты
 * @returns {string} Отформатированная дата
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

/**
 * Обрезать текст
 * @param {string} text Текст
 * @param {number} maxLength Максимальная длина
 * @returns {string} Обрезанный текст
 */
function truncateText(text, maxLength) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}

/**
 * Экранировать HTML
 * @param {string} text Текст
 * @returns {string} Экранированный текст
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Debounce функция
 * @param {Function} func Функция
 * @param {number} wait Задержка в мс
 * @returns {Function}
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Показать сообщение об успехе
 * @param {string} message Сообщение
 */
function showSuccess(message) {
    showToast(message, 'success');
}

/**
 * Показать сообщение об ошибке
 * @param {string} message Сообщение
 */
function showError(message) {
    showToast(message, 'danger');
}

/**
 * Показать toast-уведомление
 * @param {string} message Сообщение
 * @param {string} type Тип (success, danger, warning, info)
 */
function showToast(message, type = 'info') {
    // Создаём контейнер для toast если его нет
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }

    const icons = {
        'success': 'bi-check-circle-fill',
        'danger': 'bi-x-circle-fill',
        'warning': 'bi-exclamation-triangle-fill',
        'info': 'bi-info-circle-fill'
    };

    const toastId = 'toast_' + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi ${icons[type] || icons.info} me-2"></i>
                    ${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', toastHtml);

    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, { delay: 5000 });
    toast.show();

    // Удаляем элемент после скрытия
    toastElement.addEventListener('hidden.bs.toast', function () {
        toastElement.remove();
    });
}
