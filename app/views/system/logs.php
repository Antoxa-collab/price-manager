<?php
/**
 * Современная страница диагностики и системных логов
 * Стиль: Stripe/Linear/Vercel Dashboard
 */
$pageTitle = 'Диагностика системы';
$pageScript = 'system-logs';
include VIEWS_PATH . '/layout/header.php';
?>

<style>
/* ==================== Glassmorphism Cards ==================== */
.stat-card {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 24px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--card-accent, linear-gradient(90deg, #667eea, #764ba2));
    opacity: 0;
    transition: opacity 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
    border-color: rgba(255, 255, 255, 0.15);
}

.stat-card:hover::before {
    opacity: 1;
}

.stat-card .stat-icon {
    font-size: 2.5rem;
    opacity: 0.15;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
}

.stat-card .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 8px;
}

.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 4px;
}

.stat-card .stat-change {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.4);
}

/* Card accent colors */
.stat-card.accent-blue { --card-accent: linear-gradient(90deg, #3b82f6, #60a5fa); }
.stat-card.accent-cyan { --card-accent: linear-gradient(90deg, #06b6d4, #22d3ee); }
.stat-card.accent-green { --card-accent: linear-gradient(90deg, #22c55e, #4ade80); }
.stat-card.accent-red { --card-accent: linear-gradient(90deg, #ef4444, #f87171); }
.stat-card.accent-yellow { --card-accent: linear-gradient(90deg, #eab308, #facc15); }
.stat-card.accent-purple { --card-accent: linear-gradient(90deg, #8b5cf6, #a78bfa); }

.stat-value.gradient-blue {
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-value.gradient-cyan {
    background: linear-gradient(135deg, #06b6d4, #22d3ee);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-value.gradient-green {
    background: linear-gradient(135deg, #22c55e, #4ade80);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-value.gradient-red {
    background: linear-gradient(135deg, #ef4444, #f87171);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-value.gradient-yellow {
    background: linear-gradient(135deg, #eab308, #facc15);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ==================== Pill Buttons ==================== */
.pill-group {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.pill-btn {
    padding: 6px 14px;
    border-radius: 100px;
    font-size: 0.8rem;
    font-weight: 500;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.03);
    color: rgba(255, 255, 255, 0.6);
    transition: all 0.2s ease;
    cursor: pointer;
}

.pill-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.9);
    border-color: rgba(255, 255, 255, 0.2);
}

.pill-btn.active {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.3);
}

.pill-btn.level-error { --level-color: #ef4444; }
.pill-btn.level-warn { --level-color: #eab308; }
.pill-btn.level-info { --level-color: #3b82f6; }
.pill-btn.level-debug { --level-color: #6b7280; }
.pill-btn.level-ok { --level-color: #22c55e; }

.pill-btn.active.level-error { background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.5); color: #fca5a5; }
.pill-btn.active.level-warn { background: rgba(234, 179, 8, 0.2); border-color: rgba(234, 179, 8, 0.5); color: #fde047; }
.pill-btn.active.level-info { background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.5); color: #93c5fd; }
.pill-btn.active.level-debug { background: rgba(107, 114, 128, 0.2); border-color: rgba(107, 114, 128, 0.5); color: #d1d5db; }
.pill-btn.active.level-ok { background: rgba(34, 197, 94, 0.2); border-color: rgba(34, 197, 94, 0.5); color: #86efac; }

/* ==================== Live Terminal ==================== */
.live-terminal {
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    overflow: hidden;
    font-family: 'JetBrains Mono', 'Fira Code', 'Monaco', monospace;
}

.live-terminal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.03);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.live-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.live-dot {
    width: 8px;
    height: 8px;
    background: #ef4444;
    border-radius: 50%;
    animation: pulse 1.5s infinite;
}

.live-dot.paused {
    background: #6b7280;
    animation: none;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

.live-terminal-body {
    padding: 12px 16px;
    max-height: 300px;
    overflow-y: auto;
    font-size: 0.8rem;
    line-height: 1.6;
}

.log-line {
    display: flex;
    gap: 12px;
    padding: 4px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
    transition: background 0.2s ease;
}

.log-line:hover {
    background: rgba(255, 255, 255, 0.03);
}

.log-time {
    color: rgba(255, 255, 255, 0.4);
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}

.log-level {
    flex-shrink: 0;
    font-weight: 600;
    width: 50px;
    text-align: center;
}

.log-level.ERROR { color: #f87171; }
.log-level.WARN { color: #fde047; }
.log-level.INFO { color: #60a5fa; }
.log-level.DEBUG { color: #9ca3af; }
.log-level.OK { color: #4ade80; }

.log-category {
    color: rgba(255, 255, 255, 0.5);
    flex-shrink: 0;
    width: 80px;
}

.log-message {
    color: rgba(255, 255, 255, 0.85);
    flex: 1;
    word-break: break-word;
}

/* ==================== Modern Table ==================== */
.modern-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

.modern-table thead th {
    background: rgba(255, 255, 255, 0.03);
    padding: 14px 16px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: rgba(255, 255, 255, 0.5);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    position: sticky;
    top: 0;
    z-index: 10;
}

.modern-table tbody tr {
    transition: background 0.15s ease;
}

.modern-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.03);
}

.modern-table td {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.85rem;
    vertical-align: middle;
}

/* Level badges */
.level-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.level-badge.ERROR {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
}

.level-badge.WARN {
    background: rgba(234, 179, 8, 0.15);
    color: #fde047;
}

.level-badge.INFO {
    background: rgba(59, 130, 246, 0.15);
    color: #93c5fd;
}

.level-badge.DEBUG {
    background: rgba(107, 114, 128, 0.15);
    color: #d1d5db;
}

.level-badge.OK {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
}

/* Category badges */
.category-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.7);
}

/* ==================== Search Input ==================== */
.search-input-wrapper {
    position: relative;
}

.search-input-wrapper .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.3);
    pointer-events: none;
    transition: color 0.2s ease;
}

.search-input-wrapper input {
    padding-left: 42px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    color: #fff;
    transition: all 0.2s ease;
}

.search-input-wrapper input:focus {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(99, 102, 241, 0.5);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.search-input-wrapper input:focus + .search-icon {
    color: rgba(99, 102, 241, 0.8);
}

/* ==================== Cards ==================== */
.glass-card {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    overflow: hidden;
}

.glass-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: rgba(255, 255, 255, 0.02);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.glass-card-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    display: flex;
    align-items: center;
    gap: 10px;
}

.glass-card-body {
    padding: 0;
}

/* ==================== Empty State ==================== */
.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: rgba(255, 255, 255, 0.4);
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
}

.empty-state-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 8px;
}

.empty-state-text {
    font-size: 0.9rem;
}

/* ==================== Animations ==================== */
.fade-in {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ==================== Scrollbar ==================== */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.15);
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.25);
}
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-activity text-primary"></i>
            Диагностика системы
        </h4>
        <p class="text-muted mb-0 small">Мониторинг операций калькулятора и API</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="refreshBtn">
            <i class="bi bi-arrow-repeat me-1"></i> Обновить
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="exportBtn">
            <i class="bi bi-download me-1"></i> Экспорт
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="cleanupBtn" title="Очистить логи старше 30 дней">
            <i class="bi bi-trash me-1"></i> Очистить
        </button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-blue">
            <div class="stat-label">Всего операций</div>
            <div class="stat-value gradient-blue" id="statTotal">-</div>
            <div class="stat-change">за всё время</div>
            <i class="bi bi-bar-chart-fill stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-cyan">
            <div class="stat-label">За 24 часа</div>
            <div class="stat-value gradient-cyan" id="statToday">-</div>
            <div class="stat-change">операций</div>
            <i class="bi bi-clock-history stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-green">
            <div class="stat-label">Успешность</div>
            <div class="stat-value gradient-green" id="statSuccess">-</div>
            <div class="stat-change">процент</div>
            <i class="bi bi-check-circle-fill stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-red">
            <div class="stat-label">Ошибки</div>
            <div class="stat-value gradient-red" id="statErrors">-</div>
            <div class="stat-change">за период</div>
            <i class="bi bi-x-circle-fill stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-yellow">
            <div class="stat-label">Предупреждения</div>
            <div class="stat-value gradient-yellow" id="statWarnings">-</div>
            <div class="stat-change">за период</div>
            <i class="bi bi-exclamation-triangle-fill stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card accent-purple">
            <div class="stat-label">Последняя</div>
            <div class="stat-value" id="statLastActivity" style="font-size: 1rem; color: rgba(255,255,255,0.7);">-</div>
            <div class="stat-change">активность</div>
            <i class="bi bi-lightning-fill stat-icon"></i>
        </div>
    </div>
</div>

<!-- Live Terminal -->
<div class="live-terminal mb-4">
    <div class="live-terminal-header">
        <div class="live-indicator">
            <span class="live-dot" id="liveDot"></span>
            <span id="liveStatus">LIVE</span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleLiveBtn">
                <i class="bi bi-pause-fill" id="toggleLiveIcon"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearTerminalBtn">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    <div class="live-terminal-body custom-scrollbar" id="liveTerminal">
        <div class="log-line">
            <span class="log-time">--:--:--</span>
            <span class="log-level INFO">INFO</span>
            <span class="log-category">[SYS]</span>
            <span class="log-message">Ожидание событий...</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="glass-card mb-4">
    <div class="glass-card-header">
        <div class="glass-card-title">
            <i class="bi bi-funnel"></i>
            Фильтры
        </div>
        <button type="button" class="btn btn-sm btn-link text-muted" id="resetFiltersBtn">
            Сбросить
        </button>
    </div>
    <div class="p-3">
        <div class="row g-3 align-items-end">
            <!-- Period -->
            <div class="col-auto">
                <label class="form-label small text-muted mb-2">Период</label>
                <div class="pill-group" id="periodFilter">
                    <button type="button" class="pill-btn active" data-period="24h">24 часа</button>
                    <button type="button" class="pill-btn" data-period="7d">7 дней</button>
                    <button type="button" class="pill-btn" data-period="30d">30 дней</button>
                    <button type="button" class="pill-btn" data-period="all">Всё время</button>
                </div>
            </div>

            <!-- Level -->
            <div class="col-auto">
                <label class="form-label small text-muted mb-2">Уровень</label>
                <div class="pill-group" id="levelFilter">
                    <button type="button" class="pill-btn active" data-level="">Все</button>
                    <button type="button" class="pill-btn level-error" data-level="ERROR">ERROR</button>
                    <button type="button" class="pill-btn level-warn" data-level="WARN">WARN</button>
                    <button type="button" class="pill-btn level-info" data-level="INFO">INFO</button>
                    <button type="button" class="pill-btn level-ok" data-level="OK">OK</button>
                    <button type="button" class="pill-btn level-debug" data-level="DEBUG">DEBUG</button>
                </div>
            </div>

            <!-- Category -->
            <div class="col-auto">
                <label class="form-label small text-muted mb-2">Категория</label>
                <div class="pill-group" id="categoryFilter">
                    <button type="button" class="pill-btn active" data-category="">Все</button>
                    <button type="button" class="pill-btn" data-category="CALC">Калькулятор</button>
                    <button type="button" class="pill-btn" data-category="OZON_API">Ozon API</button>
                    <button type="button" class="pill-btn" data-category="WB_API">WB API</button>
                    <button type="button" class="pill-btn" data-category="DB">БД</button>
                    <button type="button" class="pill-btn" data-category="AUTH">Авторизация</button>
                </div>
            </div>

            <!-- Search -->
            <div class="col-md-3 ms-auto">
                <label class="form-label small text-muted mb-2">Поиск</label>
                <div class="search-input-wrapper">
                    <input type="text" class="form-control" id="searchInput" placeholder="Поиск по сообщениям...">
                    <i class="bi bi-search search-icon"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Logs Table -->
<div class="glass-card">
    <div class="glass-card-header">
        <div class="glass-card-title">
            <i class="bi bi-list-ul"></i>
            Журнал операций
            <span class="badge bg-secondary rounded-pill" id="logsCount">0</span>
        </div>
        <div class="text-muted small">
            Обновлено: <span id="lastUpdate">-</span>
        </div>
    </div>
    <div class="glass-card-body">
        <div class="table-responsive custom-scrollbar" style="max-height: 600px;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Время</th>
                        <th style="width: 90px;">Уровень</th>
                        <th style="width: 100px;">Категория</th>
                        <th>Сообщение</th>
                        <th style="width: 80px;">Длительность</th>
                        <th style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-hourglass-split empty-state-icon"></i>
                                <div class="empty-state-title">Загрузка...</div>
                                <div class="empty-state-text">Получение данных журнала</div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-between align-items-center mt-3">
    <div class="text-muted small">
        Показано <span id="showingFrom">0</span>-<span id="showingTo">0</span> из <span id="showingTotal">0</span>
    </div>
    <nav>
        <ul class="pagination pagination-sm mb-0" id="pagination">
        </ul>
    </nav>
</div>

<!-- Log Detail Modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle"></i>
                    Детали записи
                    <span class="badge bg-secondary" id="detailId"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">Время</div>
                        <div id="detailTime">-</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted mb-1">Уровень</div>
                        <div id="detailLevel">-</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted mb-1">Категория</div>
                        <div id="detailCategory">-</div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="small text-muted mb-1">Сообщение</div>
                    <div class="p-3 rounded" style="background: rgba(255,255,255,0.05);" id="detailMessage">-</div>
                </div>

                <div class="mb-3" id="detailContextBlock" style="display: none;">
                    <div class="small text-muted mb-1">Контекст</div>
                    <pre class="p-3 rounded mb-0 small" style="background: rgba(0,0,0,0.3); max-height: 200px; overflow: auto;" id="detailContext"></pre>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">Источник</div>
                        <code class="small" id="detailSource">-</code>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">IP адрес</div>
                        <code class="small" id="detailIp">-</code>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">Длительность</div>
                        <span id="detailDuration">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="copyLogBtn">
                    <i class="bi bi-clipboard me-1"></i> Копировать
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script src="/js/system-logs.js"></script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
