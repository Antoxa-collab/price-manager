/**
 * Price Manager - Калькулятор цен Яндекс.Маркет
 * Расчёт и загрузка цен на маркетплейс
 */

const YMCalculator = {
    // Данные
    products: [],           // Товары с сопоставлениями
    articles: [],           // Артикулы выбранного товара
    selectedProduct: null,  // Выбранный товар
    selectedArticles: new Set(), // Выбранные артикулы для загрузки
    syncStats: null,        // Статистика синхронизации

    /**
     * Инициализация модуля
     */
    init() {
        console.log('YMCalculator.init() started');
        this.bindEvents();
        this.loadProducts();
        this.loadSyncStats();
        this.loadSheetSelect();
        this.loadSettings();
        this.initTooltips();
        console.log('YMCalculator.init() completed');
    },

    /**
     * Загрузка сохранённых настроек калькулятора (наценки)
     */
    async loadSettings() {
        try {
            const data = await App.fetch('/api/calculator/settings?marketplace=yandex');
            if (data.success && data.settings) {
                const markupMin = document.getElementById('markupMin');
                const ymDiscount = document.getElementById('ymDiscount');

                if (data.settings.markup_min > 0 && markupMin) {
                    markupMin.value = data.settings.markup_min;
                }
                if (data.settings.discount > 0 && ymDiscount) {
                    ymDiscount.value = data.settings.discount;
                }

                console.log('YMCalculator: settings loaded', data.settings);
            }
        } catch (e) {
            console.warn('Не удалось загрузить настройки калькулятора:', e);
        }
    },

    /**
     * Сохранение настроек калькулятора (наценки)
     */
    async saveSettings() {
        const markupMin = parseFloat(document.getElementById('markupMin')?.value) || 0;
        const discount = parseFloat(document.getElementById('ymDiscount')?.value) || 0;

        try {
            await App.fetch('/api/calculator/settings', {
                method: 'POST',
                body: new URLSearchParams({
                    marketplace: 'yandex',
                    markup_min: markupMin,
                    markup_extra: 0,
                    discount: discount
                })
            });
            console.log('YMCalculator: settings saved');
        } catch (e) {
            console.warn('Не удалось сохранить настройки калькулятора:', e);
        }
    },

    /**
     * Загрузка списка листов из справочника раскроя
     */
    async loadSheetSelect() {
        const select = document.getElementById('ymSheetSelect');
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
        document.getElementById('markupMin')?.addEventListener('input', () => this.recalculatePrices(true));
        document.getElementById('ymDiscount')?.addEventListener('input', () => this.recalculatePrices());

        // Кнопка пересчёта
        document.getElementById('recalculateBtn')?.addEventListener('click', () => this.recalculatePrices());

        // Сохранение наценок
        document.getElementById('saveMarkupsBtn')?.addEventListener('click', () => this.saveMarkups());

        // Выбор всех артикулов
        document.getElementById('selectAllArticles')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                this.selectAllArticles();
            } else {
                this.clearArticleSelection();
            }
        });

        // Аналогично для checkAll (второй чекбокс)
        document.getElementById('checkAll')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                this.selectAllArticles();
            } else {
                this.clearArticleSelection();
            }
        });

        // Загрузка цен и остатков
        document.getElementById('uploadPricesBtn')?.addEventListener('click', () => this.uploadPrices());
        document.getElementById('uploadStocksBtn')?.addEventListener('click', () => this.uploadStocks());

        // Редактирование параметров артикула
        document.getElementById('saveArticleBtn')?.addEventListener('click', () => this.saveArticleSettings());

        // Автозаполнение из справочника раскроя
        document.getElementById('ymAutoFillBtn')?.addEventListener('click', () => this.autoFillPieces());

        // Управление остатками
        document.getElementById('applyBulkStockBtn')?.addEventListener('click', () => this.applyBulkStock());
        document.getElementById('zeroStocksBtn')?.addEventListener('click', () => this.zeroAllStocks());

        // Синхронизация с ЯМ
        document.getElementById('syncYmBtn')?.addEventListener('click', () => this.syncWithYM());
    },

    /**
     * Загрузка статистики синхронизации
     */
    async loadSyncStats() {
        try {
            const data = await App.fetch('/api/yandex/products?limit=1');
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
     * Загрузка списка НАШИХ товаров с сопоставлениями ЯМ
     */
    async loadProducts() {
        console.log('loadProducts() called');
        try {
            const data = await App.fetch('/api/yandex/products-with-mappings');
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

        select.innerHTML = '<option value="">Выберите товар...</option>';

        if (this.products.length === 0) {
            console.log('No products to display');
            select.innerHTML += '<option value="" disabled>Нет товаров с сопоставлениями</option>';
            document.getElementById('productInfo').innerHTML =
                'Нет товаров с привязанными артикулами. <a href="/yandex/mapping">Создайте сопоставления</a>';
            return;
        }

        this.products.forEach((product, index) => {
            const mappingCount = product.mapping_count || 0;
            const option = document.createElement('option');
            option.value = product.id;
            const productName = product.name || 'Без названия';
            option.textContent = `${productName} (${mappingCount} артикулов ЯМ)`;
            option.dataset.costPrice = product.cost_price || 0;
            option.dataset.basePrice = product.base_price || 0;
            option.dataset.markupMin = product.markup_min_price || 0;
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

        // Загружаем артикулы товара
        await this.loadArticles(productId);

        // Пересчитываем цены
        this.recalculatePrices();

        // Активируем кнопки
        this.updateButtons();
    },

    /**
     * Загрузка артикулов ЯМ для выбранного НАШЕГО товара
     */
    async loadArticles(productId) {
        try {
            const data = await App.fetch(`/api/yandex/product-articles?product_id=${productId}`);
            this.articles = data.mappings || [];
            this.selectedArticles.clear();

            // Восстанавливаем сохранённые остатки из localStorage
            const savedStocks = JSON.parse(localStorage.getItem('ym_stocks_' + productId) || '{}');
            this.articles.forEach(a => {
                if (savedStocks[a.mapping_id] !== undefined) {
                    a.stock = savedStocks[a.mapping_id];
                }
            });
            console.log('[loadArticles] Восстановлено остатков:', Object.keys(savedStocks).length);

            this.renderArticlesTable();
        } catch (error) {
            App.showToast('Ошибка загрузки артикулов: ' + error.message, 'danger');
        }
    },

    /**
     * Пересчёт цен
     * Формула для ЯМ:
     * cost = (cost_price / pieces_per_sheet) × quantity_in_pack
     * price = cost × (1 + markup_min / 100)
     * old_price = price / (1 - discount / 100) — зачёркнутая цена
     *
     * @param {boolean} forceRecalc - Принудительно пересчитать все артикулы
     */
    recalculatePrices(forceRecalc = false) {
        if (!this.selectedProduct) return;

        if (forceRecalc) {
            console.log('[recalculatePrices] FORCE RECALC');
        }

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const globalDiscount = parseFloat(document.getElementById('ymDiscount')?.value) || 0;
        const costPrice = this.selectedProduct.cost_price || 0;
        const basePrice = this.selectedProduct.base_price || costPrice;

        // Расчёт для базовой единицы (1 шт из листа)
        const unitCost = costPrice;
        const unitMarkup = this.roundPrice(unitCost * (1 + markupMin / 100));
        const unitOldPrice = globalDiscount > 0 ? this.roundPrice(unitMarkup / (1 - globalDiscount / 100)) : 0;

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcBasePrice').textContent = App.formatPrice(basePrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitMarkup);
        document.getElementById('calcFinalPrice').textContent = App.formatPrice(unitMarkup);

        // Пересчитываем цены для каждого артикула
        this.articles.forEach(article => {
            const piecesPerSheet = article.pieces_per_sheet || 1;
            const quantityInPack = article.quantity_in_pack || 1;
            const articleDiscount = article.custom_discount || globalDiscount;

            // Себестоимость: (закупка / кол-во из листа) × кол-во в упаковке
            const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

            // Цена с наценкой
            if (!forceRecalc && article.has_custom_price && article.custom_price > 0) {
                article.calculated_price = article.custom_price;
            } else {
                const rawPrice = articleCost * (1 + markupMin / 100);
                article.calculated_price = this.roundPrice(rawPrice);
                if (forceRecalc && article.has_custom_price) {
                    article.has_custom_price = false;
                }
            }

            // Зачёркнутая цена (old_price) если есть скидка
            if (articleDiscount > 0 && articleDiscount < 100) {
                article.old_price = this.roundPrice(article.calculated_price / (1 - articleDiscount / 100));
            } else {
                article.old_price = 0;
            }

            // Скидка артикула
            article.calculated_discount = articleDiscount;

            // Сохраняем себестоимость для отображения
            article.calculated_cost = articleCost;

            // Определяем статус
            const currentPrice = article.ym_price || 0;
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
     * Обработчик изменения цены в строке
     */
    onPriceChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newPrice = parseFloat(input.value) || 0;
        const originalPrice = parseFloat(input.dataset.original) || 0;

        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_price = newPrice;
            article.custom_price = newPrice;
            article.has_custom_price = true;

            // Пересчёт зачёркнутой цены
            const discount = article.calculated_discount || 0;
            if (discount > 0 && discount < 100) {
                article.old_price = this.roundPrice(newPrice / (1 - discount / 100));
                const oldPriceSpan = row.querySelector('.old-price');
                if (oldPriceSpan) {
                    oldPriceSpan.textContent = App.formatPrice(article.old_price);
                }
            }
        }

        // Подсвечиваем изменённую ячейку
        if (Math.abs(newPrice - originalPrice) > 0.01) {
            input.classList.add('price-modified');
            row.classList.add('row-modified');
        } else {
            input.classList.remove('price-modified');
            row.classList.remove('row-modified');
        }
    },

    /**
     * Обработчик изменения скидки в строке
     */
    onDiscountChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newDiscount = parseFloat(input.value) || 0;

        if (newDiscount < 0 || newDiscount >= 100) {
            App.showToast('Скидка должна быть от 0 до 99%', 'warning');
            input.value = Math.min(99, Math.max(0, newDiscount));
            return;
        }

        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_discount = newDiscount;
            article.custom_discount = newDiscount;

            // Пересчёт зачёркнутой цены
            const price = article.calculated_price || 0;
            if (newDiscount > 0 && newDiscount < 100) {
                article.old_price = this.roundPrice(price / (1 - newDiscount / 100));
            } else {
                article.old_price = 0;
            }

            const oldPriceSpan = row.querySelector('.old-price');
            if (oldPriceSpan) {
                oldPriceSpan.textContent = article.old_price > 0 ? App.formatPrice(article.old_price) : '-';
            }
        }
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
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-inbox display-4"></i>
                        <p class="mt-3">
                            ${this.selectedProduct
                                ? 'У этого товара нет привязанных артикулов Яндекс.Маркет'
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

        tbody.querySelectorAll('.edit-article-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.openArticleModal(
                    btn.dataset.id,
                    btn.dataset.qty,
                    btn.dataset.pieces,
                    btn.dataset.discount
                );
            });
        });

        // Привязываем события изменения остатков
        tbody.querySelectorAll('.stock-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const mappingId = e.target.dataset.id;
                const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
                if (article) {
                    article.stock = parseInt(e.target.value) || 0;
                    this.saveStocksToStorage();
                }
            });
        });

        // Привязываем события изменения цены
        tbody.querySelectorAll('.price-input').forEach(input => {
            input.addEventListener('input', (e) => {
                this.onPriceChange(e.target);
            });
        });

        // Привязываем события изменения скидки
        tbody.querySelectorAll('.discount-input').forEach(input => {
            input.addEventListener('input', (e) => {
                this.onDiscountChange(e.target);
            });
        });

        // Клик по строке переключает чекбокс
        tbody.querySelectorAll('tr[data-mapping-id]').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('input, button, a, .edit-article-btn')) return;

                const checkbox = row.querySelector('.article-checkbox');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
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
        const piecesPerSheet = article.pieces_per_sheet || 1;
        const quantityInPack = article.quantity_in_pack || 1;
        const price = article.calculated_price || 0;
        const oldPrice = article.old_price || 0;
        const discount = article.calculated_discount || 0;
        const cost = article.calculated_cost || 0;

        return `
            <tr data-mapping-id="${article.mapping_id}" data-offer-id="${article.offer_id || ''}">
                <td>
                    <input type="checkbox" class="form-check-input article-checkbox"
                           data-id="${article.mapping_id}" ${isSelected ? 'checked' : ''}>
                </td>
                <td>
                    <code>${App.escapeHtml(article.offer_id || '')}</code>
                    <div class="small text-muted text-truncate" style="max-width: 200px;" title="${App.escapeHtml(article.ym_name || '')}">
                        ${App.escapeHtml(article.ym_name || '-')}
                    </div>
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary edit-article-btn"
                            data-id="${article.mapping_id}"
                            data-qty="${quantityInPack}"
                            data-pieces="${piecesPerSheet}"
                            data-discount="${discount}"
                            title="Из листа: ${piecesPerSheet}, В упаковке: ${quantityInPack}">
                        ${quantityInPack} шт
                    </button>
                </td>
                <td class="text-end">${piecesPerSheet}</td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm discount-input text-end"
                           value="${discount}"
                           data-id="${article.mapping_id}"
                           min="0" max="99" step="1"
                           style="width: 55px; display: inline-block;">
                    <span class="text-muted">%</span>
                </td>
                <td class="text-end text-muted">
                    ${App.formatPrice(cost)}
                </td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm price-input text-end fw-bold text-warning ${article.has_custom_price ? 'price-modified' : ''}"
                           value="${price}"
                           data-id="${article.mapping_id}"
                           data-original="${price}"
                           min="0" step="1"
                           style="width: 80px; display: inline-block;">
                </td>
                <td class="text-end">
                    <span class="old-price ${oldPrice > 0 ? 'text-decoration-line-through text-muted' : ''}">
                        ${oldPrice > 0 ? App.formatPrice(oldPrice) : '-'}
                    </span>
                </td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm stock-input text-center"
                           value="${article.stock || 0}"
                           data-id="${article.mapping_id}"
                           min="0"
                           style="width: 70px; display: inline-block;">
                </td>
            </tr>
        `;
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
        const selectAll = document.getElementById('selectAllArticles');
        if (selectAll) selectAll.checked = false;
        const checkAll = document.getElementById('checkAll');
        if (checkAll) checkAll.checked = false;
    },

    /**
     * Обновление информации о выборе
     */
    updateSelectionInfo() {
        const count = this.selectedArticles.size;
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

        const ymAutoFillBtn = document.getElementById('ymAutoFillBtn');
        if (ymAutoFillBtn) {
            ymAutoFillBtn.disabled = !hasArticles;
        }

        const uploadPricesBtn = document.getElementById('uploadPricesBtn');
        if (uploadPricesBtn) {
            uploadPricesBtn.disabled = !hasSelected;
        }

        const uploadStocksBtn = document.getElementById('uploadStocksBtn');
        if (uploadStocksBtn) {
            uploadStocksBtn.disabled = !hasSelected;
        }
    },

    /**
     * Сохранение наценок для товара
     */
    async saveMarkups() {
        if (!this.selectedProduct) return;

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;

        try {
            await App.fetch('/api/yandex/mapping', {
                method: 'POST',
                body: {
                    action: 'save_markups',
                    product_id: this.selectedProduct.id,
                    markup_min_price: markupMin
                }
            });

            this.selectedProduct.markup_min_price = markupMin;

            // Сохраняем настройки калькулятора
            await this.saveSettings();

            App.showToast('Настройки сохранены', 'success');
        } catch (error) {
            App.showToast('Ошибка сохранения: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка цен на Яндекс.Маркет
     */
    async uploadPrices() {
        const articlesToUpload = this.articles.filter(a =>
            this.selectedArticles.has(String(a.mapping_id))
        );

        if (articlesToUpload.length === 0) {
            App.showToast('Выберите артикулы для загрузки', 'warning');
            return;
        }

        const confirmMsg = `Загрузить цены для ${articlesToUpload.length} артикулов на Яндекс.Маркет?`;
        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');
        if (!confirmed) return;

        try {
            // Формируем данные для отправки
            const pricesData = articlesToUpload.map(a => ({
                offerId: a.offer_id,
                price: Math.round(a.calculated_price),
                oldPrice: a.old_price > 0 ? Math.round(a.old_price) : null
            })).filter(item => item.price > 0);

            if (pricesData.length === 0) {
                App.showToast('Нет товаров с валидными ценами для загрузки', 'warning');
                return;
            }

            console.log('[uploadPrices] Отправляем на ЯМ:', pricesData);

            const data = await App.fetch('/api/yandex/upload-prices', {
                method: 'POST',
                body: { prices: pricesData }
            });

            console.log('[uploadPrices] Ответ сервера:', data);

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
     * Загрузка остатков на Яндекс.Маркет
     */
    async uploadStocks() {
        const articlesToUpload = this.articles.filter(a =>
            this.selectedArticles.has(String(a.mapping_id)) && a.stock !== undefined
        );

        if (articlesToUpload.length === 0) {
            App.showToast('Выберите артикулы с остатками для загрузки', 'warning');
            return;
        }

        const confirmMsg = `Загрузить остатки для ${articlesToUpload.length} артикулов на Яндекс.Маркет?`;
        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');
        if (!confirmed) return;

        try {
            // Формируем данные для отправки
            const stocksData = articlesToUpload.map(a => ({
                offerId: a.offer_id,
                stock: parseInt(a.stock) || 0
            }));

            console.log('[uploadStocks] Отправляем на ЯМ:', stocksData);

            const data = await App.fetch('/api/yandex/upload-stocks', {
                method: 'POST',
                body: { stocks: stocksData }
            });

            console.log('[uploadStocks] Ответ сервера:', data);

            App.showToast(data.message || 'Остатки загружены', data.success ? 'success' : 'warning');

            // Показываем результаты
            this.showUploadResults(data);

        } catch (error) {
            App.showToast('Ошибка загрузки: ' + error.message, 'danger');
        }
    },

    /**
     * Показать результаты загрузки
     */
    showUploadResults(data) {
        const card = document.getElementById('uploadResultsCard');
        const body = document.getElementById('uploadResultsBody');

        if (!card || !body) return;

        card.classList.remove('d-none');

        let html = '';

        if (data.success) {
            html += `<div class="alert alert-success mb-2">
                <i class="bi bi-check-circle me-2"></i>
                ${data.message || 'Операция выполнена успешно'}
            </div>`;
        }

        if (data.updated) {
            html += `<p>Обновлено: <strong>${data.updated}</strong> артикулов</p>`;
        }

        if (data.errors && data.errors.length > 0) {
            html += `<div class="alert alert-danger">
                <strong>Ошибки:</strong>
                <ul class="mb-0">
                    ${data.errors.map(e => `<li>${App.escapeHtml(e.offerId || e.offer_id || '')}: ${App.escapeHtml(e.error || e.message || 'Неизвестная ошибка')}</li>`).join('')}
                </ul>
            </div>`;
        }

        body.innerHTML = html;
    },

    /**
     * Открытие модального окна редактирования артикула
     */
    openArticleModal(mappingId, qty, pieces, discount) {
        document.getElementById('editMappingId').value = mappingId;
        document.getElementById('editQuantityInPack').value = qty || 1;
        document.getElementById('editPiecesPerSheet').value = pieces || 1;
        document.getElementById('editCustomDiscount').value = discount || 0;

        const modal = new bootstrap.Modal(document.getElementById('editArticleModal'));
        modal.show();
    },

    /**
     * Сохранение настроек артикула
     */
    async saveArticleSettings() {
        const mappingId = document.getElementById('editMappingId').value;
        const quantityInPack = parseInt(document.getElementById('editQuantityInPack').value) || 1;
        const piecesPerSheet = parseInt(document.getElementById('editPiecesPerSheet').value) || 1;
        const customDiscount = parseFloat(document.getElementById('editCustomDiscount').value) || 0;

        try {
            await App.fetch('/api/yandex/mapping', {
                method: 'POST',
                body: {
                    action: 'update_pack',
                    mapping_id: mappingId,
                    quantity_in_pack: quantityInPack,
                    pieces_per_sheet: piecesPerSheet
                }
            });

            // Обновляем скидку отдельно
            await App.fetch('/api/yandex/mapping/update-discount', {
                method: 'POST',
                body: {
                    mapping_id: mappingId,
                    discount: customDiscount
                }
            });

            bootstrap.Modal.getInstance(document.getElementById('editArticleModal'))?.hide();
            App.showToast('Сохранено', 'success');

            // Перезагружаем артикулы
            await this.loadArticles(this.selectedProduct.id);
            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Автозаполнение из справочника раскроя
     */
    async autoFillPieces() {
        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        const select = document.getElementById('ymSheetSelect');
        const selectedOption = select?.options[select.selectedIndex];
        if (!selectedOption) {
            App.showToast('Выберите размер листа', 'warning');
            return;
        }

        const baseWidth = parseInt(selectedOption.dataset.width) || 1520;
        const baseHeight = parseInt(selectedOption.dataset.height) || 1520;

        try {
            const data = await App.fetch('/api/yandex/auto-fill-pieces', {
                method: 'POST',
                body: {
                    product_id: this.selectedProduct.id,
                    base_width: baseWidth,
                    base_height: baseHeight
                }
            });

            App.showToast(data.message || 'Раскрой заполнен', 'success');

            // Перезагружаем артикулы
            await this.loadArticles(this.selectedProduct.id);
            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Применить остаток к выбранным артикулам
     */
    applyBulkStock() {
        const stockValue = parseInt(document.getElementById('bulkStock')?.value) || 0;

        if (this.selectedArticles.size === 0) {
            // Если ничего не выбрано - применяем ко всем
            this.articles.forEach(a => {
                a.stock = stockValue;
            });
            App.showToast(`Остаток ${stockValue} применён ко всем артикулам`, 'success');
        } else {
            this.articles.forEach(a => {
                if (this.selectedArticles.has(String(a.mapping_id))) {
                    a.stock = stockValue;
                }
            });
            App.showToast(`Остаток ${stockValue} применён к ${this.selectedArticles.size} артикулам`, 'success');
        }

        this.saveStocksToStorage();
        this.renderArticlesTable();
    },

    /**
     * Обнулить все остатки
     */
    zeroAllStocks() {
        this.articles.forEach(a => {
            a.stock = 0;
        });

        this.saveStocksToStorage();
        this.renderArticlesTable();
        App.showToast('Все остатки обнулены', 'success');
    },

    /**
     * Сохранить остатки в localStorage
     */
    saveStocksToStorage() {
        if (!this.selectedProduct) return;

        const stocks = {};
        this.articles.forEach(a => {
            if (a.stock !== undefined && a.stock !== null) {
                stocks[a.mapping_id] = a.stock;
            }
        });

        localStorage.setItem('ym_stocks_' + this.selectedProduct.id, JSON.stringify(stocks));
    },

    /**
     * Синхронизация с Яндекс.Маркет
     */
    async syncWithYM() {
        const btn = document.getElementById('syncYmBtn');
        const originalHtml = btn.innerHTML;

        try {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Синхронизация...';

            const data = await App.fetch('/api/yandex/sync-products', {
                method: 'POST',
                body: {},
                timeout: 120000
            });

            App.showToast(data.message || `Синхронизировано ${data.synced || 0} товаров`, 'success');

            // Обновляем статистику
            await this.loadSyncStats();

            // Перезагружаем товары
            await this.loadProducts();

        } catch (error) {
            App.showToast('Ошибка синхронизации: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    },

    /**
     * Округление цены (до целого)
     */
    roundPrice(price) {
        return Math.round(price);
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => YMCalculator.init());
