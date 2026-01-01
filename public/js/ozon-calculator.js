/**
 * Price Manager - Калькулятор цен Ozon
 * Расчёт и загрузка цен на маркетплейс
 */

const OzonCalculator = {
    // Данные
    products: [],           // Товары с сопоставлениями
    articles: [],           // Артикулы выбранного товара
    selectedProduct: null,  // Выбранный товар
    selectedArticles: new Set(), // Выбранные артикулы для загрузки

    /**
     * Инициализация модуля
     */
    init() {
        console.log('OzonCalculator.init() started');
        this.bindEvents();
        this.loadProducts();
        this.initTooltips();
        console.log('OzonCalculator.init() completed');
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
        document.getElementById('markupYour')?.addEventListener('input', () => this.recalculatePrices());

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
    },

    /**
     * Загрузка списка товаров с сопоставлениями
     */
    async loadProducts() {
        console.log('loadProducts() called');
        try {
            const data = await App.fetch('/api/ozon/products-with-mappings');
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
                'Нет товаров с привязанными артикулами. <a href="/ozon/mapping">Создайте сопоставления</a>';
            return;
        }

        this.products.forEach((product, index) => {
            const mappingCount = product.mapping_count || 0;
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = `${product.name} (${mappingCount} артикулов)`;
            option.dataset.costPrice = product.cost_price || 0;
            option.dataset.basePrice = product.base_price || 0;
            option.dataset.markupMin = product.markup_min_price || 0;
            option.dataset.markupYour = product.markup_your_price || 0;
            select.appendChild(option);
            console.log(`Added option ${index}: id=${product.id}, name=${product.name}`);
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
        document.getElementById('markupYour').value = this.selectedProduct.markup_your_price || 5;

        // Загружаем артикулы товара
        await this.loadArticles(productId);

        // Пересчитываем цены
        this.recalculatePrices();

        // Активируем кнопки
        this.updateButtons();
    },

    /**
     * Загрузка артикулов для выбранного товара
     */
    async loadArticles(productId) {
        try {
            const data = await App.fetch(`/api/ozon/product-articles?product_id=${productId}`);
            this.articles = data.articles || [];
            this.selectedArticles.clear();
            this.renderArticlesTable();
        } catch (error) {
            App.showToast('Ошибка загрузки артикулов: ' + error.message, 'danger');
        }
    },

    /**
     * Пересчёт цен
     * Формула: cost = (cost_price / pieces_per_sheet) × quantity_in_pack
     * min_price = cost × (1 + markup_min / 100)
     * your_price = min_price × (1 + markup_your / 100)
     */
    recalculatePrices() {
        if (!this.selectedProduct) return;

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const markupYour = parseFloat(document.getElementById('markupYour').value) || 0;
        const costPrice = this.selectedProduct.cost_price || 0;
        const basePrice = this.selectedProduct.base_price || costPrice;

        // Расчёт для базовой единицы (1 шт из листа)
        // Это цена за 1 единицу если pieces_per_sheet=1
        const unitCost = costPrice; // Закупочная цена за 1 лист/единицу
        const unitMinPrice = this.roundPrice(unitCost * (1 + markupMin / 100));
        const unitYourPrice = this.roundPrice(unitMinPrice * (1 + markupYour / 100));

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcBasePrice').textContent = App.formatPrice(basePrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitMinPrice);
        document.getElementById('calcYourPrice').textContent = App.formatPrice(unitYourPrice);

        // Пересчитываем цены для каждого артикула
        // cost = (cost_price / pieces_per_sheet) × quantity_in_pack
        this.articles.forEach(article => {
            const piecesPerSheet = article.pieces_per_sheet || 1; // Сколько единиц из 1 листа
            const quantityInPack = article.quantity_in_pack || 1; // Сколько в упаковке на Ozon

            // Себестоимость: (закупка / кол-во из листа) × кол-во в упаковке
            const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

            // Минимальная цена с наценкой
            article.calculated_min_price = this.roundPrice(articleCost * (1 + markupMin / 100));

            // Ваша цена = минимальная × (1 + доп.наценка)
            article.calculated_your_price = this.roundPrice(article.calculated_min_price * (1 + markupYour / 100));

            // Сохраняем себестоимость для отображения
            article.calculated_cost = articleCost;

            // Определяем статус
            const currentPrice = article.mp_price || 0;
            if (currentPrice === 0) {
                article.status = 'new';
            } else if (Math.abs(currentPrice - article.calculated_your_price) > 1) {
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
                                ? 'У этого товара нет привязанных артикулов Ozon'
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
                this.openPackModal(btn.dataset.id, btn.dataset.pieces, btn.dataset.qty);
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
            <tr data-mapping-id="${article.mapping_id}" class="${article.status === 'changed' ? 'table-warning' : ''}">
                <td>
                    <input type="checkbox" class="form-check-input article-checkbox"
                           data-id="${article.mapping_id}" ${isSelected ? 'checked' : ''}>
                </td>
                <td>
                    <code>${App.escapeHtml(article.marketplace_offer_id || article.marketplace_product_id || '')}</code>
                </td>
                <td class="text-truncate" style="max-width: 200px;" title="${App.escapeHtml(article.marketplace_name || '')}">
                    ${App.escapeHtml(article.marketplace_name || '-')}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-pack-btn"
                            data-id="${article.mapping_id}"
                            data-qty="${quantityInPack}"
                            data-pieces="${piecesPerSheet}"
                            title="Из листа: ${piecesPerSheet}, В упаковке: ${quantityInPack}">
                        ${piecesPerSheet}/${quantityInPack}
                    </button>
                </td>
                <td class="text-end text-warning fw-bold">
                    ${App.formatPrice(article.calculated_min_price || 0)}
                </td>
                <td class="text-end text-info fw-bold">
                    ${App.formatPrice(article.calculated_your_price || 0)}
                </td>
                <td class="text-end">
                    ${article.mp_price > 0 ? App.formatPrice(article.mp_price) : '<span class="text-muted">-</span>'}
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
     * Получение бейджа статуса
     */
    getStatusBadge(status) {
        switch (status) {
            case 'ok':
                return '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>';
            case 'changed':
                return '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>';
            case 'new':
                return '<span class="badge bg-secondary"><i class="bi bi-question-lg"></i></span>';
            default:
                return '<span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>';
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

        // Кнопка загрузки только остатков — доступна если есть артикулы
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
        const markupYour = parseFloat(document.getElementById('markupYour').value) || 0;

        try {
            await App.fetch('/api/ozon/save-markups', {
                method: 'POST',
                body: {
                    product_id: this.selectedProduct.id,
                    markup_min_price: markupMin,
                    markup_your_price: markupYour
                }
            });

            // Обновляем локальные данные
            this.selectedProduct.markup_min_price = markupMin;
            this.selectedProduct.markup_your_price = markupYour;

            App.showToast('Наценки сохранены', 'success');
        } catch (error) {
            App.showToast('Ошибка сохранения: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузить выбранные артикулы на Ozon
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
     * Загрузить все артикулы товара на Ozon
     */
    async uploadAll() {
        if (this.articles.length === 0) {
            App.showToast('Нет артикулов для загрузки', 'warning');
            return;
        }

        await this.uploadPrices(this.articles);
    },

    /**
     * Загрузка цен и остатков на Ozon
     */
    async uploadPrices(articles) {
        // Проверяем валидность цен
        for (const article of articles) {
            if (article.calculated_min_price >= article.calculated_your_price) {
                App.showToast(
                    `Ошибка: min_price должна быть меньше price для артикула ${article.marketplace_offer_id}`,
                    'danger'
                );
                return;
            }
        }

        // Считаем сколько артикулов с остатками
        const articlesWithStock = articles.filter(a => (a.stock || 0) > 0).length;
        let confirmMsg = `Загрузить цены для ${articles.length} артикулов на Ozon?`;
        if (articlesWithStock > 0) {
            confirmMsg = `Загрузить цены для ${articles.length} артикулов и остатки для ${articlesWithStock} артикулов на Ozon?`;
        }

        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');

        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/ozon/upload-prices-and-stocks', {
                method: 'POST',
                body: {
                    products: articles.map(a => ({
                        product_id: a.marketplace_product_id,
                        offer_id: a.marketplace_offer_id,
                        price: a.calculated_your_price,
                        min_price: a.calculated_min_price,
                        old_price: Math.round(a.calculated_your_price * 1.15), // Старая цена +15%
                        stock: a.stock || 0,
                        mapping_id: a.mapping_id,
                        our_product_id: this.selectedProduct.id
                    }))
                }
            });

            // Формируем сообщение
            let message = data.message || 'Загрузка завершена';
            if (data.prices_updated || data.stocks_updated) {
                message = `Цены: ${data.prices_updated || 0}, остатки: ${data.stocks_updated || 0}`;
            }
            App.showToast(message, data.success ? 'success' : 'warning');

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

        const pricesUpdated = data.prices_updated || data.success_count || 0;
        const stocksUpdated = data.stocks_updated || 0;
        const warehouseId = data.warehouse_id || null;
        const errorCount = data.error_count || 0;
        const errors = data.errors || [];

        content.innerHTML = `
            <div class="col-md-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-currency-exchange text-success display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Цены обновлены</div>
                        <div class="fs-4 fw-bold">${pricesUpdated}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-box-seam text-info display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Остатки обновлены</div>
                        <div class="fs-4 fw-bold">${stocksUpdated}</div>
                        ${warehouseId ? `<div class="text-muted small">Склад: ${warehouseId}</div>` : ''}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-x-circle-fill text-danger display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Ошибок</div>
                        <div class="fs-4 fw-bold">${errorCount}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
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
                            ${errors.map(e => {
                                const errText = typeof e === 'string' ? e : JSON.stringify(e);
                                let icon = 'bi-x-circle';
                                let hint = '';

                                if (errText.includes('частое') || errText.includes('frequently') || errText.includes('30 сек')) {
                                    icon = 'bi-clock';
                                    hint = ' <small class="text-muted">(подождите 30 сек и повторите)</small>';
                                } else if (errText.includes('склад') || errText.includes('warehouse') || errText.includes('Склад')) {
                                    icon = 'bi-building';
                                    hint = ' <small class="text-muted">(проверьте настройки склада в Ozon Seller)</small>';
                                } else if (errText.includes('disabled') || errText.includes('отключен')) {
                                    icon = 'bi-slash-circle';
                                    hint = ' <small class="text-muted">(активируйте склад в Ozon Seller → Настройки → Склады)</small>';
                                }

                                return `<li><i class="${icon} me-1"></i>${App.escapeHtml(errText)}${hint}</li>`;
                            }).join('')}
                        </ul>
                    </div>
                </div>
            ` : ''}
        `;
    },

    /**
     * Открыть модальное окно редактирования параметров упаковки
     */
    openPackModal(mappingId, piecesPerSheet, quantityInPack) {
        document.getElementById('editPackMappingId').value = mappingId;
        document.getElementById('editPiecesPerSheet').value = piecesPerSheet || 1;
        document.getElementById('editQuantityInPack').value = quantityInPack || 1;

        // Пересчитываем превью
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

        // Расчёт себестоимости по формуле
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
            await App.fetch('/api/ozon/update-pack-settings', {
                method: 'POST',
                body: {
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

            // Пересчитываем цены
            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack из названий артикулов
     * Парсит размеры (760x760) и количество (5шт) из названий на Ozon
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
            const result = await App.fetch('/api/ozon/auto-fill-pieces', {
                method: 'POST',
                body: {
                    product_id: this.selectedProduct.id,
                    base_width: 1520,
                    base_height: 1520
                }
            });

            if (result.success) {
                App.showToast(`Обновлено ${result.updated} артикулов`, 'success');
                // Перезагружаем артикулы чтобы увидеть обновлённые данные
                await this.loadArticles(this.selectedProduct.id);
                this.recalculatePrices();
            } else {
                App.showToast('Ошибка: ' + (result.message || 'Неизвестная ошибка'), 'danger');
            }
        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    },

    /**
     * Применить остатки к артикулам
     * @param {boolean} applyToAll - применить ко всем или только к выбранным
     */
    applyBulkStock(applyToAll = false) {
        const stock = parseInt(document.getElementById('bulkStock')?.value) || 0;

        if (applyToAll) {
            // Применяем ко всем артикулам
            this.articles.forEach(article => {
                article.stock = stock;
            });
            document.querySelectorAll('.stock-input').forEach(input => {
                input.value = stock;
            });
            App.showToast(`Остатки ${stock} применены ко всем артикулам`, 'success');
        } else {
            // Применяем только к выбранным
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
     * Загрузка ТОЛЬКО остатков (без цен)
     */
    async uploadStocksOnly() {
        // Получаем выбранные или все артикулы
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

        // Подтверждение
        const confirmed = await App.confirm(
            `Загрузить остатки для ${articlesToUpload.length} артикулов на Ozon?`,
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
                offer_id: article.marketplace_offer_id,
                product_id: article.marketplace_product_id,
                stock: parseInt(article.stock) || 0
            }));

            const result = await App.fetch('/api/ozon/upload-stocks-only', {
                method: 'POST',
                body: { stocks }
            });

            // Показываем результаты
            this.showUploadResults({
                success: result.success,
                prices_updated: 0,
                stocks_updated: result.updated || 0,
                warehouse_id: result.warehouse_id,
                errors: result.errors || [],
                error_count: (result.errors || []).length
            });

            if (result.success) {
                App.showToast(`Остатки загружены: ${result.updated || 0} из ${stocks.length}`, 'success');
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
     * Округление цены по правилам маркетплейса
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
document.addEventListener('DOMContentLoaded', () => OzonCalculator.init());
