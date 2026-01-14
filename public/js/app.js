/**
 * Price Manager - Общие функции приложения
 */

// CSRF токен для API запросов (получаем из мета-тега)
window.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

const App = {
    /**
     * Инициализация приложения
     */
    init() {
        this.initTooltips();
        this.initToastContainer();
        this.initAutoHideAlerts();
        this.initMobileMenu();
        this.initResponsiveTables();
        this.initCopyButtons();
        this.loadDeployInfo();

        // Автообновление индикатора деплоя каждые 10 секунд
        setInterval(() => this.loadDeployInfo(), 10000);

        // Обновлять при возврате на вкладку
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.loadDeployInfo();
            }
        });
    },

    /**
     * Инициализация кнопок копирования названий товаров (делегирование событий)
     */
    initCopyButtons() {
        const self = this;
        document.addEventListener('click', function(e) {
            const copyBtn = e.target.closest('.btn-copy-name');
            if (!copyBtn) return;

            e.stopPropagation();
            e.preventDefault();

            const wrapper = copyBtn.closest('.product-name-wrapper');
            const textElement = wrapper ? wrapper.querySelector('.product-name-text') : null;
            const text = textElement ? textElement.textContent.trim() : '';

            if (!text) {
                console.error('Текст для копирования не найден');
                return;
            }

            // Копируем в буфер
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    self.showCopySuccess(copyBtn);
                    const shortText = text.length > 40 ? text.substring(0, 40) + '...' : text;
                    self.showToast('Скопировано: ' + shortText, 'success', 2000);
                }).catch(err => {
                    console.error('Ошибка копирования:', err);
                    self.fallbackCopyWithButton(text, copyBtn);
                });
            } else {
                self.fallbackCopyWithButton(text, copyBtn);
            }
        });
    },

    /**
     * Визуальная обратная связь при успешном копировании
     */
    showCopySuccess(button) {
        button.classList.add('copied');
        const icon = button.querySelector('i');
        if (icon) icon.className = 'bi bi-clipboard-check';

        setTimeout(() => {
            button.classList.remove('copied');
            if (icon) icon.className = 'bi bi-clipboard';
        }, 2000);
    },

    /**
     * Fallback копирование с визуальной обратной связью
     */
    fallbackCopyWithButton(text, button) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.cssText = 'position:fixed;left:-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            this.showCopySuccess(button);
            this.showToast('Скопировано!', 'success', 2000);
        } catch (err) {
            console.error('Fallback копирование не удалось:', err);
            this.showToast('Не удалось скопировать', 'danger');
        }

        document.body.removeChild(textarea);
    },

    /**
     * Загрузка информации о деплое (время модификации index.php)
     */
    async loadDeployInfo() {
        try {
            // Добавляем timestamp чтобы избежать кэширования браузером
            const response = await fetch('/api/deploy-info?_=' + Date.now());
            const data = await response.json();

            if (data.success) {
                const deployEl = document.getElementById('deployTime');
                const deployContainer = document.getElementById('deployInfo');

                if (deployEl) {
                    const newTime = data.deploy_short;
                    const oldTime = deployEl.textContent;

                    deployEl.textContent = newTime;
                    deployContainer.title = `Последний деплой: ${data.deploy_formatted}\nФайл: ${data.file}`;

                    // Анимация если время изменилось
                    if (oldTime !== '—' && oldTime !== newTime && deployContainer) {
                        deployContainer.classList.add('updated');
                        setTimeout(() => deployContainer.classList.remove('updated'), 500);
                    }
                }
            }
        } catch (error) {
            // Тихо игнорируем ошибки - индикатор просто останется в состоянии "—"
            console.debug('[DeployInfo] Ошибка:', error);
        }
    },

    /**
     * Инициализация Bootstrap tooltips
     */
    initTooltips() {
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(el => new bootstrap.Tooltip(el));
    },

    /**
     * Создание контейнера для toast-уведомлений
     */
    initToastContainer() {
        if (!document.querySelector('.toast-container')) {
            const container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
    },

    /**
     * Автоматическое скрытие алертов
     */
    initAutoHideAlerts() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            }, 5000);
        });
    },

    /**
     * Инициализация мобильного меню
     */
    initMobileMenu() {
        const sidebar = document.querySelector('.sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (!sidebar || !sidebarToggle || !sidebarOverlay) {
            return;
        }

        // Toggle sidebar on hamburger click
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleSidebar();
        });

        // Close sidebar on overlay click
        sidebarOverlay.addEventListener('click', () => {
            this.closeSidebar();
        });

        // Close sidebar when clicking a link (on mobile)
        sidebar.querySelectorAll('a.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    this.closeSidebar();
                }
            });
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                this.closeSidebar();
            }
        });

        // Handle resize - close sidebar when switching to desktop
        window.addEventListener('resize', this.debounce(() => {
            if (window.innerWidth >= 768) {
                this.closeSidebar();
            }
        }, 250));
    },

    /**
     * Toggle sidebar visibility
     */
    toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (sidebar && sidebarOverlay) {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
        }
    },

    /**
     * Close sidebar
     */
    closeSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        if (sidebar && sidebarOverlay) {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        }
    },

    /**
     * Initialize responsive tables for mobile
     * Adds data-label attributes for card view on mobile
     */
    initResponsiveTables() {
        const tables = document.querySelectorAll('table.table');
        tables.forEach(table => {
            const headers = table.querySelectorAll('thead th');
            const headerLabels = Array.from(headers).map(th => th.textContent.trim());

            table.querySelectorAll('tbody tr').forEach(row => {
                row.querySelectorAll('td').forEach((cell, index) => {
                    if (headerLabels[index]) {
                        cell.setAttribute('data-label', headerLabels[index]);
                    }
                });
            });

            // Add mobile-cards class for styling
            table.classList.add('mobile-cards');
        });
    },

    /**
     * Check if device is mobile
     */
    isMobile() {
        return window.innerWidth < 768;
    },

    /**
     * Показать toast-уведомление
     * @param {string} message - Текст сообщения
     * @param {string} type - Тип (success, danger, warning, info)
     * @param {number} duration - Длительность показа в мс
     */
    showToast(message, type = 'info', duration = 4000) {
        let container = document.querySelector('.toast-container');

        // Создаём контейнер если его нет
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(container);
        }
        const id = 'toast-' + Date.now();

        const icons = {
            success: 'bi-check-circle-fill',
            danger: 'bi-exclamation-triangle-fill',
            warning: 'bi-exclamation-circle-fill',
            info: 'bi-info-circle-fill'
        };

        const html = `
            <div id="${id}" class="toast align-items-center border-${type}" role="alert">
                <div class="d-flex">
                    <div class="toast-body d-flex align-items-center gap-2">
                        <i class="bi ${icons[type] || icons.info} text-${type}"></i>
                        <span>${this.escapeHtml(message)}</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', html);

        const toastEl = document.getElementById(id);
        const toast = new bootstrap.Toast(toastEl, {
            autohide: true,
            delay: duration
        });

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });

        toast.show();
    },

    /**
     * Показать модальное окно загрузки
     * @param {string} message - Текст сообщения
     */
    showLoading(message = 'Загрузка...') {
        const modal = document.getElementById('loadingModal');
        const messageEl = document.getElementById('loadingMessage');

        if (modal && messageEl) {
            messageEl.textContent = message;
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    },

    /**
     * Скрыть модальное окно загрузки
     */
    hideLoading() {
        const modal = document.getElementById('loadingModal');
        if (modal) {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
            }
        }
    },

    /**
     * Показать диалог подтверждения
     * @param {string} message - Текст сообщения
     * @param {string} title - Заголовок
     * @returns {Promise<boolean>}
     */
    confirm(message, title = 'Подтверждение') {
        return new Promise((resolve) => {
            const modal = document.getElementById('confirmModal');
            const titleEl = document.getElementById('confirmTitle');
            const messageEl = document.getElementById('confirmMessage');
            const confirmBtn = document.getElementById('confirmButton');

            if (!modal || !titleEl || !messageEl || !confirmBtn) {
                resolve(window.confirm(message));
                return;
            }

            titleEl.textContent = title;
            messageEl.textContent = message;

            const bsModal = new bootstrap.Modal(modal);

            const handleConfirm = () => {
                bsModal.hide();
                resolve(true);
            };

            const handleCancel = () => {
                resolve(false);
            };

            confirmBtn.onclick = handleConfirm;
            modal.addEventListener('hidden.bs.modal', handleCancel, { once: true });

            bsModal.show();
        });
    },

    /**
     * AJAX запрос
     * @param {string} url - URL запроса
     * @param {Object} options - Опции запроса
     * @returns {Promise}
     */
    async fetch(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken
            }
        };

        // Добавляем CSRF токен и обрабатываем body для POST, PUT, PATCH, DELETE запросов
        const methodsWithBody = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (methodsWithBody.includes(options.method) && window.csrfToken) {
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', window.csrfToken);
            } else if (options.body instanceof URLSearchParams) {
                // URLSearchParams - form-encoded данные
                options.body.append('csrf_token', window.csrfToken);
                defaultOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            } else {
                defaultOptions.headers['Content-Type'] = 'application/json';
                if (typeof options.body === 'object') {
                    options.body = JSON.stringify({
                        ...options.body,
                        csrf_token: window.csrfToken
                    });
                }
            }
        }

        const mergedOptions = { ...defaultOptions, ...options };
        mergedOptions.headers = { ...defaultOptions.headers, ...options.headers };

        try {
            const response = await fetch(url, mergedOptions);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Ошибка запроса');
            }

            return data;
        } catch (error) {
            console.error('Fetch error:', error);
            throw error;
        }
    },

    /**
     * Форматирование числа как цены
     * @param {number} price - Цена
     * @param {string} currency - Валюта
     * @returns {string}
     */
    formatPrice(price, currency = 'RUB') {
        const formatted = new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(price);

        const symbols = { RUB: '₽', USD: '$', EUR: '€' };
        return `${formatted} ${symbols[currency] || currency}`;
    },

    /**
     * Форматирование даты
     * @param {string} dateStr - Дата в формате ISO
     * @returns {string}
     */
    formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },

    /**
     * Экранирование HTML
     * @param {string} text - Текст
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Debounce функция
     * @param {Function} func - Функция
     * @param {number} wait - Задержка в мс
     * @returns {Function}
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Получение данных формы как объекта
     * @param {HTMLFormElement} form - Форма
     * @returns {Object}
     */
    getFormData(form) {
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }
        return data;
    },

    /**
     * Валидация формы
     * @param {HTMLFormElement} form - Форма
     * @returns {boolean}
     */
    validateForm(form) {
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return false;
        }
        return true;
    },

    /**
     * Копирование текста в буфер обмена
     * @param {string} text - Текст
     * @returns {Promise}
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showToast('Скопировано в буфер обмена', 'success');
        } catch (err) {
            console.error('Copy failed:', err);
            this.showToast('Не удалось скопировать', 'danger');
        }
    },

    /**
     * Копирование названия товара (для кнопки в карточке)
     * @param {Event} event - Событие клика
     * @param {HTMLElement} button - Кнопка копирования
     */
    copyProductName(event, button) {
        // Останавливаем всплытие чтобы не выбрать товар
        event.stopPropagation();
        event.preventDefault();

        // Находим текст названия
        const nameText = button.closest('.product-name-wrapper')?.querySelector('.product-name-text');
        const text = nameText ? nameText.textContent.trim() : '';

        if (!text) return;

        // Копируем в буфер
        navigator.clipboard.writeText(text).then(() => {
            // Показываем что скопировано
            button.classList.add('copied');
            const icon = button.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-clipboard-check';
            }

            // Возвращаем исходный вид через 2 секунды
            setTimeout(() => {
                button.classList.remove('copied');
                if (icon) {
                    icon.className = 'bi bi-clipboard';
                }
            }, 2000);

            // Показываем короткое уведомление
            const shortText = text.length > 40 ? text.substring(0, 40) + '...' : text;
            this.showToast(`Скопировано: ${shortText}`, 'success', 2000);
        }).catch(err => {
            console.error('Ошибка копирования:', err);
            // Fallback для старых браузеров
            this.fallbackCopyText(text);
        });
    },

    /**
     * Fallback копирование для старых браузеров
     * @param {string} text - Текст для копирования
     */
    fallbackCopyText(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();

        try {
            document.execCommand('copy');
            this.showToast('Скопировано!', 'success', 2000);
        } catch (err) {
            console.error('Fallback копирование не удалось:', err);
            this.showToast('Не удалось скопировать', 'danger');
        }

        document.body.removeChild(textArea);
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => App.init());
