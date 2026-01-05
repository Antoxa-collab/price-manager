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
        this.loadSheetSelect();
        this.initTooltips();
        console.log('OzonCalculator.init() completed');
    },

    /**
     * Загрузка списка листов из справочника раскроя в выпадающий список
     */
    async loadSheetSelect() {
        const select = document.getElementById('sheetSelect');
        if (!select) return;

        try {
            const data = await App.fetch('/api/cutting/sheets');
            if (data.success && data.sheets && data.sheets.length > 0) {
                select.innerHTML = data.sheets.map(sheet =>
                    `<option value="${sheet.sheet_width}x${sheet.sheet_height}"
                             data-id="${sheet.id}"
                             data-width="${sheet.sheet_width}"
                             data-height="${sheet.sheet_height}">
                        ${App.escapeHtml(sheet.material_name)} ${sheet.sheet_width}×${sheet.sheet_height}
                    </option>`
                ).join('');
            } else {
                // Если справочник пуст — оставляем дефолтный вариант
                select.innerHTML = `
                    <option value="1520x1520" data-width="1520" data-height="1520">Фанера ФК 1520×1520</option>
                    <option value="2440x1220" data-width="2440" data-height="1220">Фанера ФСФ 2440×1220</option>
                `;
            }
        } catch (e) {
            console.warn('Не удалось загрузить справочник листов:', e);
        }
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
        document.getElementById('markupOldPrice')?.addEventListener('input', () => this.recalculatePrices());

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
     * old_price = your_price × (1 + markup_old / 100)
     */
    recalculatePrices() {
        if (!this.selectedProduct) return;

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const markupYour = parseFloat(document.getElementById('markupYour').value) || 0;
        const markupOldPrice = parseFloat(document.getElementById('markupOldPrice').value) || 15;
        const costPrice = this.selectedProduct.cost_price || 0;

        // Расчёт для базовой единицы (1 шт из листа)
        const unitCost = costPrice;
        const unitMinPrice = this.roundPrice(unitCost * (1 + markupMin / 100));
        const unitYourPrice = this.roundPrice(unitMinPrice * (1 + markupYour / 100));
        const unitOldPrice = this.roundPrice(unitYourPrice * (1 + markupOldPrice / 100));

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitMinPrice);
        document.getElementById('calcYourPrice').textContent = App.formatPrice(unitYourPrice);
        document.getElementById('calcOldPrice').textContent = App.formatPrice(unitOldPrice);

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

            // Цена до скидки = ваша цена × (1 + наценка old_price)
            article.calculated_old_price = this.roundPrice(article.calculated_your_price * (1 + markupOldPrice / 100));

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
     * Обработчик изменения минимальной цены
     * Пересчитывает "Ваша цена" и "До скидки" для конкретной строки
     */
    onMinPriceChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newMinPrice = parseFloat(input.value) || 0;
        const originalPrice = parseFloat(input.dataset.original) || 0;

        // Получаем текущие настройки наценок
        const markupYour = parseFloat(document.getElementById('markupYour')?.value) || 0;
        const markupOldPrice = parseFloat(document.getElementById('markupOldPrice')?.value) || 15;

        // Рассчитываем новые цены по формулам:
        // your_price = min_price × (1 + markupYour/100)
        // old_price = your_price × (1 + markupOldPrice/100)
        const yourPrice = this.roundPrice(newMinPrice * (1 + markupYour / 100));
        const oldPrice = this.roundPrice(yourPrice * (1 + markupOldPrice / 100));

        // Обновляем ячейки в строке
        const yourPriceCell = row.querySelector('.your-price-cell');
        const oldPriceCell = row.querySelector('.old-price-cell');

        if (yourPriceCell) {
            yourPriceCell.textContent = App.formatPrice(yourPrice);
            yourPriceCell.dataset.value = yourPrice;
        }

        if (oldPriceCell) {
            oldPriceCell.textContent = App.formatPrice(oldPrice);
            oldPriceCell.dataset.value = oldPrice;
        }

        // Обновляем данные в массиве articles
        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_min_price = newMinPrice;
            article.calculated_your_price = yourPrice;
            article.calculated_old_price = oldPrice;
            article.custom_min_price = newMinPrice; // Помечаем как изменённую вручную
        }

        // Подсвечиваем изменённую ячейку
        if (Math.abs(newMinPrice - originalPrice) > 0.01) {
            input.classList.add('price-modified');
            row.classList.add('row-modified');
        } else {
            input.classList.remove('price-modified');
            row.classList.remove('row-modified');
        }
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
                    <td colspan="11" class="text-center text-muted py-5">
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

        // Привязываем события изменения минимальной цены
        tbody.querySelectorAll('.min-price-input').forEach(input => {
            input.addEventListener('input', (e) => {
                this.onMinPriceChange(e.target);
            });
        });

        // Клик по строке переключает чекбокс
        tbody.querySelectorAll('tr[data-mapping-id]').forEach(row => {
            row.addEventListener('click', (e) => {
                // Игнорируем клики по интерактивным элементам
                if (e.target.closest('input, button, a, .edit-pack-btn')) return;

                const checkbox = row.querySelector('.article-checkbox');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    // Триггерим событие change для обновления выборки
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        // Кнопка удаления сопоставления
        tbody.querySelectorAll('.delete-mapping-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation(); // Чтобы не срабатывал клик на строку
                const mappingId = btn.dataset.id;
                this.deleteMapping(mappingId);
            });
        });

        this.updateSelectionInfo();
    },

    /**
     * Рендеринг строки артикула
     * ВАЖНО: Не используем яркие цвета строк (table-warning и т.п.)
     * Статус показываем только через индикатор в колонке
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
                    <code>${App.escapeHtml(article.marketplace_offer_id || article.marketplace_product_id || '')}</code>
                </td>
                <td class="text-truncate" style="max-width: 180px;" title="${App.escapeHtml(article.marketplace_name || '')}">
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
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm min-price-input text-end fw-bold text-warning"
                           value="${article.calculated_min_price || 0}"
                           data-id="${article.mapping_id}"
                           data-original="${article.calculated_min_price || 0}"
                           min="0" step="1"
                           style="width: 100px; display: inline-block;">
                </td>
                <td class="text-end fw-bold text-info your-price-cell" data-value="${article.calculated_your_price || 0}">
                    ${App.formatPrice(article.calculated_your_price || 0)}
                </td>
                <td class="text-end text-old-price old-price-cell" data-value="${article.calculated_old_price || 0}">
                    ${App.formatPrice(article.calculated_old_price || 0)}
                </td>
                <td class="text-end">
                    ${article.mp_price > 0 ? App.formatPrice(article.mp_price) : '<span class="text-muted">-</span>'}
                </td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm stock-input text-center"
                           value="${article.stock || 0}"
                           data-id="${article.mapping_id}"
                           min="0"
                           style="width: 70px; display: inline-block;">
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
     * Использует круглые индикаторы вместо ярких бейджей
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
                        old_price: a.calculated_old_price, // Рассчитанная цена до скидки
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
     * Использует справочник раскроя для получения фактического количества кусочков
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

        // Получаем выбранный размер листа из справочника
        const sheetSelect = document.getElementById('sheetSelect');
        const selectedOption = sheetSelect?.selectedOptions[0];
        const baseWidth = parseInt(selectedOption?.dataset?.width) || 1520;
        const baseHeight = parseInt(selectedOption?.dataset?.height) || 1520;

        try {
            const result = await App.fetch('/api/ozon/auto-fill-pieces', {
                method: 'POST',
                body: {
                    product_id: this.selectedProduct.id,
                    base_width: baseWidth,
                    base_height: baseHeight
                }
            });

            if (result.success) {
                const sheetInfo = `(лист ${baseWidth}×${baseHeight})`;
                App.showToast(`Обновлено ${result.updated} артикулов ${sheetInfo}`, 'success');
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
     * Удаление сопоставления (связи с артикулом Ozon)
     */
    async deleteMapping(mappingId) {
        if (!mappingId) {
            App.showToast('Ошибка: не указан ID сопоставления', 'danger');
            return;
        }

        // Находим артикул для отображения в подтверждении
        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        const articleName = article?.marketplace_offer_id || article?.marketplace_name || mappingId;

        const confirmed = await App.confirm(
            `Удалить связь с артикулом "${articleName}"?\n\nАртикул останется в кэше Ozon, но не будет привязан к этому товару.`,
            'Подтверждение удаления'
        );

        if (!confirmed) return;

        try {
            const result = await App.fetch('/api/ozon/delete-mapping', {
                method: 'POST',
                body: { mapping_id: mappingId }
            });

            if (result.success) {
                App.showToast('Сопоставление удалено', 'success');

                // Удаляем из локального массива
                this.articles = this.articles.filter(a => String(a.mapping_id) !== String(mappingId));
                this.selectedArticles.delete(String(mappingId));

                // Перерисовываем таблицу
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

/**
 * Справочник раскроя листов
 * Управляет соотношениями: исходный лист → размер кусочка → количество
 */
const CuttingReference = {
    sheets: [],              // Список листов пользователя
    selectedSheetId: null,   // ID выбранного листа
    selectedSheet: null,     // Данные выбранного листа
    pieces: [],              // Размеры кусочков для выбранного листа

    // Стандартные размеры листов
    sheetSizes: {
        'fanera_fk': { w: 1520, h: 1520 },
        'fanera_fsf': { w: 2440, h: 1220 },
        'fanera_fsf_lam': { w: 2500, h: 1250 },
        'fanera_setch': { w: 2500, h: 1250 },
        'osb': { w: 2500, h: 1250 },
        'mdf': { w: 2800, h: 2070 },
        'lmdf': { w: 2800, h: 2070 },
        'dvp': { w: 2745, h: 1700 }
    },

    /**
     * Инициализация модуля
     */
    init() {
        this.bindEvents();
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        // Переключение на вкладку - загрузка листов
        document.getElementById('cutting-tab')?.addEventListener('shown.bs.tab', () => {
            this.loadSheets();
        });

        // Автоподстановка размеров при выборе типа материала
        document.getElementById('newSheetType')?.addEventListener('change', (e) => {
            this.autoFillSheetSize(e.target.value);
        });

        // Добавить лист
        document.getElementById('btnAddSheet')?.addEventListener('click', () => this.addSheet());

        // Добавить размер кусочка
        document.getElementById('btnAddPiece')?.addEventListener('click', () => this.showAddPieceModal());

        // Загрузить размеры из артикулов
        document.getElementById('btnLoadFromArticles')?.addEventListener('click', () => this.loadFromArticles());

        // Сохранить изменения
        document.getElementById('btnSavePieces')?.addEventListener('click', () => this.savePieces());

        // Сохранить новый размер
        document.getElementById('btnSaveNewPiece')?.addEventListener('click', () => this.saveNewPiece());

        // Пресет размера кусочка
        document.getElementById('addPiecePreset')?.addEventListener('change', (e) => {
            if (e.target.value) {
                const [w, h] = e.target.value.split('x').map(Number);
                document.getElementById('addPieceWidth').value = w;
                document.getElementById('addPieceHeight').value = h;
                this.updateAddPieceCalc();
            }
        });

        // Live preview при вводе размеров
        ['addPieceWidth', 'addPieceHeight'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => this.updateAddPieceCalc());
        });
    },

    /**
     * Автоподстановка размеров листа
     */
    autoFillSheetSize(materialType) {
        const size = this.sheetSizes[materialType];
        if (size) {
            document.getElementById('newSheetWidth').value = size.w;
            document.getElementById('newSheetHeight').value = size.h;
        }
    },

    /**
     * Загрузить список листов
     */
    async loadSheets() {
        try {
            const response = await App.fetch('/api/cutting/sheets');
            if (response.success) {
                this.sheets = response.sheets || [];
                this.renderSheetsList();
            }
        } catch (e) {
            console.error('Ошибка загрузки листов:', e);
            App.showToast('Ошибка загрузки листов', 'danger');
        }
    },

    /**
     * Отрисовать список листов
     */
    renderSheetsList() {
        const container = document.getElementById('sheetsList');
        if (!container) return;

        if (this.sheets.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-3">Нет листов. Добавьте первый!</div>';
            return;
        }

        container.innerHTML = this.sheets.map(sheet => `
            <a href="#" class="list-group-item list-group-item-action bg-dark border-secondary ${sheet.id == this.selectedSheetId ? 'active' : ''}"
               data-sheet-id="${sheet.id}" onclick="CuttingReference.selectSheet(${sheet.id}); return false;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${this.escapeHtml(sheet.material_name)}</strong><br>
                        <small class="text-muted">${sheet.sheet_width}×${sheet.sheet_height} мм</small>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="event.preventDefault(); event.stopPropagation(); CuttingReference.deleteSheet(${sheet.id});">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </a>
        `).join('');
    },

    /**
     * Добавить новый лист
     */
    async addSheet() {
        const type = document.getElementById('newSheetType').value;
        const typeSelect = document.getElementById('newSheetType');
        const name = typeSelect.options[typeSelect.selectedIndex].text;
        const width = parseInt(document.getElementById('newSheetWidth').value) || 0;
        const height = parseInt(document.getElementById('newSheetHeight').value) || 0;

        if (!width || !height) {
            App.showToast('Укажите размеры листа', 'warning');
            return;
        }

        try {
            const response = await App.fetch('/api/cutting/sheets', {
                method: 'POST',
                body: new URLSearchParams({
                    material_type: type,
                    material_name: name,
                    sheet_width: width,
                    sheet_height: height
                })
            });

            if (response.success) {
                App.showToast('Лист добавлен', 'success');
                this.loadSheets();
            } else {
                App.showToast(response.message || 'Ошибка', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка добавления: ' + e.message, 'danger');
        }
    },

    /**
     * Удалить лист
     */
    async deleteSheet(sheetId) {
        if (!confirm('Удалить этот лист и все его размеры?')) return;

        try {
            const response = await App.fetch('/api/cutting/sheets/delete', {
                method: 'POST',
                body: new URLSearchParams({ sheet_id: sheetId })
            });

            if (response.success) {
                App.showToast('Лист удалён', 'success');
                if (this.selectedSheetId == sheetId) {
                    this.selectedSheetId = null;
                    this.selectedSheet = null;
                    this.pieces = [];
                    this.renderPiecesTable();
                    document.getElementById('selectedSheetName').textContent = 'выберите лист';
                    document.getElementById('btnAddPiece').disabled = true;
                    document.getElementById('btnLoadFromArticles').disabled = true;
                    document.getElementById('btnSavePieces').disabled = true;
                }
                this.loadSheets();
            } else {
                App.showToast(response.message || 'Ошибка', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка удаления: ' + e.message, 'danger');
        }
    },

    /**
     * Выбрать лист
     */
    async selectSheet(sheetId) {
        this.selectedSheetId = sheetId;
        this.renderSheetsList();

        // Активируем кнопки
        document.getElementById('btnAddPiece').disabled = false;
        document.getElementById('btnLoadFromArticles').disabled = false;
        document.getElementById('btnSavePieces').disabled = false;

        // Загружаем раскрой
        try {
            const response = await App.fetch(`/api/cutting/pieces?sheet_id=${sheetId}`);
            if (response.success) {
                this.selectedSheet = response.sheet;
                this.pieces = response.pieces || [];
                document.getElementById('selectedSheetName').textContent =
                    `${response.sheet.material_name} ${response.sheet.sheet_width}×${response.sheet.sheet_height}`;
                this.renderPiecesTable();
            }
        } catch (e) {
            console.error('Ошибка загрузки раскроя:', e);
            App.showToast('Ошибка загрузки раскроя', 'danger');
        }
    },

    /**
     * Отрисовать таблицу раскроя
     */
    renderPiecesTable() {
        const tbody = document.getElementById('piecesTableBody');
        if (!tbody) return;

        if (this.pieces.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Нет размеров. Добавьте вручную или загрузите из артикулов.
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = this.pieces.map(piece => {
            const diff = piece.actual_qty != piece.calculated_qty;
            // Сохраняем данные piece в data-атрибутах для редактирования
            return `
                <tr data-piece-id="${piece.id}"
                    data-piece-name="${this.escapeHtml(piece.piece_name || '')}"
                    data-piece-width="${piece.piece_width}"
                    data-piece-height="${piece.piece_height}"
                    data-actual-qty="${piece.actual_qty}">
                    <td>${this.escapeHtml(piece.piece_name || '-')}</td>
                    <td class="text-center">${piece.piece_width}×${piece.piece_height}</td>
                    <td class="text-center text-muted">${piece.calculated_qty} шт</td>
                    <td class="text-center">
                        <input type="number" class="form-control form-control-sm piece-actual-qty text-center"
                               value="${piece.actual_qty}" min="1" style="width: 80px; display: inline-block;">
                        ${diff ? '<i class="bi bi-exclamation-triangle text-warning ms-1" title="Отличается от авто-расчёта"></i>' : ''}
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-secondary btn-sm me-1"
                                onclick="CuttingReference.editPiece(${piece.id})" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="CuttingReference.deletePiece(${piece.id})" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    },

    /**
     * Показать модальное окно добавления размера
     */
    showAddPieceModal() {
        if (!this.selectedSheet) {
            App.showToast('Сначала выберите лист', 'warning');
            return;
        }

        // Очищаем поля (qty пустой - рассчитается автоматически)
        document.getElementById('addPieceName').value = '';
        document.getElementById('addPiecePreset').value = '';
        document.getElementById('addPieceWidth').value = '';
        document.getElementById('addPieceHeight').value = '';
        document.getElementById('addPieceQty').value = '';
        document.getElementById('addPieceCalc').textContent = '-';

        const modal = new bootstrap.Modal(document.getElementById('addPieceModal'));
        modal.show();
    },

    /**
     * Обновить авто-расчёт в модалке
     */
    updateAddPieceCalc() {
        if (!this.selectedSheet) return;

        const pieceW = parseInt(document.getElementById('addPieceWidth')?.value) || 0;
        const pieceH = parseInt(document.getElementById('addPieceHeight')?.value) || 0;

        if (pieceW > 0 && pieceH > 0) {
            const calc = this.calculatePieces(
                this.selectedSheet.sheet_width, this.selectedSheet.sheet_height,
                pieceW, pieceH
            );
            document.getElementById('addPieceCalc').textContent = calc;
            document.getElementById('addPieceQty').value = calc;
        } else {
            document.getElementById('addPieceCalc').textContent = '-';
        }
    },

    /**
     * Сохранить новый размер
     */
    async saveNewPiece() {
        const nameEl = document.getElementById('addPieceName');
        const widthEl = document.getElementById('addPieceWidth');
        const heightEl = document.getElementById('addPieceHeight');
        const qtyEl = document.getElementById('addPieceQty');

        const name = nameEl?.value?.trim() || '';
        const width = parseInt(widthEl?.value) || 0;
        const height = parseInt(heightEl?.value) || 0;
        let qty = parseInt(qtyEl?.value) || 0;

        console.log('saveNewPiece:', { name, width, height, qty, sheetId: this.selectedSheetId });

        if (!this.selectedSheetId) {
            App.showToast('Сначала выберите лист', 'warning');
            return;
        }

        if (!width || width <= 0) {
            App.showToast('Укажите ширину кусочка', 'warning');
            widthEl?.focus();
            return;
        }

        if (!height || height <= 0) {
            App.showToast('Укажите высоту кусочка', 'warning');
            heightEl?.focus();
            return;
        }

        // Если qty не указан — рассчитываем автоматически
        if (qty <= 0 && this.selectedSheet) {
            qty = this.calculatePieces(
                this.selectedSheet.sheet_width, this.selectedSheet.sheet_height,
                width, height
            );
        }

        // Минимум 1
        if (qty < 1) qty = 1;

        const pieceName = name || `${width}×${height}`;

        try {
            const response = await App.fetch('/api/cutting/pieces', {
                method: 'POST',
                body: new URLSearchParams({
                    sheet_id: this.selectedSheetId,
                    piece_name: pieceName,
                    piece_width: width,
                    piece_height: height,
                    actual_qty: qty
                })
            });

            if (response.success) {
                App.showToast('Размер добавлен', 'success');
                bootstrap.Modal.getInstance(document.getElementById('addPieceModal'))?.hide();
                this.selectSheet(this.selectedSheetId);
            } else {
                App.showToast(response.message || 'Ошибка', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка добавления: ' + e.message, 'danger');
        }
    },

    /**
     * Удалить размер
     */
    async deletePiece(pieceId) {
        if (!confirm('Удалить этот размер?')) return;

        try {
            const response = await App.fetch('/api/cutting/pieces/delete', {
                method: 'POST',
                body: new URLSearchParams({ piece_id: pieceId })
            });

            if (response.success) {
                App.showToast('Размер удалён', 'success');
                this.selectSheet(this.selectedSheetId);
            } else {
                App.showToast(response.message || 'Ошибка', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка удаления: ' + e.message, 'danger');
        }
    },

    /**
     * Загрузить размеры из артикулов Ozon
     */
    async loadFromArticles() {
        if (!this.selectedSheetId) {
            App.showToast('Сначала выберите лист слева', 'warning');
            return;
        }

        const btn = document.getElementById('btnLoadFromArticles');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            const response = await App.fetch('/api/cutting/load-from-articles', {
                method: 'POST',
                body: new URLSearchParams({ sheet_id: String(this.selectedSheetId) })
            });

            if (response.success) {
                App.showToast(response.message || 'Размеры загружены', 'success');
                this.selectSheet(this.selectedSheetId);
            } else {
                App.showToast(response.message || 'Ошибка загрузки', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка загрузки: ' + e.message, 'danger');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    },

    /**
     * Сохранить изменения в таблице
     */
    async savePieces() {
        const rows = document.querySelectorAll('#piecesTableBody tr[data-piece-id]');
        const pieces = [];

        rows.forEach(row => {
            const input = row.querySelector('.piece-actual-qty');
            if (input) {
                pieces.push({
                    id: row.dataset.pieceId,
                    actual_qty: parseInt(input.value) || 1
                });
            }
        });

        if (pieces.length === 0) {
            App.showToast('Нечего сохранять', 'info');
            return;
        }

        try {
            const response = await App.fetch('/api/cutting/pieces/bulk', {
                method: 'POST',
                body: new URLSearchParams({ pieces: JSON.stringify(pieces) })
            });

            if (response.success) {
                App.showToast(`Сохранено: ${response.updated}`, 'success');
                this.selectSheet(this.selectedSheetId);
            } else {
                App.showToast(response.message || 'Ошибка', 'danger');
            }
        } catch (e) {
            App.showToast('Ошибка сохранения: ' + e.message, 'danger');
        }
    },

    /**
     * Открыть модальное окно редактирования размера
     */
    editPiece(pieceId) {
        // Находим строку с данными
        const row = document.querySelector(`tr[data-piece-id="${pieceId}"]`);
        if (!row) {
            App.showToast('Размер не найден', 'danger');
            return;
        }

        // Читаем данные из data-атрибутов
        document.getElementById('editPieceId').value = pieceId;
        document.getElementById('editPieceName').value = row.dataset.pieceName || '';
        document.getElementById('editPieceWidth').value = row.dataset.pieceWidth || '';
        document.getElementById('editPieceHeight').value = row.dataset.pieceHeight || '';
        document.getElementById('editPieceQty').value = row.dataset.actualQty || '';

        const modal = new bootstrap.Modal(document.getElementById('editPieceModal'));
        modal.show();
    },

    /**
     * Сохранить изменения размера
     */
    async updatePiece() {
        const pieceId = document.getElementById('editPieceId').value;
        const name = document.getElementById('editPieceName').value.trim();
        const width = parseInt(document.getElementById('editPieceWidth').value) || 0;
        const height = parseInt(document.getElementById('editPieceHeight').value) || 0;
        let qty = parseInt(document.getElementById('editPieceQty').value) || 0;

        if (!pieceId) {
            App.showToast('Ошибка: не указан ID', 'danger');
            return;
        }

        if (!width || width <= 0) {
            App.showToast('Укажите ширину кусочка', 'warning');
            return;
        }

        if (!height || height <= 0) {
            App.showToast('Укажите высоту кусочка', 'warning');
            return;
        }

        // Если qty не указан — рассчитываем
        if (qty <= 0 && this.selectedSheet) {
            qty = this.calculatePieces(
                this.selectedSheet.sheet_width, this.selectedSheet.sheet_height,
                width, height
            );
        }
        if (qty < 1) qty = 1;

        try {
            const response = await App.fetch('/api/cutting/pieces/update', {
                method: 'POST',
                body: new URLSearchParams({
                    piece_id: pieceId,
                    piece_name: name || `${width}×${height}`,
                    piece_width: width,
                    piece_height: height,
                    actual_qty: qty
                })
            });

            if (response.success) {
                App.showToast('Размер обновлён', 'success');
                bootstrap.Modal.getInstance(document.getElementById('editPieceModal'))?.hide();
                this.selectSheet(this.selectedSheetId); // Перезагрузить таблицу
            } else {
                App.showToast(response.message || 'Ошибка обновления', 'danger');
            }
        } catch (e) {
            console.error('updatePiece error:', e);
            App.showToast('Ошибка обновления: ' + e.message, 'danger');
        }
    },

    /**
     * Рассчитать количество кусочков с учётом поворота
     */
    calculatePieces(sheetW, sheetH, pieceW, pieceH) {
        // Минимальный размер кусочка — 50мм (защита от нереалистичных значений)
        if (!pieceW || !pieceH || pieceW < 50 || pieceH < 50) {
            return 1;
        }
        // Вариант 1: стандартная ориентация
        const total1 = Math.floor(sheetW / pieceW) * Math.floor(sheetH / pieceH);
        // Вариант 2: повёрнутая ориентация
        const total2 = Math.floor(sheetW / pieceH) * Math.floor(sheetH / pieceW);
        // Максимум 10000 (защита от переполнения в БД)
        return Math.min(10000, Math.max(1, Math.max(total1, total2)));
    },

    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    OzonCalculator.init();
    CuttingReference.init();
});
