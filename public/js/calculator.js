/**
 * Price Manager - Логика калькулятора цен
 */

const Calculator = {
    // Текущие результаты расчёта
    currentResults: null,

    /**
     * Инициализация калькулятора
     */
    init() {
        this.form = document.getElementById('calculatorForm');
        this.resultsTable = document.getElementById('resultsTable');
        this.actionButtons = document.getElementById('actionButtons');
        this.emptyMessage = document.getElementById('emptyMessage');

        if (this.form) {
            this.bindEvents();
        }
    },

    /**
     * Привязка событий
     */
    bindEvents() {
        // Отправка формы
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.calculate();
        });

        // Автоматический расчёт при изменении полей
        const autoCalcFields = ['basePrice', 'markupRetail', 'markupMedium', 'markupWholesale'];
        autoCalcFields.forEach(id => {
            const field = document.getElementById(id);
            if (field) {
                field.addEventListener('input', App.debounce(() => {
                    if (this.validateInputs()) {
                        this.calculate();
                    }
                }, 500));
            }
        });

        // Кнопки действий
        document.getElementById('saveProductBtn')?.addEventListener('click', () => this.saveProduct());
        document.getElementById('uploadWbBtn')?.addEventListener('click', () => this.uploadToWB());
        document.getElementById('uploadOzonBtn')?.addEventListener('click', () => this.uploadToOzon());
        document.getElementById('zeroWbBtn')?.addEventListener('click', () => this.zeroStockWB());
        document.getElementById('zeroOzonBtn')?.addEventListener('click', () => this.zeroStockOzon());
    },

    /**
     * Валидация полей ввода
     * @returns {boolean}
     */
    validateInputs() {
        const basePrice = parseFloat(document.getElementById('basePrice').value);
        return basePrice > 0;
    },

    /**
     * Расчёт цен
     */
    async calculate() {
        if (!this.validateInputs()) {
            return;
        }

        const formData = new FormData(this.form);

        try {
            const response = await fetch('/api/calculate', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                this.currentResults = result.data;
                this.displayResults(result.data);
            } else {
                App.showToast(result.message || 'Ошибка расчёта', 'danger');
            }
        } catch (error) {
            console.error('Calculate error:', error);
            App.showToast('Ошибка соединения с сервером', 'danger');
        }
    },

    /**
     * Отображение результатов
     * @param {Object} data - Данные расчёта
     */
    displayResults(data) {
        // Скрываем пустое сообщение
        if (this.emptyMessage) {
            this.emptyMessage.style.display = 'none';
        }

        // Показываем кнопки действий
        if (this.actionButtons) {
            this.actionButtons.style.display = 'flex';
        }

        // Заполняем таблицу
        this.updateRow('retail', data.retail);
        this.updateRow('medium', data.medium);
        this.updateRow('wholesale', data.wholesale);

        // Итоговые цены для маркетплейсов
        document.getElementById('wbPrice').textContent = App.formatPrice(data.wb_price);
        document.getElementById('ozonPrice').textContent = App.formatPrice(data.ozon_price);

        // Анимация
        this.resultsTable.classList.add('fade-in');
        setTimeout(() => this.resultsTable.classList.remove('fade-in'), 300);
    },

    /**
     * Обновление строки таблицы
     * @param {string} type - Тип (retail, medium, wholesale)
     * @param {Object} data - Данные строки
     */
    updateRow(type, data) {
        document.getElementById(`${type}Markup`).textContent = `${data.markup_percent}%`;
        document.getElementById(`${type}Raw`).textContent = App.formatPrice(data.price_raw);
        document.getElementById(`${type}Rounded`).textContent = App.formatPrice(data.price_rounded);

        // Подсветка разницы
        const roundedCell = document.getElementById(`${type}Rounded`);
        if (data.difference > 0) {
            roundedCell.classList.add('text-success');
            roundedCell.classList.remove('text-danger');
        } else if (data.difference < 0) {
            roundedCell.classList.add('text-danger');
            roundedCell.classList.remove('text-success');
        } else {
            roundedCell.classList.remove('text-success', 'text-danger');
        }
    },

    /**
     * Сохранение товара
     */
    async saveProduct() {
        if (!this.currentResults) {
            App.showToast('Сначала выполните расчёт', 'warning');
            return;
        }

        const formData = new FormData(this.form);

        // Добавляем название материала
        const materialSelect = document.getElementById('material');
        const selectedOption = materialSelect.options[materialSelect.selectedIndex];
        formData.append('material_name', selectedOption.dataset.name || selectedOption.textContent);

        // Добавляем результаты расчёта
        formData.append('price_rounded', this.currentResults.retail.price_rounded);
        formData.append('wb_price', this.currentResults.wb_price);
        formData.append('ozon_price', this.currentResults.ozon_price);

        App.showLoading('Сохранение товара...');

        try {
            const response = await fetch('/api/product/save', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            App.hideLoading();

            if (result.success) {
                App.showToast('Товар успешно сохранён', 'success');
            } else {
                App.showToast(result.message || 'Ошибка сохранения', 'danger');
            }
        } catch (error) {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        }
    },

    /**
     * Загрузка цен на Wildberries
     */
    async uploadToWB() {
        if (!this.currentResults) {
            App.showToast('Сначала выполните расчёт', 'warning');
            return;
        }

        const nmId = document.getElementById('wbArticle').value;
        if (!nmId) {
            App.showToast('Укажите артикул WB', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            `Загрузить цену ${App.formatPrice(this.currentResults.wb_price)} на Wildberries?`,
            'Подтверждение загрузки'
        );

        if (!confirmed) return;

        App.showLoading('Загрузка цены на Wildberries...');

        try {
            const formData = new FormData();
            formData.append('nm_id', nmId);
            formData.append('price', this.currentResults.wb_price);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('/api/wb/prices', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            App.hideLoading();

            if (result.success) {
                App.showToast('Цена успешно загружена на Wildberries', 'success');
            } else {
                App.showToast(result.message || 'Ошибка загрузки', 'danger');
            }
        } catch (error) {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        }
    },

    /**
     * Загрузка цен на Ozon
     */
    async uploadToOzon() {
        if (!this.currentResults) {
            App.showToast('Сначала выполните расчёт', 'warning');
            return;
        }

        const productId = document.getElementById('ozonArticle').value;
        if (!productId) {
            App.showToast('Укажите артикул Ozon', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            `Загрузить цену ${App.formatPrice(this.currentResults.ozon_price)} на Ozon?`,
            'Подтверждение загрузки'
        );

        if (!confirmed) return;

        App.showLoading('Загрузка цены на Ozon...');

        try {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('price', this.currentResults.ozon_price);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('/api/ozon/prices', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            App.hideLoading();

            if (result.success) {
                App.showToast('Цена успешно загружена на Ozon', 'success');
            } else {
                App.showToast(result.message || 'Ошибка загрузки', 'danger');
            }
        } catch (error) {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        }
    },

    /**
     * Обнуление остатков на Wildberries
     */
    async zeroStockWB() {
        const sku = document.getElementById('sellerArticle').value;
        if (!sku) {
            App.showToast('Укажите артикул продавца', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            'Обнулить остатки на Wildberries для этого товара?',
            'Подтверждение обнуления'
        );

        if (!confirmed) return;

        App.showLoading('Обнуление остатков на Wildberries...');

        try {
            const formData = new FormData();
            formData.append('sku', sku);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('/api/wb/zero-stock', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            App.hideLoading();

            if (result.success) {
                App.showToast('Остатки успешно обнулены на Wildberries', 'success');
            } else {
                App.showToast(result.message || 'Ошибка обнуления', 'danger');
            }
        } catch (error) {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        }
    },

    /**
     * Обнуление остатков на Ozon
     */
    async zeroStockOzon() {
        const productId = document.getElementById('ozonArticle').value;
        if (!productId) {
            App.showToast('Укажите артикул Ozon', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            'Обнулить остатки на Ozon для этого товара?',
            'Подтверждение обнуления'
        );

        if (!confirmed) return;

        App.showLoading('Обнуление остатков на Ozon...');

        try {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('/api/ozon/zero-stock', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            App.hideLoading();

            if (result.success) {
                App.showToast('Остатки успешно обнулены на Ozon', 'success');
            } else {
                App.showToast(result.message || 'Ошибка обнуления', 'danger');
            }
        } catch (error) {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        }
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => Calculator.init());
