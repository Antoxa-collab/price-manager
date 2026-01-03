/**
 * Price Manager - Калькулятор цен Wildberries
 * Расчёт и загрузка цен на маркетплейс
 */

const WBCalculator = {
    // Данные
    products: [],           // Товары с сопоставлениями
    articles: [],           // Артикулы выбранного товара
    warehouses: [],         // Склады WB
    selectedProduct: null,  // Выбранный товар
    selectedArticles: new Set(), // Выбранные артикулы для загрузки
    syncStats: null,        // Статистика синхронизации

    /**
     * Инициализация модуля
     */
    init() {
        console.log('WBCalculator.init() started');
        this.bindEvents();
        this.loadProducts();
        this.loadWarehouses();
        this.loadSyncStats();
        this.initTooltips();
        console.log('WBCalculator.init() completed');
    },

    /**
     * Инициализация тултипов Bootstrap
     */
    initTooltips() {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        // Выбор товара
        document.getElementById('productSelect')?.addEventListener('change', (e) => {
            this.onProductSelect(e.target.value);
        });

        // Изменение наценок - живой пересчёт
        document.getElementById('markupMin')?.addEventListener('input', () => this.recalculatePrices());
        document.getElementById('wbDiscount')?.addEventListener('input', () => this.recalculatePrices());

        // Кнопка пересчёта
        document.getElementById('recalculateBtn')?.addEventListener('click', () => this.recalculatePrices());

        // Сохранение наценок
        document.getElementById('saveMarkupsBtn')?.addEventListener('click', () => this.saveMarkups());

        // Выбор всех артикулов
        document.getElementById('selectAllCheckbox')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                this.selectAllArticles();
            } else {
                this.clearArticleSelection();
            }
        });

        // Загрузка цен
        document.getElementById('uploadSelectedBtn')?.addEventListener('click', () => this.uploadSelected());
        document.getElementById('uploadAllBtn')?.addEventListener('click', () => this.uploadAll());

        // Редактирование параметров упаковки
        document.getElementById('savePackBtn')?.addEventListener('click', () => this.savePackSettings());

        // Живое обновление превью в модалке
        document.getElementById('editPiecesPerSheet')?.addEventListener('input', () => this.updatePackPreview());
        document.getElementById('editQuantityInPack')?.addEventListener('input', () => this.updatePackPreview());

        // Автозаполнение из названий артикулов
        document.getElementById('autoFillBtn')?.addEventListener('click', () => this.autoFillFromNames());

        // Управление остатками
        document.getElementById('applyBulkStockBtn')?.addEventListener('click', () => this.applyBulkStock(false));
        document.getElementById('applyAllStockBtn')?.addEventListener('click', () => this.applyBulkStock(true));
        document.getElementById('zeroStockBtn')?.addEventListener('click', () => this.zeroAllStocks());

        // Загрузка только остатков
        document.getElementById('uploadStocksOnlyBtn')?.addEventListener('click', () => this.uploadStocksOnly());

        // Синхронизация с WB
        document.getElementById('syncWbBtn')?.addEventListener('click', () => this.syncWithWB());
    },

    /**
     * Загрузка статистики синхронизации
     */
    async loadSyncStats() {
        try {
            const data = await App.fetch('/api/wb/products?limit=1');
            if (data.stats) {
                this.syncStats = data.stats;
                this.renderSyncStats();
            }
        } catch (error) {
            console.error('Failed to load sync stats:', error);
        }
    },

    /**
     * Отображение статистики синхронизации
     */
    renderSyncStats() {
        const row = document.getElementById('syncStatsRow');
        const text = document.getElementById('syncStatsText');
        const time = document.getElementById('lastSyncTime');

        if (!this.syncStats || !row) return;

        row.classList.remove('d-none');
        text.textContent = `Товаров в кэше: ${this.syncStats.total_products || 0} | Сопоставлено: ${this.syncStats.mapped_count || 0}`;

        if (this.syncStats.last_sync) {
            time.textContent = `Последняя синхронизация: ${new Date(this.syncStats.last_sync).toLocaleString()}`;
        }
    },

    /**
     * Загрузка складов WB
     */
    async loadWarehouses() {
        try {
            const data = await App.fetch('/api/wb/warehouses');
            this.warehouses = data.warehouses || [];
            this.renderWarehouseSelect();
        } catch (error) {
            console.error('Failed to load warehouses:', error);
        }
    },

    /**
     * Рендеринг списка складов
     */
    renderWarehouseSelect() {
        const select = document.getElementById('warehouseSelect');
        if (!select) return;

        select.innerHTML = '';

        if (this.warehouses.length === 0) {
            select.innerHTML = '<option value="">Нет доступных складов</option>';
            return;
        }

        this.warehouses.forEach(wh => {
            const option = document.createElement('option');
            option.value = wh.id;
            option.textContent = wh.name;
            select.appendChild(option);
        });
    },

    /**
     * Загрузка списка НАШИХ товаров с сопоставлениями WB
     */
    async loadProducts() {
        console.log('loadProducts() called');
        try {
            // Загружаем НАШИ товары из таблицы products (не товары WB!)
            const data = await App.fetch('/api/wb/products-with-mappings');
            console.log('API response:', data);
            this.products = data.products || [];
            console.log('Products loaded:', this.products.length);
            this.renderProductSelect();
        } catch (error) {
            console.error('loadProducts error:', error);
            App.showToast('Ошибка загрузки товаров: ' + error.message, 'danger');
        }
    },

    /**
     * Рендеринг выпадающего списка товаров
     */
    renderProductSelect() {
        console.log('renderProductSelect() called, products:', this.products.length);
        const select = document.getElementById('productSelect');
        if (!select) {
            console.error('productSelect element not found!');
            return;
        }

        // Очищаем и добавляем placeholder
        select.innerHTML = '<option value="">Выберите товар...</option>';

        if (this.products.length === 0) {
            console.log('No products to display');
            select.innerHTML += '<option value="" disabled>Нет товаров с сопоставлениями</option>';
            document.getElementById('productInfo').innerHTML =
                'Нет товаров с привязанными артикулами. <a href="/wildberries/mapping">Создайте сопоставления</a>';
            return;
        }

        this.products.forEach((product, index) => {
            const mappingCount = product.mapping_count || 0;
            const option = document.createElement('option');
            option.value = product.id;
            // Используем name из таблицы products (НАШИ товары)
            const productName = product.name || 'Без названия';
            option.textContent = `${productName} (${mappingCount} артикулов WB)`;
            option.dataset.costPrice = product.cost_price || 0;
            option.dataset.basePrice = product.base_price || 0;
            option.dataset.markupMin = product.markup_min_price || 0;
            option.dataset.wbDiscount = product.wb_discount || 0;
            select.appendChild(option);
            console.log(`Added option ${index}: id=${product.id}, name=${productName}`);
        });

        console.log('Select now has', select.options.length, 'options');
        document.getElementById('productInfo').textContent =
            `Доступно ${this.products.length} товаров с привязанными артикулами`;
    },

    /**
     * Обработчик выбора товара
     */
    async onProductSelect(productId) {
        if (!productId) {
            this.selectedProduct = null;
            this.articles = [];
            this.renderArticlesTable();
            this.hideCalculatedPrices();
            this.updateButtons();
            return;
        }

        this.selectedProduct = this.products.find(p => String(p.id) === String(productId));
        if (!this.selectedProduct) return;

        // Загружаем наценки из товара
        document.getElementById('markupMin').value = this.selectedProduct.markup_min_price || 20;
        document.getElementById('wbDiscount').value = this.selectedProduct.wb_discount || 0;

        // Загружаем артикулы товара
        await this.loadArticles(productId);

        // Пересчитываем цены
        this.recalculatePrices();

        // Активируем кнопки
        this.updateButtons();
    },

    /**
     * Загрузка артикулов WB для выбранного НАШЕГО товара
     */
    async loadArticles(productId) {
        try {
            // Загружаем артикулы WB связанные с нашим товаром
            const data = await App.fetch(`/api/wb/product-articles?product_id=${productId}`);
            this.articles = data.mappings || [];
            this.selectedArticles.clear();
            this.renderArticlesTable();
        } catch (error) {
            App.showToast('Ошибка загрузки артикулов: ' + error.message, 'danger');
        }
    },

    /**
     * Пересчёт цен
     * Формула для WB:
     * cost = (cost_price / pieces_per_sheet) × quantity_in_pack
     * price_before_discount = cost × (1 + markup_min / 100)
     * price_after_discount = price_before_discount × (1 - wb_discount / 100)
     */
    recalculatePrices() {
        if (!this.selectedProduct) return;

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const wbDiscount = parseFloat(document.getElementById('wbDiscount').value) || 0;
        const costPrice = this.selectedProduct.cost_price || 0;
        const basePrice = this.selectedProduct.base_price || costPrice;

        // Расчёт для базовой единицы (1 шт из листа)
        const unitCost = costPrice;
        const unitPriceBeforeDiscount = this.roundPrice(unitCost * (1 + markupMin / 100));
        const unitPriceAfterDiscount = this.roundPrice(unitPriceBeforeDiscount * (1 - wbDiscount / 100));

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcBasePrice').textContent = App.formatPrice(basePrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitPriceBeforeDiscount);
        document.getElementById('calcFinalPrice').textContent = App.formatPrice(unitPriceAfterDiscount);

        // Пересчитываем цены для каждого артикула
        this.articles.forEach(article => {
            const piecesPerSheet = article.pieces_per_sheet || 1;
            const quantityInPack = article.quantity_in_pack || 1;

            // Себестоимость: (закупка / кол-во из листа) × кол-во в упаковке
            const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

            // Цена до скидки (для установки на WB)
            article.calculated_price = this.roundPrice(articleCost * (1 + markupMin / 100));

            // Цена после скидки (что видит покупатель)
            article.calculated_final_price = this.roundPrice(article.calculated_price * (1 - wbDiscount / 100));

            // Скидка для артикула
            article.calculated_discount = wbDiscount;

            // Сохраняем себестоимость для отображения
            article.calculated_cost = articleCost;

            // Определяем статус
            const currentPrice = article.wb_price || 0;
            if (currentPrice === 0) {
                article.status = 'new';
            } else if (Math.abs(currentPrice - article.calculated_price) > 1) {
                article.status = 'changed';
            } else {
                article.status = 'ok';
            }
        });

        this.renderArticlesTable();
    },

    /**
     * Скрыть блок с расчётными ценами
     */
    hideCalculatedPrices() {
        document.getElementById('calculatedPricesBlock')?.classList.add('d-none');
    },

    /**
     * Рендеринг таблицы артикулов
     */
    renderArticlesTable() {
        const tbody = document.getElementById('articlesTableBody');
        if (!tbody) return;

        document.getElementById('articlesCount').textContent = this.articles.length;

        // Показываем/скрываем блок управления остатками
        const stockCard = document.getElementById('stockManagementCard');
        if (stockCard) {
            if (this.articles.length > 0) {
                stockCard.classList.remove('d-none');
            } else {
                stockCard.classList.add('d-none');
            }
        }

        if (this.articles.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-inbox display-4"></i>
                        <p class="mt-3">
                            ${this.selectedProduct
                                ? 'У этого товара нет привязанных артикулов Wildberries'
                                : 'Выберите товар для просмотра привязанных артикулов'}
                        </p>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = this.articles.map(article => this.renderArticleRow(article)).join('');

        // Привязываем события
        tbody.querySelectorAll('.article-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const id = e.target.dataset.id;
                if (e.target.checked) {
                    this.selectedArticles.add(id);
                } else {
                    this.selectedArticles.delete(id);
                }
                this.updateSelectionInfo();
            });
        });

        tbody.querySelectorAll('.edit-pack-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.openPackModal(btn.dataset.id, btn.dataset.nmid, btn.dataset.pieces, btn.dataset.qty);
            });
        });

        // Привязываем события изменения остатков
        tbody.querySelectorAll('.stock-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const mappingId = e.target.dataset.id;
                const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
                if (article) {
                    article.stock = parseInt(e.target.value) || 0;
                }
            });
        });

        // Клик по строке переключает чекбокс
        tbody.querySelectorAll('tr[data-mapping-id]').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('input, button, a, .edit-pack-btn')) return;

                const checkbox = row.querySelector('.article-checkbox');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        // Кнопка удаления сопоставления
        tbody.querySelectorAll('.delete-mapping-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const mappingId = btn.dataset.id;
                this.deleteMapping(mappingId);
            });
        });

        this.updateSelectionInfo();
    },

    /**
     * Рендеринг строки артикула
     */
    renderArticleRow(article) {
        const isSelected = this.selectedArticles.has(String(article.mapping_id));
        const statusHtml = this.getStatusBadge(article.status);
        const piecesPerSheet = article.pieces_per_sheet || 1;
        const quantityInPack = article.quantity_in_pack || 1;

        return `
            <tr data-mapping-id="${article.mapping_id}" data-status="${article.status || 'new'}">
                <td>
                    <input type="checkbox" class="form-check-input article-checkbox"
                           data-id="${article.mapping_id}" ${isSelected ? 'checked' : ''}>
                </td>
                <td>
                    <code>${App.escapeHtml(article.vendor_code || article.nm_id || '')}</code>
                    <div class="small text-muted">nmID: ${article.nm_id || '-'}</div>
                </td>
                <td class="text-truncate" style="max-width: 200px;" title="${App.escapeHtml(article.wb_name || '')}">
                    ${App.escapeHtml(article.wb_name || '-')}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-pack-btn"
                            data-id="${article.mapping_id}"
                            data-nmid="${article.nm_id}"
                            data-qty="${quantityInPack}"
                            data-pieces="${piecesPerSheet}"
                            title="Из листа: ${piecesPerSheet}, В упаковке: ${quantityInPack}">
                        ${piecesPerSheet}/${quantityInPack}
                    </button>
                </td>
                <td class="text-end fw-bold text-warning">
                    ${App.formatPrice(article.calculated_price || 0)}
                </td>
                <td class="text-end text-info">
                    ${article.calculated_discount || 0}%
                </td>
                <td class="text-end">
                    ${article.wb_price > 0 ? App.formatPrice(article.wb_price) : '<span class="text-muted">-</span>'}
                </td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm stock-input text-center"
                           value="${article.stock || 0}"
                           data-id="${article.mapping_id}"
                           min="0"
                           style="width: 80px; display: inline-block;">
                </td>
                <td class="text-center">
                    ${statusHtml}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger delete-mapping-btn"
                            data-id="${article.mapping_id}" title="Удалить связь">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </td>
            </tr>
        `;
    },

    /**
     * Получение индикатора статуса
     */
    getStatusBadge(status) {
        switch (status) {
            case 'ok':
                return '<span class="status-indicator ok" title="Цена актуальна"><i class="bi bi-check-lg"></i></span>';
            case 'changed':
                return '<span class="status-indicator warning" title="Цена изменилась"><i class="bi bi-exclamation"></i></span>';
            case 'new':
                return '<span class="status-indicator new" title="Новый артикул"><i class="bi bi-question"></i></span>';
            default:
                return '<span class="status-indicator error" title="Ошибка"><i class="bi bi-x"></i></span>';
        }
    },

    /**
     * Выбрать все артикулы
     */
    selectAllArticles() {
        this.articles.forEach(a => {
            this.selectedArticles.add(String(a.mapping_id));
        });
        this.renderArticlesTable();
    },

    /**
     * Сбросить выбор артикулов
     */
    clearArticleSelection() {
        this.selectedArticles.clear();
        this.renderArticlesTable();
        document.getElementById('selectAllCheckbox').checked = false;
    },

    /**
     * Обновление информации о выборе
     */
    updateSelectionInfo() {
        const count = this.selectedArticles.size;
        const actionsDiv = document.getElementById('tableActions');
        const countSpan = document.getElementById('selectedCount');

        if (count > 0) {
            actionsDiv?.classList.remove('d-none');
            countSpan.textContent = count;
        } else {
            actionsDiv?.classList.add('d-none');
        }

        this.updateButtons();
    },

    /**
     * Обновление состояния кнопок
     */
    updateButtons() {
        const hasProduct = !!this.selectedProduct;
        const hasArticles = this.articles.length > 0;
        const hasSelected = this.selectedArticles.size > 0;

        document.getElementById('recalculateBtn').disabled = !hasProduct;
        document.getElementById('saveMarkupsBtn').disabled = !hasProduct;
        document.getElementById('autoFillBtn').disabled = !hasArticles;
        document.getElementById('uploadSelectedBtn').disabled = !hasSelected;
        document.getElementById('uploadAllBtn').disabled = !hasArticles;

        const uploadStocksOnlyBtn = document.getElementById('uploadStocksOnlyBtn');
        if (uploadStocksOnlyBtn) {
            uploadStocksOnlyBtn.disabled = !hasArticles;
        }
    },

    /**
     * Сохранение наценок для товара
     */
    async saveMarkups() {
        if (!this.selectedProduct) return;

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const wbDiscount = parseFloat(document.getElementById('wbDiscount').value) || 0;

        try {
            await App.fetch('/api/wb/mapping', {
                method: 'POST',
                body: {
                    action: 'save_markups',
                    product_id: this.selectedProduct.id,
                    markup_min_price: markupMin,
                    wb_discount: wbDiscount
                }
            });

            // Обновляем локальные данные
            this.selectedProduct.markup_min_price = markupMin;
            this.selectedProduct.wb_discount = wbDiscount;

            App.showToast('Настройки сохранены', 'success');
        } catch (error) {
            App.showToast('Ошибка сохранения: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузить выбранные артикулы на WB
     */
    async uploadSelected() {
        if (this.selectedArticles.size === 0) {
            App.showToast('Выберите артикулы для загрузки', 'warning');
            return;
        }

        const articlesToUpload = this.articles.filter(a =>
            this.selectedArticles.has(String(a.mapping_id))
        );

        await this.uploadPrices(articlesToUpload);
    },

    /**
     * Загрузить все артикулы товара на WB
     */
    async uploadAll() {
        if (this.articles.length === 0) {
            App.showToast('Нет артикулов для загрузки', 'warning');
            return;
        }

        await this.uploadPrices(this.articles);
    },

    /**
     * Загрузка цен на Wildberries
     */
    async uploadPrices(articles) {
        const wbDiscount = parseFloat(document.getElementById('wbDiscount').value) || 0;

        let confirmMsg = `Загрузить цены для ${articles.length} артикулов на Wildberries?`;
        if (wbDiscount > 0) {
            confirmMsg += `\nСкидка: ${wbDiscount}%`;
        }

        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');
        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/wb/upload-prices', {
                method: 'POST',
                body: {
                    prices: articles.map(a => ({
                        nmID: parseInt(a.nm_id),
                        price: Math.round(a.calculated_price),
                        discount: Math.round(a.calculated_discount || wbDiscount)
                    }))
                }
            });

            App.showToast(data.message || 'Цены загружены', data.success ? 'success' : 'warning');

            // Показываем результаты
            this.showUploadResults(data);

            // Перезагружаем артикулы для обновления статусов
            await this.loadArticles(this.selectedProduct.id);
            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка загрузки: ' + error.message, 'danger');
        }
    },

    /**
     * Показать результаты загрузки
     */
    showUploadResults(data) {
        const card = document.getElementById('uploadResultsCard');
        const content = document.getElementById('uploadResultsContent');
        if (!card || !content) return;

        card.classList.remove('d-none');

        const updatedCount = data.updated || 0;
        const errorCount = data.error_count || 0;
        const errors = data.errors || [];

        content.innerHTML = `
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-currency-exchange text-success display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Цены обновлены</div>
                        <div class="fs-4 fw-bold">${updatedCount}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-x-circle-fill text-danger display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Ошибок</div>
                        <div class="fs-4 fw-bold">${errorCount}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-clock-history text-secondary display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Время загрузки</div>
                        <div class="fs-6">${new Date().toLocaleTimeString()}</div>
                    </div>
                </div>
            </div>
            ${errors.length > 0 ? `
                <div class="col-12 mt-3">
                    <div class="alert alert-danger mb-0">
                        <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Ошибки:</h6>
                        <ul class="mb-0 mt-2">
                            ${errors.map(e => `<li>${App.escapeHtml(typeof e === 'string' ? e : JSON.stringify(e))}</li>`).join('')}
                        </ul>
                    </div>
                </div>
            ` : ''}
        `;
    },

    /**
     * Открыть модальное окно редактирования параметров упаковки
     */
    openPackModal(mappingId, nmId, piecesPerSheet, quantityInPack) {
        document.getElementById('editPackMappingId').value = mappingId;
        document.getElementById('editPackNmId').value = nmId;
        document.getElementById('editPiecesPerSheet').value = piecesPerSheet || 1;
        document.getElementById('editQuantityInPack').value = quantityInPack || 1;

        this.updatePackPreview();

        const modal = new bootstrap.Modal(document.getElementById('editPackModal'));
        modal.show();
    },

    /**
     * Обновить превью расчёта в модалке
     */
    updatePackPreview() {
        const piecesPerSheet = parseInt(document.getElementById('editPiecesPerSheet').value) || 1;
        const quantityInPack = parseInt(document.getElementById('editQuantityInPack').value) || 1;
        const costPrice = this.selectedProduct?.cost_price || 0;

        const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

        const preview = document.getElementById('packPreview');
        if (preview) {
            preview.innerHTML = `
                <div class="text-muted small mb-1">Формула: (${App.formatPrice(costPrice)} / ${piecesPerSheet}) × ${quantityInPack}</div>
                <div class="fw-bold">Себестоимость: ${App.formatPrice(articleCost)}</div>
            `;
        }
    },

    /**
     * Сохранить параметры упаковки
     */
    async savePackSettings() {
        const mappingId = document.getElementById('editPackMappingId').value;
        const piecesPerSheet = parseInt(document.getElementById('editPiecesPerSheet').value) || 1;
        const quantityInPack = parseInt(document.getElementById('editQuantityInPack').value) || 1;

        try {
            await App.fetch('/api/wb/mapping', {
                method: 'POST',
                body: {
                    action: 'update_pack',
                    mapping_id: mappingId,
                    pieces_per_sheet: piecesPerSheet,
                    quantity_in_pack: quantityInPack
                }
            });

            bootstrap.Modal.getInstance(document.getElementById('editPackModal'))?.hide();
            App.showToast('Сохранено', 'success');

            // Обновляем локальные данные
            const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
            if (article) {
                article.pieces_per_sheet = piecesPerSheet;
                article.quantity_in_pack = quantityInPack;
            }

            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack из артикулов WB
     */
    async autoFillFromNames() {
        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        const btn = document.getElementById('autoFillBtn');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            let updated = 0;

            for (const article of this.articles) {
                // Парсим артикул WB
                const parseResult = await App.fetch(`/api/wb/parse-article?article=${encodeURIComponent(article.vendor_code || '')}`);

                if (parseResult.success && parseResult.data) {
                    const { pieces_per_sheet, quantity_in_pack } = parseResult.data;

                    if (pieces_per_sheet || quantity_in_pack) {
                        await App.fetch('/api/wb/mapping', {
                            method: 'POST',
                            body: {
                                action: 'update_pack',
                                mapping_id: article.mapping_id,
                                pieces_per_sheet: pieces_per_sheet || article.pieces_per_sheet || 1,
                                quantity_in_pack: quantity_in_pack || article.quantity_in_pack || 1
                            }
                        });

                        article.pieces_per_sheet = pieces_per_sheet || article.pieces_per_sheet;
                        article.quantity_in_pack = quantity_in_pack || article.quantity_in_pack;
                        updated++;
                    }
                }
            }

            App.showToast(`Обновлено ${updated} артикулов`, 'success');
            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    },

    /**
     * Применить остатки к артикулам
     */
    applyBulkStock(applyToAll = false) {
        const stock = parseInt(document.getElementById('bulkStock')?.value) || 0;

        if (applyToAll) {
            this.articles.forEach(article => {
                article.stock = stock;
            });
            document.querySelectorAll('.stock-input').forEach(input => {
                input.value = stock;
            });
            App.showToast(`Остатки ${stock} применены ко всем артикулам`, 'success');
        } else {
            if (this.selectedArticles.size === 0) {
                App.showToast('Сначала выберите артикулы', 'warning');
                return;
            }

            this.articles.forEach(article => {
                if (this.selectedArticles.has(String(article.mapping_id))) {
                    article.stock = stock;
                }
            });

            document.querySelectorAll('.stock-input').forEach(input => {
                if (this.selectedArticles.has(input.dataset.id)) {
                    input.value = stock;
                }
            });

            App.showToast(`Остатки ${stock} применены к ${this.selectedArticles.size} артикулам`, 'success');
        }
    },

    /**
     * Обнулить все остатки
     */
    zeroAllStocks() {
        this.articles.forEach(article => {
            article.stock = 0;
        });

        document.querySelectorAll('.stock-input').forEach(input => {
            input.value = 0;
        });

        document.getElementById('bulkStock').value = 0;
        App.showToast('Все остатки обнулены', 'info');
    },

    /**
     * Загрузка ТОЛЬКО остатков на WB
     */
    async uploadStocksOnly() {
        let articlesToUpload = [];
        if (this.selectedArticles.size > 0) {
            articlesToUpload = this.articles.filter(a => this.selectedArticles.has(String(a.mapping_id)));
        } else {
            articlesToUpload = this.articles;
        }

        if (articlesToUpload.length === 0) {
            App.showToast('Нет товаров для загрузки остатков', 'warning');
            return;
        }

        const warehouseId = document.getElementById('warehouseSelect')?.value;
        if (!warehouseId) {
            App.showToast('Выберите склад для загрузки остатков', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            `Загрузить остатки для ${articlesToUpload.length} артикулов на Wildberries?`,
            'Загрузка остатков'
        );
        if (!confirmed) return;

        const btn = document.getElementById('uploadStocksOnlyBtn');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Загрузка...';
        }

        try {
            const stocks = articlesToUpload.map(article => ({
                sku: article.vendor_code,
                amount: parseInt(article.stock) || 0
            }));

            const result = await App.fetch('/api/wb/upload-stocks', {
                method: 'POST',
                body: {
                    warehouse_id: parseInt(warehouseId),
                    stocks: stocks
                }
            });

            this.showUploadResults({
                success: result.success,
                updated: result.updated || 0,
                errors: result.errors || [],
                error_count: (result.errors || []).length
            });

            if (result.success) {
                App.showToast(`Остатки загружены: ${result.updated || 0}`, 'success');
            } else {
                App.showToast('Ошибка: ' + (result.error || result.message || 'Неизвестная ошибка'), 'danger');
            }

        } catch (error) {
            console.error('Upload stocks error:', error);
            App.showToast('Ошибка загрузки: ' + error.message, 'danger');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    },

    /**
     * Удаление сопоставления
     */
    async deleteMapping(mappingId) {
        if (!mappingId) {
            App.showToast('Ошибка: не указан ID сопоставления', 'danger');
            return;
        }

        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        const articleName = article?.vendor_code || article?.wb_name || mappingId;

        const confirmed = await App.confirm(
            `Удалить связь с артикулом "${articleName}"?`,
            'Подтверждение удаления'
        );

        if (!confirmed) return;

        try {
            const result = await App.fetch('/api/wb/mapping', {
                method: 'DELETE',
                body: { mapping_id: mappingId }
            });

            if (result.success) {
                App.showToast('Сопоставление удалено', 'success');

                this.articles = this.articles.filter(a => String(a.mapping_id) !== String(mappingId));
                this.selectedArticles.delete(String(mappingId));

                this.renderArticlesTable();
                this.updateButtons();
            } else {
                App.showToast('Ошибка: ' + (result.message || 'Не удалось удалить'), 'danger');
            }
        } catch (error) {
            console.error('Delete mapping error:', error);
            App.showToast('Ошибка удаления: ' + error.message, 'danger');
        }
    },

    /**
     * Синхронизация с Wildberries
     */
    async syncWithWB() {
        const modal = new bootstrap.Modal(document.getElementById('syncModal'));
        modal.show();

        // Показываем загрузку
        document.getElementById('syncModalLoading').classList.remove('d-none');
        document.getElementById('syncModalResult').classList.add('d-none');
        document.getElementById('syncModalError').classList.add('d-none');
        document.getElementById('syncModalFooter').classList.add('d-none');

        try {
            const result = await App.fetch('/api/wb/sync-products', {
                method: 'POST',
                timeout: 120000 // 2 минуты таймаут
            });

            document.getElementById('syncModalLoading').classList.add('d-none');

            if (result.success) {
                document.getElementById('syncModalResult').classList.remove('d-none');
                document.getElementById('syncResultText').textContent =
                    `Синхронизировано ${result.synced || 0} товаров`;

                // Перезагружаем данные
                await this.loadProducts();
                await this.loadSyncStats();
            } else {
                document.getElementById('syncModalError').classList.remove('d-none');
                document.getElementById('syncErrorText').textContent =
                    result.error || 'Неизвестная ошибка';
            }

        } catch (error) {
            document.getElementById('syncModalLoading').classList.add('d-none');
            document.getElementById('syncModalError').classList.remove('d-none');
            document.getElementById('syncErrorText').textContent = error.message;
        }

        document.getElementById('syncModalFooter').classList.remove('d-none');
    },

    /**
     * Округление цены по правилам WB
     */
    roundPrice(price) {
        if (price < 100) {
            return Math.ceil(price / 10) * 10 - 1;
        } else if (price < 500) {
            const r = price % 100;
            return r < 50 ? Math.floor(price / 100) * 100 + 49 : Math.floor(price / 100) * 100 + 99;
        } else if (price < 1000) {
            return Math.ceil(price / 100) * 100 - 1;
        } else if (price < 5000) {
            const r = price % 1000;
            if (r < 100) return Math.floor(price / 1000) * 1000 + 99;
            if (r < 500) return Math.floor(price / 1000) * 1000 + 499;
            return Math.floor(price / 1000) * 1000 + 999;
        } else {
            return Math.ceil(price / 1000) * 1000 - 1;
        }
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => WBCalculator.init());
