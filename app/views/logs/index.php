<?php
/**
 * Страница просмотра логов ошибок
 * Только для администраторов
 */
$pageTitle = 'Логи ошибок';
$pageScript = 'logs';
include VIEWS_PATH . '/layout/header.php';

// Получаем статистику
$stats = ErrorLogger::getStatistics();
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-bug me-2 text-danger"></i>
                Логи ошибок
            </h4>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary" id="refreshLogsBtn">
                    <i class="bi bi-arrow-repeat me-1"></i> Обновить
                </button>
                <button type="button" class="btn btn-outline-warning" id="cleanupLogsBtn">
                    <i class="bi bi-trash me-1"></i> Очистить старые
                </button>
                <button type="button" class="btn btn-outline-danger" id="clearAllLogsBtn">
                    <i class="bi bi-trash-fill me-1"></i> Очистить все
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-dark border-secondary">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Всего записей</div>
                        <h4 class="mb-0" id="statTotal"><?= $stats['total'] ?></h4>
                    </div>
                    <i class="bi bi-journal-text display-6 text-secondary"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark border-secondary">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">За последние 24 часа</div>
                        <h4 class="mb-0" id="statLast24h"><?= $stats['last_24h'] ?></h4>
                    </div>
                    <i class="bi bi-clock-history display-6 text-info"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark border-secondary">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">За последний час</div>
                        <h4 class="mb-0 <?= $stats['last_hour'] > 10 ? 'text-warning' : '' ?>" id="statLastHour">
                            <?= $stats['last_hour'] ?>
                        </h4>
                    </div>
                    <i class="bi bi-exclamation-triangle display-6 <?= $stats['last_hour'] > 10 ? 'text-warning' : 'text-secondary' ?>"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark border-danger">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Ошибок (ERROR)</div>
                        <h4 class="mb-0 text-danger" id="statErrors">
                            <?php
                            $errorCount = 0;
                            foreach ($stats['by_level'] as $level) {
                                if ($level['level'] === 'ERROR') {
                                    $errorCount = $level['count'];
                                    break;
                                }
                            }
                            echo $errorCount;
                            ?>
                        </h4>
                    </div>
                    <i class="bi bi-bug-fill display-6 text-danger"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-body py-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Уровень</label>
                <select class="form-select form-select-sm" id="filterLevel">
                    <option value="">Все</option>
                    <option value="ERROR">ERROR</option>
                    <option value="API_ERROR">API_ERROR</option>
                    <option value="DB_ERROR">DB_ERROR</option>
                    <option value="WARNING">WARNING</option>
                    <option value="INFO">INFO</option>
                    <option value="API_OK">API_OK</option>
                    <option value="DEBUG">DEBUG</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Поиск</label>
                <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Поиск по сообщению или URL...">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Записей</label>
                <select class="form-select form-select-sm" id="filterLimit">
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                </select>
            </div>
            <div class="col-md-4 text-end">
                <div class="btn-group btn-group-sm flex-wrap">
                    <button type="button" class="btn btn-outline-light level-filter active" data-level="">Все</button>
                    <button type="button" class="btn btn-outline-danger level-filter" data-level="ERROR">
                        <i class="bi bi-x-circle-fill"></i> ERROR
                    </button>
                    <button type="button" class="btn btn-outline-danger level-filter" data-level="API_ERROR">
                        <i class="bi bi-cloud-slash"></i> API
                    </button>
                    <button type="button" class="btn btn-outline-danger level-filter" data-level="DB_ERROR">
                        <i class="bi bi-database-x"></i> DB
                    </button>
                    <button type="button" class="btn btn-outline-warning level-filter" data-level="WARNING">
                        <i class="bi bi-exclamation-triangle-fill"></i> WARN
                    </button>
                    <button type="button" class="btn btn-outline-success level-filter" data-level="API_OK">
                        <i class="bi bi-cloud-check"></i> OK
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Таблица логов -->
<div class="card bg-dark border-secondary">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-list-ul me-2"></i>
            Записи логов
            <span class="badge bg-secondary ms-2" id="logsCount">0</span>
        </div>
        <div class="text-muted small" id="lastUpdate">
            <i class="bi bi-clock me-1"></i>
            Обновлено: <span id="lastUpdateTime">-</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover mb-0" id="logsTable">
                <thead>
                    <tr>
                        <th style="width: 150px;">Время</th>
                        <th style="width: 80px;">Уровень</th>
                        <th>Сообщение</th>
                        <th style="width: 200px;">URL</th>
                        <th style="width: 100px;">Пользователь</th>
                        <th style="width: 60px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-hourglass-split display-4"></i>
                            <p class="mt-3">Загрузка...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модальное окно деталей ошибки -->
<div class="modal fade" id="logDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i>
                    Детали ошибки <span id="detailLogId" class="text-muted"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Основная информация -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <span class="text-muted">Время:</span>
                            <span id="detailTime" class="ms-2">-</span>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted">Уровень:</span>
                            <span id="detailLevel" class="ms-2">-</span>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted">URL:</span>
                            <code id="detailUrl" class="ms-2">-</code>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <span class="text-muted">Метод:</span>
                            <span id="detailMethod" class="ms-2">-</span>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted">IP:</span>
                            <code id="detailIp" class="ms-2">-</code>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted">Пользователь:</span>
                            <span id="detailUser" class="ms-2">-</span>
                        </div>
                    </div>
                </div>

                <hr class="border-secondary">

                <!-- Сообщение -->
                <div class="mb-3">
                    <h6 class="text-muted">Сообщение:</h6>
                    <div class="alert alert-danger mb-0" id="detailMessage">-</div>
                </div>

                <!-- Stack Trace -->
                <div class="mb-3" id="detailTraceBlock" style="display: none;">
                    <h6 class="text-muted">Stack Trace:</h6>
                    <pre class="bg-body-tertiary p-3 rounded small" id="detailTrace" style="max-height: 300px; overflow: auto;">-</pre>
                </div>

                <!-- Контекст -->
                <div class="mb-3" id="detailContextBlock" style="display: none;">
                    <h6 class="text-muted">Контекст:</h6>
                    <pre class="bg-body-tertiary p-3 rounded small" id="detailContext" style="max-height: 200px; overflow: auto;">-</pre>
                </div>

                <!-- User Agent -->
                <div class="mb-0" id="detailUserAgentBlock" style="display: none;">
                    <h6 class="text-muted">User-Agent:</h6>
                    <code id="detailUserAgent" class="small">-</code>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary" id="copyLogBtn">
                    <i class="bi bi-clipboard me-1"></i> Копировать всё
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
