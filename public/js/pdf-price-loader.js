/**
 * Модуль загрузки цен из PDF-накладных
 * Позволяет парсить PDF, сопоставлять товары и обновлять закупочные цены
 */
const PdfPriceLoader = {
    parsedItems: [],      // Распознанные товары из PDF
    products: [],         // Список товаров системы для выбора

    /**
     * Загрузить и распарсить PDF
     */
    async uploadAndParse() {
        const fileInput = document.getElementById('pdfFileInput');
        const file = fileInput?.files[0];

        if (!file) {
            App.showToast('Выберите PDF-файл', 'warning');
            return;
        }

        if (!file.name.toLowerCase().endsWith('.pdf')) {
            App.showToast('Файл должен быть в формате PDF', 'danger');
            return;
        }

        // Показываем шаг парсинга
        document.getElementById('pdfUploadStep')?.classList.add('d-none');
        document.getElementById('pdfParsingStep')?.classList.remove('d-none');

        const formData = new FormData();
        formData.append('pdf', file);

        try {
            const response = await fetch('/api/products/parse-pdf', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Ошибка парсинга PDF');
            }

            this.parsedItems = result.items || [];
            this.products = result.products || [];

            this.renderMappingTable();

            // Показать таблицу сопоставления
            document.getElementById('pdfParsingStep')?.classList.add('d-none');
            document.getElementById('pdfMappingStep')?.classList.remove('d-none');

            App.showToast(`Распознано ${this.parsedItems.length} товаров`, 'success');

        } catch (error) {
            console.error('PDF parse error:', error);
            App.showToast('Ошибка: ' + error.message, 'danger');
            // Возвращаемся к шагу загрузки
            document.getElementById('pdfParsingStep')?.classList.add('d-none');
            document.getElementById('pdfUploadStep')?.classList.remove('d-none');
        }
    },

    /**
     * Отрисовать таблицу сопоставления
     */
    renderMappingTable() {
        const tbody = document.getElementById('pdfMappingTable');
        if (!tbody) return;

        tbody.innerHTML = '';

        let matchedCount = 0;

        this.parsedItems.forEach((item, index) => {
            const tr = document.createElement('tr');

            const isAutoMapped = item.is_auto_matched && item.matched_product_id;

            if (isAutoMapped) {
                matchedCount++;
                tr.classList.add('table-success');
            }

            tr.innerHTML = `
                <td class="text-center">
                    <input type="checkbox" class="form-check-input pdf-item-check"
                           data-index="${index}" ${isAutoMapped ? 'checked' : ''}>
                </td>
                <td><code>${this.escapeHtml(item.supplier_code)}</code></td>
                <td title="${this.escapeHtml(item.supplier_name)}">
                    ${this.escapeHtml(item.supplier_name)}
                </td>
                <td class="text-end text-warning fw-bold">${this.formatPrice(item.price)}</td>
                <td>
                    <select class="form-select form-select-sm product-select"
                            data-index="${index}"
                            data-code="${this.escapeHtml(item.supplier_code)}"
                            data-name="${this.escapeHtml(item.supplier_name)}">
                        <option value="">-- Выберите товар --</option>
                        ${this.products.map(p => `
                            <option value="${p.id}" ${p.id == item.matched_product_id ? 'selected' : ''}>
                                ${this.escapeHtml(p.name)} ${p.sku ? '(' + this.escapeHtml(p.sku) + ')' : ''}
                            </option>
                        `).join('')}
                    </select>
                </td>
                <td class="text-center status-cell">
                    ${isAutoMapped
                        ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Авто</span>'
                        : '<span class="badge bg-secondary"><i class="bi bi-dash"></i></span>'
                    }
                </td>
            `;

            tbody.appendChild(tr);
        });

        // Обновить статистику
        this.updateStats();

        // Обработчик изменения выбора
        tbody.querySelectorAll('.product-select').forEach(select => {
            select.addEventListener('change', (e) => this.onProductSelect(e));
        });

        // Обработчик чекбокса "выбрать все"
        document.getElementById('selectAllPdfItems')?.addEventListener('change', (e) => {
            document.querySelectorAll('.pdf-item-check').forEach(cb => {
                cb.checked = e.target.checked;
            });
            this.updateStats();
        });

        // Обработчики чекбоксов
        tbody.querySelectorAll('.pdf-item-check').forEach(cb => {
            cb.addEventListener('change', () => this.updateStats());
        });
    },

    /**
     * Обработчик выбора товара
     */
    onProductSelect(e) {
        const select = e.target;
        const productId = select.value;
        const row = select.closest('tr');
        const statusCell = row.querySelector('.status-cell');
        const checkbox = row.querySelector('.pdf-item-check');

        if (productId) {
            row.classList.remove('table-secondary');
            row.classList.add('table-success');
            statusCell.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>';
            if (checkbox) checkbox.checked = true;
        } else {
            row.classList.remove('table-success');
            statusCell.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-dash"></i></span>';
        }

        this.updateStats();
    },

    /**
     * Обновить статистику
     */
    updateStats() {
        let matched = 0;
        let total = 0;

        document.querySelectorAll('.product-select').forEach(select => {
            total++;
            if (select.value) {
                matched++;
            }
        });

        const totalEl = document.getElementById('pdfTotalItems');
        const matchedEl = document.getElementById('pdfMatchedItems');

        if (totalEl) totalEl.textContent = total;
        if (matchedEl) matchedEl.textContent = matched;
    },

    /**
     * Применить цены и сохранить сопоставления
     */
    async applyPrices() {
        const items = [];

        document.querySelectorAll('.product-select').forEach((select, index) => {
            const productId = select.value;
            const checkbox = document.querySelector(`.pdf-item-check[data-index="${index}"]`);

            if (productId && checkbox?.checked) {
                const item = this.parsedItems[index];
                items.push({
                    supplier_code: item.supplier_code,
                    supplier_name: item.supplier_name,
                    product_id: parseInt(productId),
                    price: item.price,
                    save_mapping: true
                });
            }
        });

        if (items.length === 0) {
            App.showToast('Выберите хотя бы один товар для обновления', 'warning');
            return;
        }

        const btn = document.getElementById('applyPdfPricesBtn');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Сохранение...';
        }

        try {
            const response = await fetch('/api/products/apply-pdf-prices', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ items })
            });

            const result = await response.json();

            if (result.success) {
                // Показать результат
                document.getElementById('pdfMappingStep')?.classList.add('d-none');
                document.getElementById('pdfResultStep')?.classList.remove('d-none');

                const resultMsg = document.getElementById('pdfResultMessage');
                if (resultMsg) {
                    resultMsg.textContent = `Обновлено товаров: ${result.updated}, сохранено сопоставлений: ${result.mappings_saved}`;
                }

                App.showToast(result.message, 'success');

                // Перезагрузить страницу после закрытия модального окна
                const modal = document.getElementById('uploadPdfModal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        location.reload();
                    }, { once: true });
                }

            } else {
                throw new Error(result.message || 'Ошибка применения цен');
            }

        } catch (error) {
            console.error('Apply prices error:', error);
            App.showToast('Ошибка: ' + error.message, 'danger');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    },

    /**
     * Вернуться к загрузке файла
     */
    goBack() {
        document.getElementById('pdfMappingStep')?.classList.add('d-none');
        document.getElementById('pdfUploadStep')?.classList.remove('d-none');
        document.getElementById('pdfFileInput').value = '';
    },

    /**
     * Сброс модального окна при закрытии
     */
    resetModal() {
        this.parsedItems = [];
        this.products = [];

        document.getElementById('pdfUploadStep')?.classList.remove('d-none');
        document.getElementById('pdfParsingStep')?.classList.add('d-none');
        document.getElementById('pdfMappingStep')?.classList.add('d-none');
        document.getElementById('pdfResultStep')?.classList.add('d-none');

        const fileInput = document.getElementById('pdfFileInput');
        if (fileInput) fileInput.value = '';

        const tbody = document.getElementById('pdfMappingTable');
        if (tbody) tbody.innerHTML = '';
    },

    /**
     * Форматирование цены
     */
    formatPrice(price) {
        if (!price && price !== 0) return '—';
        return new Intl.NumberFormat('ru-RU').format(Math.round(price)) + ' ₽';
    },

    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    },

    /**
     * Инициализация
     */
    init() {
        // Кнопка выбора файла
        document.getElementById('selectPdfBtn')?.addEventListener('click', () => {
            document.getElementById('pdfFileInput')?.click();
        });

        // При выборе файла - сразу парсим
        document.getElementById('pdfFileInput')?.addEventListener('change', () => {
            this.uploadAndParse();
        });

        // Кнопка "Назад"
        document.getElementById('pdfBackBtn')?.addEventListener('click', () => {
            this.goBack();
        });

        // Кнопка "Применить цены"
        document.getElementById('applyPdfPricesBtn')?.addEventListener('click', () => {
            this.applyPrices();
        });

        // Сброс при закрытии модального окна
        const modal = document.getElementById('uploadPdfModal');
        if (modal) {
            modal.addEventListener('hidden.bs.modal', () => this.resetModal());
        }
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    PdfPriceLoader.init();
});
