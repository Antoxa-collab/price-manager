/**
 * System Logs - Современная страница диагностики
 */

const SystemLogs = {
    // Состояние
    logs: [],
    stats: {},
    filters: {
        period: '24h',
        level: '',
        category: '',
        search: ''
    },
    pagination: {
        limit: 50,
        offset: 0,
        total: 0
    },
    isLive: true,
    liveInterval: null,
    lastLogId: 0,

    /**
     * Инициализация
     */
    init() {
        console.log('[SystemLogs] Initializing...');
        this.bindEvents();
        this.loadStats();
        this.loadLogs();
        this.startLiveUpdates();
    },

    /**
     * Привязка событий
     */
    bindEvents() {
        // Refresh button
        document.getElementById('refreshBtn')?.addEventListener('click', () => {
            this.loadStats();
            this.loadLogs();
        });

        // Export button
        document.getElementById('exportBtn')?.addEventListener('click', () => this.exportLogs());

        // Cleanup button
        document.getElementById('cleanupBtn')?.addEventListener('click', () => this.cleanupLogs());

        // Period filter
        document.querySelectorAll('#periodFilter .pill-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('#periodFilter .pill-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.filters.period = e.target.dataset.period;
                this.pagination.offset = 0;
                this.loadStats();
                this.loadLogs();
            });
        });

        // Level filter
        document.querySelectorAll('#levelFilter .pill-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('#levelFilter .pill-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.filters.level = e.target.dataset.level;
                this.pagination.offset = 0;
                this.loadLogs();
            });
        });

        // Category filter
        document.querySelectorAll('#categoryFilter .pill-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('#categoryFilter .pill-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.filters.category = e.target.dataset.category;
                this.pagination.offset = 0;
                this.loadLogs();
            });
        });

        // Search
        let searchTimeout;
        document.getElementById('searchInput')?.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.filters.search = e.target.value;
                this.pagination.offset = 0;
                this.loadLogs();
            }, 300);
        });

        // Reset filters
        document.getElementById('resetFiltersBtn')?.addEventListener('click', () => this.resetFilters());

        // Live toggle
        document.getElementById('toggleLiveBtn')?.addEventListener('click', () => this.toggleLive());

        // Clear terminal
        document.getElementById('clearTerminalBtn')?.addEventListener('click', () => {
            document.getElementById('liveTerminal').innerHTML = '';
        });

        // Copy log
        document.getElementById('copyLogBtn')?.addEventListener('click', () => this.copyCurrentLog());
    },

    /**
     * Загрузка статистики
     */
    async loadStats() {
        try {
            const period = this.filters.period === 'all' ? '' : this.filters.period;
            const response = await App.fetch(`/api/system/logs/stats?period=${period}`);

            if (response.success) {
                this.stats = response.stats;
                this.renderStats();
            }
        } catch (error) {
            console.error('[SystemLogs] Failed to load stats:', error);
        }
    },

    /**
     * Отрисовка статистики
     */
    renderStats() {
        document.getElementById('statTotal').textContent = this.formatNumber(this.stats.total || 0);
        document.getElementById('statToday').textContent = this.formatNumber(this.stats.today || 0);
        document.getElementById('statSuccess').textContent = (this.stats.success_rate || 100) + '%';
        document.getElementById('statErrors').textContent = this.formatNumber(this.stats.errors || 0);
        document.getElementById('statWarnings').textContent = this.formatNumber(this.stats.warnings || 0);

        // Last activity
        if (this.stats.last_activity) {
            const date = new Date(this.stats.last_activity);
            document.getElementById('statLastActivity').textContent = this.formatTime(date);
        } else {
            document.getElementById('statLastActivity').textContent = '-';
        }
    },

    /**
     * Загрузка логов
     */
    async loadLogs() {
        try {
            const params = new URLSearchParams({
                limit: this.pagination.limit,
                offset: this.pagination.offset
            });

            if (this.filters.level) params.append('level', this.filters.level);
            if (this.filters.category) params.append('category', this.filters.category);
            if (this.filters.search) params.append('search', this.filters.search);

            // Period to date range
            if (this.filters.period && this.filters.period !== 'all') {
                const now = new Date();
                let from;
                switch (this.filters.period) {
                    case '1h':
                        from = new Date(now - 60 * 60 * 1000);
                        break;
                    case '24h':
                        from = new Date(now - 24 * 60 * 60 * 1000);
                        break;
                    case '7d':
                        from = new Date(now - 7 * 24 * 60 * 60 * 1000);
                        break;
                    case '30d':
                        from = new Date(now - 30 * 24 * 60 * 60 * 1000);
                        break;
                }
                if (from) {
                    params.append('from', from.toISOString().slice(0, 19).replace('T', ' '));
                }
            }

            const response = await App.fetch(`/api/system/logs?${params}`);

            if (response.success) {
                this.logs = response.logs || [];
                this.pagination.total = response.total || 0;
                this.renderLogs();
                this.renderPagination();
                this.updateLastUpdate();

                // Update last log ID for live updates
                if (this.logs.length > 0) {
                    this.lastLogId = Math.max(...this.logs.map(l => l.id));
                }
            }
        } catch (error) {
            console.error('[SystemLogs] Failed to load logs:', error);
            this.renderEmpty('Ошибка загрузки данных');
        }
    },

    /**
     * Отрисовка таблицы логов
     */
    renderLogs() {
        const tbody = document.getElementById('logsTableBody');
        if (!tbody) return;

        if (this.logs.length === 0) {
            this.renderEmpty('Нет записей в журнале');
            return;
        }

        tbody.innerHTML = this.logs.map(log => `
            <tr class="fade-in" data-log-id="${log.id}">
                <td>
                    <span class="text-muted">${this.formatDateTime(log.created_at)}</span>
                </td>
                <td>
                    <span class="level-badge ${log.level}">${log.level}</span>
                </td>
                <td>
                    <span class="category-badge">${log.category}</span>
                </td>
                <td>
                    <div class="text-truncate" style="max-width: 400px;" title="${this.escapeHtml(log.message)}">
                        ${this.escapeHtml(log.message)}
                    </div>
                </td>
                <td>
                    ${log.duration_ms ? `<span class="text-muted">${log.duration_ms}ms</span>` : '-'}
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="SystemLogs.showDetail(${log.id})">
                        <i class="bi bi-three-dots"></i>
                    </button>
                </td>
            </tr>
        `).join('');

        // Update count
        document.getElementById('logsCount').textContent = this.pagination.total;
        document.getElementById('showingFrom').textContent = this.pagination.offset + 1;
        document.getElementById('showingTo').textContent = Math.min(this.pagination.offset + this.logs.length, this.pagination.total);
        document.getElementById('showingTotal').textContent = this.pagination.total;
    },

    /**
     * Отрисовка пустого состояния
     */
    renderEmpty(message = 'Нет записей') {
        const tbody = document.getElementById('logsTableBody');
        if (!tbody) return;

        tbody.innerHTML = `
            <tr>
                <td colspan="6">
                    <div class="empty-state">
                        <i class="bi bi-inbox empty-state-icon"></i>
                        <div class="empty-state-title">${message}</div>
                        <div class="empty-state-text">Попробуйте изменить фильтры</div>
                    </div>
                </td>
            </tr>
        `;

        document.getElementById('logsCount').textContent = '0';
    },

    /**
     * Отрисовка пагинации
     */
    renderPagination() {
        const pagination = document.getElementById('pagination');
        if (!pagination) return;

        const totalPages = Math.ceil(this.pagination.total / this.pagination.limit);
        const currentPage = Math.floor(this.pagination.offset / this.pagination.limit) + 1;

        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        let html = '';

        // Previous
        html += `
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="SystemLogs.goToPage(${currentPage - 1}); return false;">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        `;

        // Pages
        const maxPages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxPages / 2));
        let endPage = Math.min(totalPages, startPage + maxPages - 1);

        if (endPage - startPage < maxPages - 1) {
            startPage = Math.max(1, endPage - maxPages + 1);
        }

        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="SystemLogs.goToPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            html += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="SystemLogs.goToPage(${i}); return false;">${i}</a>
                </li>
            `;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            html += `<li class="page-item"><a class="page-link" href="#" onclick="SystemLogs.goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }

        // Next
        html += `
            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="SystemLogs.goToPage(${currentPage + 1}); return false;">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        `;

        pagination.innerHTML = html;
    },

    /**
     * Переход на страницу
     */
    goToPage(page) {
        const totalPages = Math.ceil(this.pagination.total / this.pagination.limit);
        if (page < 1 || page > totalPages) return;

        this.pagination.offset = (page - 1) * this.pagination.limit;
        this.loadLogs();
    },

    /**
     * Показать детали лога
     */
    showDetail(logId) {
        const log = this.logs.find(l => l.id === logId);
        if (!log) return;

        document.getElementById('detailId').textContent = `#${log.id}`;
        document.getElementById('detailTime').textContent = this.formatDateTime(log.created_at, true);
        document.getElementById('detailLevel').innerHTML = `<span class="level-badge ${log.level}">${log.level}</span>`;
        document.getElementById('detailCategory').innerHTML = `<span class="category-badge">${log.category}</span>`;
        document.getElementById('detailMessage').textContent = log.message;
        document.getElementById('detailSource').textContent = log.source || '-';
        document.getElementById('detailIp').textContent = log.ip_address || '-';
        document.getElementById('detailDuration').textContent = log.duration_ms ? `${log.duration_ms}ms` : '-';

        // Context
        const contextBlock = document.getElementById('detailContextBlock');
        if (log.context && Object.keys(log.context).length > 0) {
            document.getElementById('detailContext').textContent = JSON.stringify(log.context, null, 2);
            contextBlock.style.display = 'block';
        } else {
            contextBlock.style.display = 'none';
        }

        // Store current log for copy
        this.currentLog = log;

        const modal = new bootstrap.Modal(document.getElementById('logDetailModal'));
        modal.show();
    },

    /**
     * Копировать текущий лог
     */
    copyCurrentLog() {
        if (!this.currentLog) return;

        const text = `[${this.currentLog.created_at}] [${this.currentLog.level}] [${this.currentLog.category}] ${this.currentLog.message}
${this.currentLog.context ? JSON.stringify(this.currentLog.context, null, 2) : ''}`;

        navigator.clipboard.writeText(text).then(() => {
            App.showToast('Скопировано в буфер обмена', 'success');
        });
    },

    /**
     * Live обновления
     */
    startLiveUpdates() {
        if (this.liveInterval) {
            clearInterval(this.liveInterval);
        }

        this.liveInterval = setInterval(() => {
            if (this.isLive) {
                this.fetchNewLogs();
            }
        }, 5000);
    },

    /**
     * Получить новые логи для live ленты
     */
    async fetchNewLogs() {
        try {
            const response = await App.fetch(`/api/system/logs?limit=10&offset=0`);

            if (response.success && response.logs) {
                const newLogs = response.logs.filter(l => l.id > this.lastLogId);

                if (newLogs.length > 0) {
                    this.lastLogId = Math.max(...newLogs.map(l => l.id));
                    newLogs.reverse().forEach(log => this.addToTerminal(log));

                    // Update stats
                    this.loadStats();
                }
            }
        } catch (error) {
            console.debug('[SystemLogs] Live update failed:', error);
        }
    },

    /**
     * Добавить запись в терминал
     */
    addToTerminal(log) {
        const terminal = document.getElementById('liveTerminal');
        if (!terminal) return;

        const time = new Date(log.created_at);
        const timeStr = time.toLocaleTimeString('ru-RU');

        const line = document.createElement('div');
        line.className = 'log-line fade-in';
        line.innerHTML = `
            <span class="log-time">${timeStr}</span>
            <span class="log-level ${log.level}">${log.level}</span>
            <span class="log-category">[${log.category}]</span>
            <span class="log-message">${this.escapeHtml(log.message)}</span>
        `;

        terminal.appendChild(line);

        // Keep only last 50 lines
        while (terminal.children.length > 50) {
            terminal.removeChild(terminal.firstChild);
        }

        // Auto scroll
        terminal.scrollTop = terminal.scrollHeight;
    },

    /**
     * Переключить live режим
     */
    toggleLive() {
        this.isLive = !this.isLive;

        const dot = document.getElementById('liveDot');
        const status = document.getElementById('liveStatus');
        const icon = document.getElementById('toggleLiveIcon');

        if (this.isLive) {
            dot.classList.remove('paused');
            status.textContent = 'LIVE';
            icon.className = 'bi bi-pause-fill';
        } else {
            dot.classList.add('paused');
            status.textContent = 'PAUSED';
            icon.className = 'bi bi-play-fill';
        }
    },

    /**
     * Сброс фильтров
     */
    resetFilters() {
        this.filters = { period: '24h', level: '', category: '', search: '' };
        this.pagination.offset = 0;

        // Reset UI
        document.querySelectorAll('#periodFilter .pill-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('#periodFilter [data-period="24h"]').classList.add('active');

        document.querySelectorAll('#levelFilter .pill-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('#levelFilter [data-level=""]').classList.add('active');

        document.querySelectorAll('#categoryFilter .pill-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('#categoryFilter [data-category=""]').classList.add('active');

        document.getElementById('searchInput').value = '';

        this.loadStats();
        this.loadLogs();
    },

    /**
     * Экспорт логов в CSV
     */
    async exportLogs() {
        try {
            const params = new URLSearchParams({ limit: 1000, offset: 0 });
            if (this.filters.level) params.append('level', this.filters.level);
            if (this.filters.category) params.append('category', this.filters.category);
            if (this.filters.search) params.append('search', this.filters.search);

            const response = await App.fetch(`/api/system/logs?${params}`);

            if (response.success && response.logs) {
                const csv = this.logsToCSV(response.logs);
                this.downloadCSV(csv, `system-logs-${new Date().toISOString().slice(0,10)}.csv`);
                App.showToast(`Экспортировано ${response.logs.length} записей`, 'success');
            }
        } catch (error) {
            App.showToast('Ошибка экспорта', 'danger');
        }
    },

    /**
     * Конвертация логов в CSV
     */
    logsToCSV(logs) {
        const headers = ['ID', 'Время', 'Уровень', 'Категория', 'Сообщение', 'Источник', 'IP', 'Длительность'];
        const rows = logs.map(log => [
            log.id,
            log.created_at,
            log.level,
            log.category,
            `"${(log.message || '').replace(/"/g, '""')}"`,
            log.source || '',
            log.ip_address || '',
            log.duration_ms || ''
        ]);

        return [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
    },

    /**
     * Скачать CSV файл
     */
    downloadCSV(csv, filename) {
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
    },

    /**
     * Очистка старых логов
     */
    async cleanupLogs() {
        const confirmed = await App.confirm('Удалить логи старше 30 дней?', 'Очистка логов');
        if (!confirmed) return;

        try {
            const response = await App.fetch('/api/system/logs/cleanup?days=30', { method: 'POST' });

            if (response.success) {
                App.showToast(`Удалено ${response.deleted} записей`, 'success');
                this.loadStats();
                this.loadLogs();
            } else {
                App.showToast('Ошибка очистки', 'danger');
            }
        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Обновить время последнего обновления
     */
    updateLastUpdate() {
        document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('ru-RU');
    },

    // ==================== Утилиты ====================

    formatNumber(num) {
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
        return num.toString();
    },

    formatDateTime(dateStr, full = false) {
        const date = new Date(dateStr);
        if (full) {
            return date.toLocaleString('ru-RU');
        }
        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();
        if (isToday) {
            return date.toLocaleTimeString('ru-RU');
        }
        return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    },

    formatTime(date) {
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'только что';
        if (diff < 3600000) return Math.floor(diff / 60000) + ' мин назад';
        if (diff < 86400000) return Math.floor(diff / 3600000) + ' ч назад';
        return date.toLocaleDateString('ru-RU');
    },

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => SystemLogs.init());
