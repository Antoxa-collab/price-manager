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
    packagingReference: {}, // Кэш справочника упаковки из БД
    
    // Сортировка таблицы
    sortColumn: null,       // 'article' или 'name'
    sortDirection: 'asc',   // 'asc' или 'desc'
    sortStorageKey: 'wb_calculator_sort', // Ключ localStorage

    /**
     * Инициализация модуля
     */
    init() {
        console.log('WBCalculator.init() started');
        this.bindEvents();
        this.loadProducts();
        this.loadWarehouses();
        this.loadSyncStats();
        this.loadSheetSelect();
        this.loadSettings(); // Загружаем сохранённые настройки наценок
        this.initBulkDiscount(); // Массовое применение скидки
        this.initMinPriceThreshold(); // Минимальная наценка
        this.initTooltips();
        this.initSort(); // Инициализация сортировки таблицы
        this.loadPackagingReference(); // Загрузить справочник упаковки из БД
        console.log('WBCalculator.init() completed');
    },

    /**
     * Загрузка сохранённых настроек калькулятора (наценки)
     */
    async loadSettings() {
        try {
            const data = await App.fetch('/api/calculator/settings?marketplace=wildberries');
            if (data.success && data.settings) {
                const markupMin = document.getElementById('markupMin');
                const wbDiscount = document.getElementById('wbDiscount');

                // Загружаем только если значения были сохранены (не дефолтные нули)
                if (data.settings.markup_min > 0 && markupMin) {
                    markupMin.value = data.settings.markup_min;
                }
                if (data.settings.discount > 0 && wbDiscount) {
                    wbDiscount.value = data.settings.discount;
                }

                console.log('WBCalculator: settings loaded', data.settings);
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
        const discount = parseFloat(document.getElementById('wbDiscount')?.value) || 0;

        try {
            await App.fetch('/api/calculator/settings', {
                method: 'POST',
                body: new URLSearchParams({
                    marketplace: 'wildberries',
                    markup_min: markupMin,
                    markup_extra: 0, // WB не использует доп.наценку
                    discount: discount
                })
            });
            console.log('WBCalculator: settings saved');
        } catch (e) {
            console.warn('Не удалось сохранить настройки калькулятора:', e);
        }
    },

    // =============================================
    // СОРТИРОВКА ТАБЛИЦЫ АРТИКУЛОВ
    // =============================================

    /**
     * Инициализация сортировки
     */
    initSort() {
        this.loadSortSettings();
        if (this.sortColumn) {
            this.sortArticles();
        }
    },

    /**
     * Загрузить настройки сортировки из localStorage
     */
    loadSortSettings() {
        try {
            const saved = localStorage.getItem(this.sortStorageKey);
            if (saved) {
                const settings = JSON.parse(saved);
                this.sortColumn = settings.column || null;
                this.sortDirection = settings.direction || 'asc';
                console.log(`[Sort] Загружено: ${this.sortColumn} ${this.sortDirection}`);
            }
        } catch (e) {
            console.warn('[Sort] Ошибка загрузки настроек:', e);
        }
    },

    /**
     * Сохранить настройки сортировки
     */
    saveSortSettings() {
        try {
            const settings = {
                column: this.sortColumn,
                direction: this.sortDirection
            };
            localStorage.setItem(this.sortStorageKey, JSON.stringify(settings));
        } catch (e) {
            console.warn('[Sort] Ошибка сохранения:', e);
        }
    },

    /**
     * Обработчик клика по заголовку
     */
    handleSortClick(column) {
        console.log(`[Sort] Клик по колонке: ${column}`);
        
        if (this.sortColumn === column) {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortColumn = column;
            this.sortDirection = 'asc';
        }
        
        this.sortArticles();
        this.saveSortSettings();
        this.renderArticlesTable();
    },

    /**
     * Сортировка массива артикулов
     */
    sortArticles() {
        if (!this.sortColumn || !this.articles || this.articles.length === 0) {
            return;
        }
        
        const direction = this.sortDirection === 'asc' ? 1 : -1;
        
        this.articles.sort((a, b) => {
            let valA, valB;
            
            if (this.sortColumn === 'article') {
                valA = (a.vendor_code || a.nmID || a.article || '').toString().toLowerCase();
                valB = (b.vendor_code || b.nmID || b.article || '').toString().toLowerCase();
            } else if (this.sortColumn === 'name') {
                valA = (a.wb_name || a.name || a.title || '').toLowerCase();
                valB = (b.wb_name || b.name || b.title || '').toLowerCase();
            } else {
                return 0;
            }
            
            return valA.localeCompare(valB, 'ru', { numeric: true }) * direction;
        });
        
        console.log(`[Sort] Отсортировано: ${this.sortColumn} ${this.sortDirection}`);
    },

    /**
     * Обновить индикаторы сортировки в заголовках
     */
    updateSortIndicators() {
        document.querySelectorAll('#articlesTable th.sortable').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        
        if (this.sortColumn) {
            const activeHeader = document.querySelector(`#articlesTable th[data-sort="${this.sortColumn}"]`);
            if (activeHeader) {
                activeHeader.classList.add(this.sortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        }
    },

    // =============================================
    // СПРАВОЧНИК УПАКОВКИ АРТИКУЛОВ (БД)
    // =============================================

    /**
     * Загрузить справочник упаковки из БД
     */
    async loadPackagingReference() {
        try {
            const response = await App.fetch('/api/article-packaging/list');
            
            if (response.success && response.data) {
                this.packagingReference = response.data;
                console.log(`[loadPackagingReference] ✅ Загружено ${response.count} записей из БД`);
            } else {
                this.packagingReference = {};
                console.log('[loadPackagingReference] БД пуста');
            }
        } catch (error) {
            console.error('[loadPackagingReference] ❌ Ошибка:', error);
            this.packagingReference = {};
        }
        
        return this.packagingReference;
    },

    /**
     * Сохранить параметры упаковки артикула в БД
     */
    async savePackagingToDb(articleId, articleName, piecesPerSheet, packQuantity, sheetName) {
        articleId = (articleId || '').toString().trim();
        articleName = (articleName || '').trim();
        sheetName = (sheetName || '').trim();
        
        if (!articleId) {
            console.warn('[savePackagingToDb] Пустой article_id');
            return false;
        }
        
        console.log(`[savePackagingToDb] Сохраняем: ${articleId}, pieces=${piecesPerSheet}, pack=${packQuantity}`);
        
        try {
            const response = await App.fetch('/api/article-packaging/save', {
                method: 'POST',
                body: JSON.stringify({
                    article_id: articleId,
                    article_name: articleName,
                    pieces_per_sheet: piecesPerSheet || null,
                    pack_quantity: packQuantity || null,
                    sheet_name: sheetName
                })
            });
            
            if (response.success) {
                // Обновить локальный кэш
                this.packagingReference[articleId] = {
                    pieces_per_sheet: piecesPerSheet,
                    pack_quantity: packQuantity
                };
                console.log('[savePackagingToDb] ✅ Сохранено в БД');
                return true;
            } else {
                console.error('[savePackagingToDb] ❌', response.message);
                return false;
            }
        } catch (error) {
            console.error('[savePackagingToDb] ❌ Ошибка:', error);
            return false;
        }
    },

    /**
     * Инициализация массового применения скидки
     */
    initBulkDiscount() {
        const applyBtn = document.getElementById('wbApplyBulkDiscount');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyBulkDiscount());
        }
    },

    /**
     * Массовое применение скидки ко всем артикулам текущего товара
     */
    async applyBulkDiscount() {
        const discountInput = document.getElementById('wbBulkDiscount');
        const discount = parseFloat(discountInput?.value) || 0;

        if (discount < 0 || discount > 100) {
            App.showToast('Скидка должна быть от 0 до 100%', 'warning');
            return;
        }

        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        const confirmed = await App.confirm(
            `Применить скидку ${discount}% ко всем артикулам товара "${this.selectedProduct.name}"?`,
            'Подтверждение'
        );

        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/wb/bulk-discount', {
                method: 'POST',
                body: new URLSearchParams({
                    product_id: this.selectedProduct.id,
                    discount: discount
                })
            });

            if (data.success) {
                App.showToast(data.message || 'Скидка применена', 'success');

                // Обновляем данные в массиве articles
                this.articles.forEach(article => {
                    article.custom_discount = discount;
                    article.is_discount_edited = true;
                    article.calculated_discount = discount;
                    // Пересчитываем "Цена для WB" = Наценка / (1 - Скидка/100)
                    const markup = article.calculated_price || 0;
                    article.price_for_wb = discount < 100 ? this.roundPrice(markup / (1 - discount / 100)) : markup;
                });

                // Перерисовываем таблицу
                this.renderArticlesTable();
            } else {
                App.showToast(data.error || 'Ошибка применения скидки', 'danger');
            }
        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Инициализация функционала минимальной цены
     */
    initMinPriceThreshold() {
        console.log('[initMinPriceThreshold] called');
        const applyBtn = document.getElementById('wbApplyMinPrice');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyMinPriceThreshold());
        }
    },

    /**
     * Применить минимальную цену - поднять все артикулы ниже порога
     */
    applyMinPriceThreshold() {
        console.log('[applyMinPriceThreshold] called, articles:', this.articles.length);
        const input = document.getElementById('wbMinPriceThreshold');
        const minPrice = parseFloat(input?.value) || 0;

        if (minPrice <= 0) {
            App.showToast('Введите минимальную цену больше 0', 'warning');
            return;
        }

        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        if (this.articles.length === 0) {
            App.showToast('Нет артикулов для обработки', 'warning');
            return;
        }

        let updatedCount = 0;

        // Проходим по всем артикулам в памяти
        this.articles.forEach(article => {
            const currentPrice = article.calculated_price || 0;

            // Если текущая наценка ниже минимальной — поднимаем
            if (currentPrice < minPrice) {
                article.calculated_price = minPrice;
                article.custom_min_price = minPrice;
                article.min_price_edited = true;
                article.has_custom_min_price = true;

                // Пересчёт "Цена для WB" = Наценка / (1 - Скидка/100)
                const discount = article.calculated_discount || 90;
                article.price_for_wb = discount < 100 ? this.roundPrice(minPrice / (1 - discount / 100)) : minPrice;

                updatedCount++;
            }
        });

        if (updatedCount > 0) {
            // Перерисовать таблицу
            this.renderArticlesTable();

            const ending = this.pluralize(updatedCount, '', 'а', 'ов');
            App.showToast(`Поднято ${updatedCount} артикул${ending} до ${minPrice}₽`, 'success');
        } else {
            App.showToast('Все цены уже выше минимальной', 'info');
        }
    },

    /**
     * Склонение слов по числу
     */
    pluralize(n, one, few, many) {
        const mod10 = n % 10;
        const mod100 = n % 100;
        if (mod100 >= 11 && mod100 <= 19) return many;
        if (mod10 === 1) return one;
        if (mod10 >= 2 && mod10 <= 4) return few;
        return many;
    },

    /**
     * Загрузка списка листов из справочника раскроя в выпадающий список
     */
    async loadSheetSelect() {
        const select = document.getElementById('wbSheetSelect');
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
        // При изменении % наценки - принудительный пересчёт ВСЕХ артикулов (игнорируем кастомные цены)
        document.getElementById('markupMin')?.addEventListener('input', () => this.recalculatePrices(true));
        // При изменении скидки - обычный пересчёт (сохраняем кастомные цены)
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

        // Автозаполнение из названий артикулов (старый метод)
        document.getElementById('autoFillBtn')?.addEventListener('click', () => this.autoFillFromNames());

        // Автозаполнение из справочника раскроя (новый метод)
        document.getElementById('wbAutoFillBtn')?.addEventListener('click', () => this.autoFillPieces());

        // Управление остатками
        document.getElementById('applyBulkStockBtn')?.addEventListener('click', () => this.applyBulkStock(false));
        document.getElementById('applyAllStockBtn')?.addEventListener('click', () => this.applyBulkStock(true));
        document.getElementById('zeroStockBtn')?.addEventListener('click', () => this.zeroAllStocks());

        // Загрузка только остатков
        document.getElementById('uploadStocksOnlyBtn')?.addEventListener('click', () => this.uploadStocksOnly());

        // Синхронизация с WB
        document.getElementById('syncWbBtn')?.addEventListener('click', () => this.syncWithWB());

        // Ручной ввод ID склада (fallback если API не вернул склады)
        document.getElementById('saveManualWarehouse')?.addEventListener('click', () => {
            const manualId = document.getElementById('manualWarehouseId')?.value;
            if (manualId) {
                const select = document.getElementById('warehouseSelect');
                if (select) {
                    // Добавляем как опцию
                    const option = document.createElement('option');
                    option.value = manualId;
                    option.textContent = `Склад #${manualId} (ручной ввод)`;
                    option.selected = true;
                    select.innerHTML = '';
                    select.appendChild(option);

                    // Сохраняем в warehouses для uploadStocksOnly
                    this.warehouses = [{ id: parseInt(manualId), name: `Склад #${manualId}` }];

                    // Скрываем блок ручного ввода
                    document.getElementById('manualWarehouseBlock').style.display = 'none';

                    App.showToast('ID склада сохранён', 'success');
                    console.log('[saveManualWarehouse] Склад добавлен вручную:', manualId);
                }
            } else {
                App.showToast('Введите ID склада', 'warning');
            }
        });
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
        console.log('[loadWarehouses] Начинаем загрузку складов...');
        try {
            const response = await App.fetch('/api/wb/warehouses');
            console.log('[loadWarehouses] Raw response:', response);

            // Проверяем структуру ответа
            if (!response) {
                console.error('[loadWarehouses] Пустой ответ от сервера');
                this.warehouses = [];
                this.renderWarehouseSelect();
                return;
            }

            // response должен быть {success: true, warehouses: [...]}
            if (response.success && Array.isArray(response.warehouses)) {
                this.warehouses = response.warehouses;
                console.log('[loadWarehouses] Получено складов:', this.warehouses.length);

                if (this.warehouses.length === 0) {
                    console.warn('[loadWarehouses] API вернул пустой массив складов');
                    console.warn('[loadWarehouses] Возможные причины:');
                    console.warn('  1. API ключ не имеет прав на Marketplace');
                    console.warn('  2. Нет FBS складов в ЛК Wildberries');
                    console.warn('  3. Ошибка авторизации');
                }
            } else {
                console.warn('[loadWarehouses] Некорректный ответ:', response);
                console.warn('[loadWarehouses] success:', response.success);
                console.warn('[loadWarehouses] warehouses type:', typeof response.warehouses);
                console.warn('[loadWarehouses] error:', response.error);
                this.warehouses = [];
            }

            this.renderWarehouseSelect();

        } catch (error) {
            console.error('[loadWarehouses] Исключение:', error);
            this.warehouses = [];
            this.renderWarehouseSelect();
        }
    },

    /**
     * Рендеринг списка складов
     */
    renderWarehouseSelect() {
        const select = document.getElementById('warehouseSelect');
        const manualBlock = document.getElementById('manualWarehouseBlock');

        if (!select) return;

        select.innerHTML = '';

        if (this.warehouses.length === 0) {
            select.innerHTML = '<option value="">Нет доступных складов (проверьте API ключ)</option>';
            console.warn('[renderWarehouseSelect] Список складов пуст');

            // Показываем ручной ввод
            if (manualBlock) {
                manualBlock.style.display = 'block';
            }
            return;
        }

        // Скрываем ручной ввод если склады загрузились
        if (manualBlock) {
            manualBlock.style.display = 'none';
        }

        select.innerHTML = '<option value="">Выберите склад</option>';

        // Получаем сохранённый склад
        const savedWarehouseId = localStorage.getItem('wb_selected_warehouse');

        this.warehouses.forEach(wh => {
            const option = document.createElement('option');
            option.value = wh.id;
            option.textContent = wh.name;
            // Автовыбор сохранённого склада
            if (savedWarehouseId && String(wh.id) === savedWarehouseId) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        // Сохраняем выбор склада при изменении
        select.removeEventListener('change', this._warehouseChangeHandler);
        this._warehouseChangeHandler = () => {
            localStorage.setItem('wb_selected_warehouse', select.value);
            console.log('[renderWarehouseSelect] Склад сохранён:', select.value);
        };
        select.addEventListener('change', this._warehouseChangeHandler);

        console.log('[renderWarehouseSelect] Отрисовано складов:', this.warehouses.length, ', сохранённый:', savedWarehouseId);
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

            // Восстанавливаем сохранённые остатки из localStorage
            const savedStocks = JSON.parse(localStorage.getItem('wb_stocks_' + productId) || '{}');
            this.articles.forEach(a => {
                if (savedStocks[a.mapping_id] !== undefined) {
                    a.stock = savedStocks[a.mapping_id];
                }
            });
            console.log('[loadArticles] Восстановлено остатков:', Object.keys(savedStocks).length);

            // Загружаем кастомные минимальные цены
            await this.loadCustomMinPrices(productId);

            this.renderArticlesTable();
        } catch (error) {
            App.showToast('Ошибка загрузки артикулов: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка кастомных минимальных цен для артикулов товара
     */
    async loadCustomMinPrices(productId) {
        try {
            const data = await App.fetch(`/api/mapping/min-prices?product_id=${productId}&marketplace=wildberries`);
            if (data.success && data.prices) {
                // data.prices = { mapping_id: min_price, ... }
                this.articles.forEach(article => {
                    if (data.prices[article.mapping_id]) {
                        article.custom_min_price = data.prices[article.mapping_id];
                        article.has_custom_min_price = true;
                    }
                });
                console.log('WBCalculator: custom min prices loaded', data.prices);
            }
        } catch (e) {
            console.warn('Не удалось загрузить кастомные мин. цены:', e);
        }
    },

    /**
     * Пересчёт цен
     * Формула для WB:
     * cost = (cost_price / pieces_per_sheet) × quantity_in_pack
     * price_before_discount = cost × (1 + markup_min / 100)
     * price_after_discount = price_before_discount × (1 - wb_discount / 100)
     *
     * @param {boolean} forceRecalc - Принудительно пересчитать все артикулы, игнорируя кастомные цены
     */
    recalculatePrices(forceRecalc = false) {
        if (!this.selectedProduct) return;

        if (forceRecalc) {
            console.log('[recalculatePrices] FORCE RECALC - игнорируем кастомные цены');
        }

        const markupMin = parseFloat(document.getElementById('markupMin').value) || 0;
        const costPrice = this.selectedProduct.cost_price || 0;
        const basePrice = this.selectedProduct.base_price || costPrice;

        // Расчёт для базовой единицы (1 шт из листа)
        const unitCost = costPrice;
        const unitMarkup = this.roundPrice(unitCost * (1 + markupMin / 100));  // Наценка
        const defaultDiscount = 90; // 90% скидка по умолчанию
        const unitPriceForWb = this.roundPrice(unitMarkup / (1 - defaultDiscount / 100));  // Цена для WB

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcBasePrice').textContent = App.formatPrice(basePrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitMarkup);  // Наценка
        document.getElementById('calcFinalPrice').textContent = App.formatPrice(unitPriceForWb);  // Цена для WB

        // Пересчитываем цены для каждого артикула
        this.articles.forEach(article => {
            const piecesPerSheet = article.pieces_per_sheet || 1;
            const quantityInPack = article.quantity_in_pack || 1;

            // Себестоимость: (закупка / кол-во из листа) × кол-во в упаковке
            const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

            // Цена до скидки: используем кастомную если была сохранена И не принудительный пересчёт
            if (!forceRecalc && article.has_custom_min_price && article.custom_min_price > 0) {
                // Есть сохранённая кастомная цена - используем её
                article.calculated_price = article.custom_min_price;
                article.min_price_edited = true; // Помечаем как отредактированную
                console.log(`[recalc] ${article.vendor_code}: SKIP (has_custom_min_price=${article.custom_min_price})`);
            } else {
                // Рассчитываем по формуле (или принудительно пересчитываем)
                const rawPrice = articleCost * (1 + markupMin / 100);
                article.calculated_price = this.roundPrice(rawPrice);
                // При принудительном пересчёте сбрасываем флаг кастомной цены
                if (forceRecalc && article.has_custom_min_price) {
                    article.has_custom_min_price = false;
                    article.min_price_edited = false;
                    console.log(`[recalc] ${article.vendor_code}: FORCE cost=${articleCost.toFixed(2)}, markup=${markupMin}%, raw=${rawPrice.toFixed(2)}, final=${article.calculated_price}`);
                } else {
                    console.log(`[recalc] ${article.vendor_code}: cost=${articleCost.toFixed(2)}, markup=${markupMin}%, raw=${rawPrice.toFixed(2)}, final=${article.calculated_price}`);
                }
            }

            // Скидка для артикула: используем кастомную если была сохранена, иначе 90% по умолчанию
            if (article.is_discount_edited && article.custom_discount !== null) {
                article.calculated_discount = article.custom_discount;
            } else if (article.custom_discount !== null && article.custom_discount !== undefined) {
                // Используем значение из БД (по умолчанию 90)
                article.calculated_discount = article.custom_discount;
            } else {
                // Дефолт 90%
                article.calculated_discount = 90;
            }

            // Расчёт "Цена для WB" = Наценка / (1 - Скидка/100)
            const discount = article.calculated_discount;
            article.price_for_wb = discount < 100 ? this.roundPrice(article.calculated_price / (1 - discount / 100)) : article.calculated_price;

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
     * Обработчик изменения наценки
     * Пересчитывает "Цена для WB" для конкретной строки
     */
    onPriceChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newMarkup = parseFloat(input.value) || 0;
        const originalMarkup = parseFloat(input.dataset.original) || 0;

        // Обновляем данные в массиве articles
        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_price = newMarkup;
            article.custom_min_price = newMarkup; // Помечаем как изменённую вручную
            article.min_price_edited = true; // Флаг для сохранения в БД

            // Пересчёт "Цена для WB" = Наценка / (1 - Скидка/100)
            const discount = article.calculated_discount || 90;
            const priceForWb = discount < 100 ? this.roundPrice(newMarkup / (1 - discount / 100)) : newMarkup;
            article.price_for_wb = priceForWb;

            // Обновляем отображение "Цена для WB" в строке
            const priceForWbSpan = row.querySelector('.price-for-wb');
            if (priceForWbSpan) {
                priceForWbSpan.textContent = App.formatPrice(priceForWb);
            }
        }

        // Подсвечиваем изменённую ячейку
        if (Math.abs(newMarkup - originalMarkup) > 0.01) {
            input.classList.add('price-modified');
            row.classList.add('row-modified');
        } else {
            input.classList.remove('price-modified');
            row.classList.remove('row-modified');
        }
    },

    /**
     * Обработчик изменения скидки
     * Пересчитывает "Цена для WB" для конкретной строки
     */
    onDiscountChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newDiscount = parseFloat(input.value) || 0;
        const originalDiscount = parseFloat(input.dataset.original) || 90;

        // Валидация (скидка от 0 до 99%, при 100% деление на ноль)
        if (newDiscount < 0 || newDiscount >= 100) {
            App.showToast('Скидка должна быть от 0 до 99%', 'warning');
            input.value = Math.min(99, Math.max(0, newDiscount));
            return;
        }

        // Обновляем данные в массиве articles
        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_discount = newDiscount;
            article.custom_discount = newDiscount;
            article.is_discount_edited = true;

            // Пересчёт "Цена для WB" = Наценка / (1 - Скидка/100)
            const markup = article.calculated_price || 0;
            const priceForWb = newDiscount < 100 ? this.roundPrice(markup / (1 - newDiscount / 100)) : markup;
            article.price_for_wb = priceForWb;

            // Обновляем отображение "Цена для WB" в строке
            const priceForWbSpan = row.querySelector('.price-for-wb');
            if (priceForWbSpan) {
                priceForWbSpan.textContent = App.formatPrice(priceForWb);
            }
        }

        // Подсвечиваем изменённую ячейку
        if (Math.abs(newDiscount - originalDiscount) > 0.01) {
            input.classList.remove('text-info');
            input.classList.add('text-warning');
            row.classList.add('row-modified');
        } else {
            input.classList.remove('text-warning');
            input.classList.add('text-info');
            row.classList.remove('row-modified');
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

        // Применить сортировку перед рендерингом
        this.sortArticles();

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
                                ? 'У этого товара нет привязанных артикулов Wildberries'
                                : 'Выберите товар для просмотра привязанных артикулов'}
                        </p>
                    </td>
                </tr>
            `;
            this.updateSortIndicators();
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
        this.updateSortIndicators();
    },

    /**
     * Рендеринг строки артикула
     */
    renderArticleRow(article) {
        const isSelected = this.selectedArticles.has(String(article.mapping_id));
        const statusHtml = this.getStatusBadge(article.status);
        const piecesPerSheet = article.pieces_per_sheet || 1;
        const quantityInPack = article.quantity_in_pack || 1;
        const isOversized = article.is_oversized || false;

        // Расчёт "Цена для WB" по формуле: Наценка / (1 - Скидка/100)
        const markup = article.calculated_price || 0;
        const discount = article.calculated_discount || 90; // default 90%
        const priceForWb = discount < 100 ? this.roundPrice(markup / (1 - discount / 100)) : markup;

        // Сохраняем для выгрузки
        article.price_for_wb = priceForWb;

        // КГТ-товары помечаются и блокируются для загрузки остатков
        const rowClass = isOversized ? 'oversized-item' : '';
        const checkboxDisabled = isOversized ? 'disabled' : '';
        const oversizedBadge = isOversized ? '<span class="badge bg-warning text-dark ms-1" title="КГТ - не принимается складом">КГТ</span>' : '';

        return `
            <tr data-mapping-id="${article.mapping_id}" data-nm-id="${article.nm_id || ''}" data-status="${article.status || 'new'}" class="${rowClass}">
                <td>
                    <input type="checkbox" class="form-check-input article-checkbox"
                           data-id="${article.mapping_id}" ${isSelected && !isOversized ? 'checked' : ''} ${checkboxDisabled}>
                </td>
                <td>
                    <code>${App.escapeHtml(article.vendor_code || article.nm_id || '')}</code>${oversizedBadge}
                    <div class="small text-muted">nmID: ${article.nm_id || '-'}</div>
                    <div class="small ${article.barcode ? 'text-muted' : 'text-danger'}">
                        ${article.barcode ? `barcode: ${article.barcode}` : 'barcode: ❌ нет'}
                    </div>
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
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm price-input text-end fw-bold text-warning ${article.has_custom_min_price ? 'price-modified' : ''}"
                           value="${markup}"
                           data-id="${article.mapping_id}"
                           data-original="${markup}"
                           min="0" step="1"
                           style="width: 80px; display: inline-block;"
                           title="${article.has_custom_min_price ? 'Сохранённая наценка' : 'Рассчитанная наценка'}">
                    ${article.has_custom_min_price ? '<i class="bi bi-bookmark-fill text-success ms-1" title="Сохранённая наценка"></i>' : ''}
                </td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm discount-input text-end ${article.is_discount_edited ? 'text-warning' : 'text-info'}"
                           value="${discount}"
                           data-id="${article.mapping_id}"
                           data-original="${article.calculated_discount || 90}"
                           min="0" max="99" step="1"
                           style="width: 55px; display: inline-block;"
                           title="${article.is_discount_edited ? 'Сохранённая скидка' : 'Скидка по умолчанию 90%'}">
                    <span class="text-muted">%</span>
                    ${article.is_discount_edited ? '<i class="bi bi-bookmark-fill text-warning ms-1" title="Сохранённая скидка"></i>' : ''}
                </td>
                <td class="text-end">
                    <span class="fw-bold text-success price-for-wb" data-id="${article.mapping_id}">
                        ${App.formatPrice(priceForWb)}
                    </span>
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

        // Старая кнопка автозаполнения (если есть)
        const autoFillBtn = document.getElementById('autoFillBtn');
        if (autoFillBtn) {
            autoFillBtn.disabled = !hasArticles;
        }

        // Новая кнопка автозаполнения из справочника раскроя
        const wbAutoFillBtn = document.getElementById('wbAutoFillBtn');
        if (wbAutoFillBtn) {
            wbAutoFillBtn.disabled = !hasArticles;
        }

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

            // Дополнительно: сохранить справочник раскроя
            await this.saveCuttingReference();

            App.showToast('Настройки сохранены', 'success');
        } catch (error) {
            App.showToast('Ошибка сохранения: ' + error.message, 'danger');
        }
    },

    /**
     * Сохранить справочник раскроя в БД
     */
    async saveCuttingReference() {
        const sheetSelect = document.getElementById('wbSheetSelect');
        const selectedOption = sheetSelect?.selectedOptions[0];

        if (!selectedOption) {
            console.log('[saveCuttingReference] Лист не выбран');
            return;
        }

        const sheetName = (selectedOption.textContent || '').trim();
        const sheetWidth = parseInt(selectedOption.dataset.width) || 1400;
        const sheetHeight = parseInt(selectedOption.dataset.height) || 1030;

        // Собрать данные из таблицы
        const items = [];

        this.articles.forEach(article => {
            const name = article.wb_name || article.vendor_code || '';
            const dimensions = parseArticleDimensions(name);
            const piecesPerSheet = parseInt(article.pieces_per_sheet) || 0;

            if (dimensions && dimensions.width && dimensions.height && piecesPerSheet > 0) {
                items.push({
                    piece_width: dimensions.width,
                    piece_height: dimensions.height,
                    pieces_count: piecesPerSheet
                });
            }
        });

        if (items.length === 0) {
            console.log('[saveCuttingReference] Нет данных для сохранения');
            return;
        }

        try {
            const response = await App.fetch('/api/cutting-reference/save', {
                method: 'POST',
                body: JSON.stringify({
                    sheet_name: sheetName,
                    sheet_width: sheetWidth,
                    sheet_height: sheetHeight,
                    items: items
                })
            });

            if (response.success) {
                console.log(`[saveCuttingReference] Сохранено ${response.saved} записей в справочник "${sheetName}"`);
            }
        } catch (error) {
            console.error('[saveCuttingReference] Ошибка:', error);
        }
    },

    /**
     * Загрузить справочник из БД (приоритет) или использовать дефолтный
     */
    /**
     * Загрузить справочник раскроя
     * ПРИОРИТЕТ: БД > дефолтный справочник
     * Объединяет данные: сначала дефолт, потом БД перезаписывает
     */
    async loadCuttingReference(sheetName) {
        // 1. Начинаем с дефолтного справочника
        const defaultRef = this.getDefaultCuttingReference(sheetName);
        console.log(`[loadCuttingReference] Дефолтный справочник: ${Object.keys(defaultRef).length} позиций`);
        
        // 2. Пробуем загрузить из БД
        let dbRef = {};
        try {
            const response = await App.fetch(`/api/cutting-reference/load?sheet_name=${encodeURIComponent(sheetName)}`);
            
            if (response.success && response.reference && Object.keys(response.reference).length > 0) {
                dbRef = response.reference;
                console.log(`[loadCuttingReference] ✅ Загружено из БД: ${Object.keys(dbRef).length} позиций`);
                console.log(`[loadCuttingReference] Данные БД:`, dbRef);
            } else {
                console.log(`[loadCuttingReference] БД пуста для листа "${sheetName}"`);
            }
        } catch (error) {
            console.warn('[loadCuttingReference] ❌ Ошибка загрузки из БД:', error);
        }
        
        // 3. Объединяем: дефолт + БД (БД перезаписывает дефолт!)
        const merged = { ...defaultRef, ...dbRef };
        console.log(`[loadCuttingReference] Итого после объединения: ${Object.keys(merged).length} позиций`);
        
        return merged;
    },

    /**
     * Дефолтный справочник для листа 1400×1030
     */
    /**
     * Дефолтный справочник раскроя для листа "Другой 1400×1030"
     * Данные: выход деталей с 2 листов 1400×1030 (= один лист 2800×2070 разрезанный пополам)
     * Применяется для товаров: МДФ, L-MDF
     */
    getDefaultCuttingReference(sheetName) {
        if (sheetName.includes('Другой') || sheetName.includes('1400')) {
            return {
                // Основные размеры
                '500x400': 12, '400x500': 12,
                '1350x700': 2, '700x1350': 2,
                '600x450': 8, '450x600': 8,
                '500x500': 8,
                '750x550': 4, '550x750': 4,
                '600x400': 10, '400x600': 10,  // ИСПРАВЛЕНО: было 8
                '600x600': 4,
                '300x300': 24,
                '600x500': 8, '500x600': 8,
                '400x300': 18, '300x400': 18,
                '350x250': 32, '250x350': 32,
                '800x600': 8, '600x800': 8,    // ИСПРАВЛЕНО: было 4
                '700x500': 8, '500x700': 8,    // ИСПРАВЛЕНО: было 6
                '750x600': 4, '600x750': 4,
                
                // Форматы А4, А3
                '297x210': 38, '210x297': 38,  // А4
                '420x297': 18, '297x420': 18,  // А3
                
                // Размеры KIT (1350×...)
                '1350x400': 3, '400x1350': 3,
                '1350x300': 4, '300x1350': 4,
                '1350x500': 2, '500x1350': 2,
                '1350x600': 2, '600x1350': 2,
                '1350x800': 1, '800x1350': 1,  // ДОБАВЛЕНО
                '1350x900': 1, '900x1350': 1
            };
        }

        return {};
    },

    /**
     * Загрузить артикулы товара
     */
    async uploadSelected() {
        if (this.selectedArticles.size === 0) {
            App.showToast('Выберите артикулы для загрузки', 'warning');
            return;
        }

        const articlesToUpload = this.articles.filter(a =>
            this.selectedArticles.has(String(a.mapping_id))
        );

        if (articlesToUpload.length === 0) {
            App.showToast('Не удалось найти выбранные артикулы', 'warning');
            return;
        }

        // 1. Загружаем цены
        await this.uploadPrices(articlesToUpload);

        // 2. Задержка 10 сек чтобы WB успел обработать цены (иначе HTTP 409)
        console.log('[uploadSelected] Пауза 10 сек перед загрузкой остатков...');
        await new Promise(resolve => setTimeout(resolve, 10000));

        // 3. Загружаем остатки (если есть склад и артикулы с остатками)
        await this._uploadStocksAfterPrices(articlesToUpload);
    },

    /**
     * Загрузить все артикулы товара на WB (цены + остатки)
     */
    async uploadAll() {
        if (this.articles.length === 0) {
            App.showToast('Нет артикулов для загрузки', 'warning');
            return;
        }

        // 1. Загружаем цены
        await this.uploadPrices(this.articles);

        // 2. Задержка 10 сек чтобы WB успел обработать цены (иначе HTTP 409)
        console.log('[uploadAll] Пауза 10 сек перед загрузкой остатков...');
        await new Promise(resolve => setTimeout(resolve, 10000));

        // 3. Загружаем остатки (если есть склад и артикулы с остатками)
        await this._uploadStocksAfterPrices(this.articles);
    },

    /**
     * Вспомогательный метод: загрузка остатков после цен
     * RETRY v3: батчи по 10, retry при 409, задержка 2 сек между батчами
     * @param {Array} articles - Артикулы для загрузки
     */
    async _uploadStocksAfterPrices(articles) {
        console.log('[_uploadStocksAfterPrices] ===== ВЕРСИЯ С RETRY v3 =====');
        const warehouseId = document.getElementById('warehouseSelect')?.value;

        if (!warehouseId) {
            if (articles.some(a => parseInt(a.stock) > 0)) {
                console.log('[_uploadStocksAfterPrices] Склад не выбран, остатки не загружены');
            }
            return;
        }

        // Фильтруем артикулы с остатками >= 0 (включая обнуление)
        // ВАЖНО: Исключаем КГТ-товары (is_oversized) — они не принимаются складом
        const articlesWithStocks = articles.filter(a => {
            const hasStock = a.stock !== undefined && a.stock !== null;
            const hasIdentifier = a.barcode || a.nm_id || a.vendor_code;
            const notOversized = !a.is_oversized;
            return hasStock && hasIdentifier && notOversized;
        });

        // Считаем сколько КГТ было пропущено
        const oversizedCount = articles.filter(a => a.is_oversized && a.stock !== undefined && a.stock !== null).length;
        if (oversizedCount > 0) {
            console.log(`[_uploadStocksAfterPrices] Пропущено КГТ-товаров: ${oversizedCount}`);
        }

        if (articlesWithStocks.length === 0) {
            if (oversizedCount > 0) {
                console.log('[_uploadStocksAfterPrices] Все товары с остатками — КГТ, загрузка невозможна');
            } else {
                console.log('[_uploadStocksAfterPrices] Нет артикулов с указанными остатками');
            }
            return;
        }

        // Формируем массив остатков
        const stocks = articlesWithStocks.map(a => ({
            sku: a.barcode || a.vendor_code || '',
            nm_id: a.nm_id,
            amount: parseInt(a.stock) || 0
        }));

        console.log('[_uploadStocksAfterPrices] ★★★ RETRY v3 АКТИВЕН ★★★');
        console.log('[_uploadStocksAfterPrices] Всего SKU (без КГТ):', stocks.length);

        // Разбиваем на батчи по 10 штук (уменьшено с 20)
        const batchSize = 10;
        const batches = [];
        for (let i = 0; i < stocks.length; i += batchSize) {
            batches.push(stocks.slice(i, i + batchSize));
        }

        console.log('[_uploadStocksAfterPrices] Размер батча:', batchSize);
        console.log('[_uploadStocksAfterPrices] Батчей будет:', batches.length);

        let totalUpdated = 0;
        let totalErrors = 0;
        let allWarnings = [];  // Собираем предупреждения о пропущенных артикулах
        const maxRetries = 3;
        const retryDelay = 5000;  // 5 секунд между retry
        const batchDelay = 2000;  // 2 секунды между батчами

        for (let i = 0; i < batches.length; i++) {
            const batch = batches[i];
            console.log(`[_uploadStocksAfterPrices] Батч ${i + 1}/${batches.length}: ${batch.length} шт.`);

            let success = false;
            let lastError = null;

            // Retry логика
            for (let attempt = 1; attempt <= maxRetries && !success; attempt++) {
                if (attempt > 1) {
                    console.log(`[_uploadStocksAfterPrices] Retry ${attempt}/${maxRetries} через ${retryDelay/1000} сек...`);
                    await new Promise(resolve => setTimeout(resolve, retryDelay));
                }

                try {
                    const result = await App.fetch('/api/wb/upload-stocks', {
                        method: 'POST',
                        body: {
                            warehouse_id: parseInt(warehouseId),
                            stocks: batch
                        }
                    });

                    console.log(`[_uploadStocksAfterPrices] Батч ${i + 1} попытка ${attempt}:`, result);

                    if (result.success) {
                        totalUpdated += result.updated || batch.length;
                        success = true;
                        // Собираем warnings (пропущенные артикулы без баркодов и т.д.)
                        if (result.warnings && Array.isArray(result.warnings)) {
                            allWarnings = allWarnings.concat(result.warnings);
                        }
                    } else if (result.error && result.error.includes('CargoWarehouseRestriction')) {
                        // КГТ-товары — склад не принимает крупногабарит, retry бесполезен
                        lastError = result.error;
                        console.warn(`[_uploadStocksAfterPrices] КГТ-ошибка (склад не принимает крупногабарит), пропускаем батч`);
                        // Помечаем товары как КГТ (сервер уже сделал это, но покажем пользователю)
                        if (result.marked_as_oversized > 0) {
                            console.log(`[_uploadStocksAfterPrices] Помечено КГТ: ${result.marked_as_oversized} товаров`);
                        }
                        break; // Не делаем retry — это постоянная ошибка
                    } else if (result.error && result.error.includes('409')) {
                        // Другие HTTP 409 - нужен retry
                        lastError = result.error;
                        console.warn(`[_uploadStocksAfterPrices] HTTP 409, retry...`);
                    } else if (result.error && result.error.includes('429')) {
                        // Rate limit - увеличенная задержка
                        lastError = result.error;
                        console.warn(`[_uploadStocksAfterPrices] Rate limit, ждём 10 сек...`);
                        await new Promise(resolve => setTimeout(resolve, 10000));
                    } else {
                        // Другая ошибка - не retry
                        lastError = result.error;
                        console.error(`[_uploadStocksAfterPrices] Ошибка без retry:`, result.error);
                        break;
                    }
                } catch (err) {
                    lastError = err.message;
                    console.error(`[_uploadStocksAfterPrices] Exception:`, err);
                }
            }

            if (!success) {
                totalErrors++;
                console.error(`[_uploadStocksAfterPrices] Батч ${i + 1} FAILED после ${maxRetries} попыток:`, lastError);
            }

            // Пауза между батчами (кроме последнего)
            if (i < batches.length - 1) {
                console.log(`[_uploadStocksAfterPrices] Пауза ${batchDelay/1000} сек...`);
                await new Promise(resolve => setTimeout(resolve, batchDelay));
            }
        }

        // Логируем warnings (артикулы без баркодов и т.д.)
        if (allWarnings.length > 0) {
            console.warn('[_uploadStocksAfterPrices] Пропущенные артикулы:', allWarnings);
        }

        // Итоговое уведомление
        if (totalErrors === 0 && totalUpdated > 0) {
            if (allWarnings.length > 0) {
                App.showToast(`Остатки: ${totalUpdated} SKU, пропущено ${allWarnings.length} (нет баркода)`, 'warning');
            } else {
                App.showToast(`Остатки загружены: ${totalUpdated} SKU`, 'success');
            }
        } else if (totalUpdated > 0) {
            App.showToast(`Остатки: ${totalUpdated} OK, ${totalErrors} батчей с ошибками`, 'warning');
        } else if (totalErrors > 0) {
            console.error('[_uploadStocksAfterPrices] Все батчи завершились с ошибками');
        }
    },

    /**
     * Загрузка цен на Wildberries
     * Отправляет: price = "Цена для WB", discount = скидка артикула
     */
    async uploadPrices(articles) {
        // Пересчитываем price_for_wb для всех артикулов перед выгрузкой
        articles.forEach(a => {
            const markup = a.calculated_price || 0;
            const discount = a.calculated_discount || 90;
            a.price_for_wb = discount < 100 ? this.roundPrice(markup / (1 - discount / 100)) : markup;
        });

        const confirmMsg = `Загрузить цены для ${articles.length} артикулов на Wildberries?\n\nБудет отправлено: "Цена для WB" и индивидуальная скидка каждого артикула.`;

        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');
        if (!confirmed) return;

        try {
            // Формируем данные для отправки
            const allPricesData = articles.map(a => ({
                nmID: parseInt(a.nm_id),
                price: Math.round(a.price_for_wb),  // Цена для WB (до скидки)
                discount: Math.round(a.calculated_discount || 90),  // Скидка артикула
                vendor_code: a.vendor_code  // Для логирования
            }));

            // Фильтруем невалидные цены (WB отклоняет price <= 0)
            const pricesData = allPricesData.filter(item => {
                if (!item.price || item.price <= 0) {
                    console.warn('[uploadPrices] Пропускаем товар с невалидной ценой:', item);
                    return false;
                }
                return true;
            });

            const skippedCount = allPricesData.length - pricesData.length;
            if (skippedCount > 0) {
                console.warn(`[uploadPrices] Пропущено ${skippedCount} товаров с невалидными ценами`);
            }

            if (pricesData.length === 0) {
                App.showToast('Нет товаров с валидными ценами для загрузки', 'warning');
                return;
            }

            // Проверяем карантин перед загрузкой
            const nmIds = pricesData.map(p => p.nmID);
            console.log('[uploadPrices] Проверяем карантин для', nmIds.length, 'товаров...');
            const quarantineCheck = await this.checkQuarantine(nmIds);

            if (quarantineCheck.inQuarantine && quarantineCheck.inQuarantine.length > 0) {
                const quarantineList = quarantineCheck.inQuarantine.map(q =>
                    `nmID ${q.nmID} (${q.vendorCode}): ${q.reason}`
                ).join('\n');

                const continueUpload = await App.confirm(
                    `⚠️ Обнаружены товары в КАРАНТИНЕ WB!\n\n${quarantineList}\n\n` +
                    `Эти товары не смогут обновить цену через API.\n` +
                    `Освободите их в ЛК WB: Цены → Карантин\n\n` +
                    `Продолжить загрузку остальных ${quarantineCheck.clear?.length || 0} товаров?`,
                    'Карантин WB'
                );

                if (!continueUpload) return;

                // Фильтруем товары в карантине
                const quarantineNmIds = quarantineCheck.inQuarantine.map(q => q.nmID);
                const filteredPricesData = pricesData.filter(p => !quarantineNmIds.includes(p.nmID));

                if (filteredPricesData.length === 0) {
                    App.showToast('Все товары в карантине. Освободите их в ЛК WB.', 'warning');
                    return;
                }

                // Используем отфильтрованный список
                pricesData.length = 0;
                pricesData.push(...filteredPricesData);
            }

            // Логируем отправляемые данные
            console.log('[uploadPrices] Отправляем на WB:', pricesData.length, 'из', allPricesData.length);
            console.log('[uploadPrices] Данные:', pricesData);
            console.log('[uploadPrices] Артикулы:', articles.map(a => ({
                nm_id: a.nm_id,
                vendor_code: a.vendor_code,
                calculated_price: a.calculated_price,
                price_for_wb: a.price_for_wb,
                discount: a.calculated_discount
            })));

            const data = await App.fetch('/api/wb/upload-prices', {
                method: 'POST',
                body: { prices: pricesData.map(({vendor_code, ...rest}) => rest) }  // Убираем vendor_code перед отправкой
            });

            console.log('[uploadPrices] Ответ сервера:', data);

            // Обработка rate limit
            if (data.error_code === 'RATE_LIMIT' || data.error === 'rate_limit') {
                App.showToast(data.message || 'WB ограничивает частоту запросов. Подождите 5-10 минут.', 'warning');
                this.showUploadResults({
                    ...data,
                    errors: [{ nmID: 0, error: 'Rate Limit: подождите 5-10 минут перед повторной загрузкой' }]
                });
                return;
            }

            App.showToast(data.message || 'Цены загружены', data.success ? 'success' : 'warning');

            // Показываем результаты
            this.showUploadResults(data);

            // Сохраняем настройки наценок (чтобы при следующем входе восстановились)
            await this.saveSettings();

            // Сохраняем кастомные минимальные цены
            await this.saveCustomMinPrices(articles);

            // Сохраняем кастомные скидки
            await this.saveCustomDiscounts(articles);

            // Сохраняем текущие остатки перед перезагрузкой
            const savedStocks = {};
            this.articles.forEach(a => {
                if (a.stock !== undefined && a.stock !== null) {
                    savedStocks[a.mapping_id] = a.stock;
                }
            });

            // Перезагружаем артикулы для обновления статусов
            await this.loadArticles(this.selectedProduct.id);

            // Восстанавливаем остатки
            this.articles.forEach(a => {
                if (savedStocks[a.mapping_id] !== undefined) {
                    a.stock = savedStocks[a.mapping_id];
                }
            });

            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка загрузки: ' + error.message, 'danger');
        }
    },

    /**
     * Сохранение кастомных минимальных цен для артикулов
     */
    async saveCustomMinPrices(articles) {
        // Сохраняем только те, где цена была вручную отредактирована
        for (const article of articles) {
            if (article.min_price_edited && article.mapping_id) {
                try {
                    await App.fetch('/api/mapping/update-min-price', {
                        method: 'POST',
                        body: new URLSearchParams({
                            mapping_id: article.mapping_id,
                            min_price: article.calculated_price
                        })
                    });
                } catch (e) {
                    console.warn('Не удалось сохранить цену для mapping_id=' + article.mapping_id, e);
                }
            }
        }
    },

    /**
     * Сохранение кастомных скидок для артикулов
     */
    async saveCustomDiscounts(articles) {
        // Сохраняем только те, где скидка была вручную отредактирована
        for (const article of articles) {
            if (article.is_discount_edited && article.mapping_id) {
                try {
                    await App.fetch('/api/wb/mapping/update-discount', {
                        method: 'POST',
                        body: new URLSearchParams({
                            mapping_id: article.mapping_id,
                            discount: article.calculated_discount
                        })
                    });
                } catch (e) {
                    console.warn('Не удалось сохранить скидку для mapping_id=' + article.mapping_id, e);
                }
            }
        }
    },

    /**
     * Сохранение остатков в localStorage
     */
    saveStocksToStorage() {
        if (!this.selectedProduct?.id) return;

        const stocks = {};
        this.articles.forEach(a => {
            if (a.stock !== undefined && a.stock !== null && parseInt(a.stock) >= 0) {
                stocks[a.mapping_id] = parseInt(a.stock);
            }
        });

        localStorage.setItem('wb_stocks_' + this.selectedProduct.id, JSON.stringify(stocks));
        console.log('[saveStocksToStorage] Сохранено остатков:', Object.keys(stocks).length);
    },

    /**
     * Показать результаты загрузки
     */
    showUploadResults(data) {
        const card = document.getElementById('uploadResultsCard');
        const content = document.getElementById('uploadResultsContent');
        if (!card || !content) return;

        card.classList.remove('d-none');

        const sentCount = data.sent || data.updated || 0;
        const updatedCount = data.updated || 0;
        const errorCount = data.error_count || 0;
        const errors = data.errors || [];
        const warnings = data.warnings || [];
        const errorNmIds = data.error_nm_ids || [];
        const taskId = data.taskId || null;
        const alreadyExists = data.alreadyExists || false;

        // Определяем статус
        let statusIcon = 'bi-check-circle-fill text-success';
        let statusText = 'Успешно';
        if (errorCount > 0 && updatedCount > 0) {
            statusIcon = 'bi-exclamation-triangle-fill text-warning';
            statusText = 'Частично';
        } else if (errorCount > 0 && updatedCount === 0) {
            statusIcon = 'bi-x-circle-fill text-danger';
            statusText = 'Ошибка';
        } else if (alreadyExists) {
            statusIcon = 'bi-info-circle-fill text-info';
            statusText = 'Уже актуально';
        }

        // Находим артикулы с ошибками для отображения vendor_code
        const errorDetails = errors.map(e => {
            if (typeof e === 'object' && e.nmID) {
                const article = this.articles.find(a => parseInt(a.nm_id) === parseInt(e.nmID));
                return {
                    nmID: e.nmID,
                    vendorCode: article?.vendor_code || '?',
                    error: e.error || 'Неизвестная ошибка',
                    price: e.price,
                    discount: e.discount
                };
            }
            return { nmID: 0, vendorCode: '?', error: typeof e === 'string' ? e : JSON.stringify(e) };
        });

        // Подсвечиваем строки с ошибками в таблице
        if (errorNmIds.length > 0) {
            this.highlightErrorRows(errorNmIds);
        }

        content.innerHTML = `
            <div class="col-md-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-send text-primary display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Отправлено</div>
                        <div class="fs-4 fw-bold">${sentCount}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-currency-exchange text-success display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Обработано</div>
                        <div class="fs-4 fw-bold">${updatedCount}</div>
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
                    <i class="bi ${statusIcon} display-6 me-3"></i>
                    <div>
                        <div class="text-muted small">Статус</div>
                        <div class="fs-6">${statusText}${taskId ? ` #${taskId}` : ''}</div>
                    </div>
                </div>
            </div>
            ${errorDetails.length > 0 ? `
                <div class="col-12 mt-3">
                    <div class="alert alert-danger mb-0">
                        <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Ошибки WB (${errorDetails.length}):</h6>
                        <div class="table-responsive mt-2" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>nmID</th>
                                        <th>Артикул</th>
                                        <th>Ошибка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${errorDetails.map(e => `
                                        <tr>
                                            <td><code>${e.nmID}</code></td>
                                            <td><small>${App.escapeHtml(e.vendorCode)}</small></td>
                                            <td class="text-danger"><small>${App.escapeHtml(e.error)}</small></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            ` : ''}
            ${warnings.length > 0 ? `
                <div class="col-12 mt-3">
                    <div class="alert alert-warning mb-0">
                        <h6 class="alert-heading"><i class="bi bi-exclamation-circle me-2"></i>Пропущенные артикулы (${warnings.length}):</h6>
                        <small class="text-muted">Для загрузки остатков требуется баркод. Обновите товары WB.</small>
                        <div class="mt-2" style="max-height: 150px; overflow-y: auto;">
                            ${warnings.map(w => `<div class="small">${App.escapeHtml(w)}</div>`).join('')}
                        </div>
                    </div>
                </div>
            ` : ''}
        `;

        console.log('[showUploadResults] Показаны результаты:', { sentCount, updatedCount, errorCount, errorDetails });
    },

    /**
     * Подсветка строк с ошибками в таблице артикулов
     */
    highlightErrorRows(errorNmIds) {
        // Убираем предыдущую подсветку
        document.querySelectorAll('#articlesTable tbody tr.table-danger').forEach(row => {
            row.classList.remove('table-danger');
        });

        // Подсвечиваем строки с ошибками
        errorNmIds.forEach(nmId => {
            const row = document.querySelector(`#articlesTable tbody tr[data-nm-id="${nmId}"]`);
            if (row) {
                row.classList.add('table-danger');
                console.log(`[highlightErrorRows] Подсвечена строка nmID=${nmId}`);
            }
        });
    },

    /**
     * Проверить товары на карантин WB
     * @param {number[]} nmIds - Массив nmID для проверки
     * @returns {Promise<{inQuarantine: Array, clear: Array}>}
     */
    async checkQuarantine(nmIds) {
        if (!nmIds || nmIds.length === 0) {
            return { inQuarantine: [], clear: [] };
        }

        try {
            const result = await App.fetch('/api/wb/check-quarantine', {
                method: 'POST',
                body: { nm_ids: nmIds }
            });

            console.log('[checkQuarantine] Результат:', result);

            if (!result.success) {
                console.warn('[checkQuarantine] Ошибка проверки карантина:', result.error);
                // При ошибке считаем все товары "чистыми" чтобы не блокировать загрузку
                return { inQuarantine: [], clear: nmIds, error: result.error };
            }

            return {
                inQuarantine: result.inQuarantine || [],
                clear: result.clear || nmIds
            };
        } catch (e) {
            console.error('[checkQuarantine] Исключение:', e);
            // При ошибке считаем все товары "чистыми"
            return { inQuarantine: [], clear: nmIds, error: e.message };
        }
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
     * Сохранить одну запись в справочник раскроя
     */
    /**
     * Сохранить одну запись в справочник раскроя
     * @returns {boolean} true если успешно сохранено в БД
     */
    async saveToCuttingReference(sheetName, pieceWidth, pieceHeight, piecesCount) {
        // Определить размеры листа
        const sheetSelect = document.getElementById('wbSheetSelect');
        const selectedOption = sheetSelect?.selectedOptions[0];
        
        let sheetWidth = 1400, sheetHeight = 1030;
        if (selectedOption) {
            sheetWidth = parseInt(selectedOption.dataset.width) || 1400;
            sheetHeight = parseInt(selectedOption.dataset.height) || 1030;
        }
        
        console.log(`[saveToCuttingReference] Сохраняем: ${pieceWidth}×${pieceHeight} = ${piecesCount} → "${sheetName}"`);
        
        try {
            const response = await App.fetch('/api/cutting-reference/save', {
                method: 'POST',
                body: JSON.stringify({
                    sheet_name: sheetName,
                    sheet_width: sheetWidth,
                    sheet_height: sheetHeight,
                    items: [{
                        piece_width: pieceWidth,
                        piece_height: pieceHeight,
                        pieces_count: piecesCount
                    }]
                })
            });
            
            if (response.success) {
                console.log(`[saveToCuttingReference] ✅ Сохранено в БД: ${pieceWidth}×${pieceHeight} = ${piecesCount}`);
                return true;
            } else {
                console.error(`[saveToCuttingReference] ❌ Ошибка от сервера:`, response);
                return false;
            }
        } catch (error) {
            console.error('[saveToCuttingReference] ❌ Ошибка запроса:', error);
            return false;
        }
    },

    /**
     * Сохранить параметры упаковки
     * ВАЖНО: Сохраняет в БД для последующего использования в "Авто"
     */
    async savePackSettings() {
        const mappingId = document.getElementById('editPackMappingId').value;
        const piecesPerSheet = parseInt(document.getElementById('editPiecesPerSheet').value) || 1;
        const quantityInPack = parseInt(document.getElementById('editQuantityInPack').value) || 1;

        console.log(`[savePackSettings] Начало сохранения: mappingId=${mappingId}, pieces=${piecesPerSheet}, qty=${quantityInPack}`);

        try {
            // 1. Сохранить в таблицу маппингов
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

            // 2. Обновляем локальные данные
            const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
            if (article) {
                article.pieces_per_sheet = piecesPerSheet;
                article.quantity_in_pack = quantityInPack;
                
                // 3. Получить данные для справочников
                const sheetSelect = document.getElementById('wbSheetSelect');
                const sheetName = (sheetSelect?.selectedOptions[0]?.textContent || '').trim();
                const articleName = article.wb_name || article.vendor_code || '';
                // ПРИОРИТЕТ: используем vendor_code как уникальный ID артикула
                const articleId = article.vendor_code || article.nm_id || String(article.mapping_id);
                
                console.log(`[savePackSettings] Сохраняем в БД: articleId=${articleId}, pieces=${piecesPerSheet}, pack=${quantityInPack}`);
                
                // 4. Сохранить в справочник упаковки артикулов (article_packaging)
                await this.savePackagingToDb(articleId, articleName, piecesPerSheet, quantityInPack, sheetName);
                
                // 5. Сохранить в справочник раскроя (cutting_reference) по размерам
                if (sheetName) {
                    const dimensions = parseArticleDimensions(articleName);
                    
                    console.log(`[savePackSettings] Размеры:`, dimensions);
                    
                    if (dimensions && dimensions.width && dimensions.height && piecesPerSheet > 0) {
                        const saved = await this.saveToCuttingReference(sheetName, dimensions.width, dimensions.height, piecesPerSheet);
                        if (saved) {
                            App.showToast(`✅ Сохранено в справочник: ${dimensions.width}×${dimensions.height} = ${piecesPerSheet} шт`, 'success');
                        } else {
                            App.showToast('✅ Сохранено в справочник артикулов', 'success');
                        }
                    } else {
                        App.showToast('✅ Сохранено в справочник артикулов', 'success');
                    }
                } else {
                    App.showToast('✅ Сохранено', 'success');
                }
            }

            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack из артикулов WB (старый метод)
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
     * Автозаполнение pieces_per_sheet из справочника раскроя (новый метод)
     * Использует таблицы cutting_sheets и cutting_pieces
     * 
     * ПРАВИЛЬНАЯ ЛОГИКА:
     * - Артикулы берём из таблицы (привязаны к выбранному ТОВАРУ слева)
     * - Лист справа ("Другой 1400×1030") — это только параметр для расчёта количества деталей
     */
    async autoFillPieces() {
        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        // Проверяем, есть ли артикулы
        if (!this.articles || this.articles.length === 0) {
            App.showToast('В таблице нет артикулов', 'warning');
            return;
        }

        const sheetSelect = document.getElementById('wbSheetSelect');
        const selectedOption = sheetSelect?.selectedOptions[0];

        if (!selectedOption) {
            App.showToast('Выберите лист для расчёта', 'warning');
            return;
        }

        const baseWidth = parseInt(selectedOption.dataset.width) || 1520;
        const baseHeight = parseInt(selectedOption.dataset.height) || 1520;
        const sheetName = (selectedOption.textContent || '').trim();

        const btn = document.getElementById('wbAutoFillBtn');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            // 1. Загрузить справочник упаковки артикулов из БД
            await this.loadPackagingReference();

            // 2. Загрузить справочник раскроя (из БД или дефолтный)
            const cuttingReference = await this.loadCuttingReference(sheetName);

            let fromDb = 0;
            let fromCutting = 0;
            let fromServer = 0;
            const notFoundArticles = [];
            const alreadyFilled = new Set(); // Артикулы с данными из БД/раскроя

            console.log(`[autoFillPieces] Обработка ${this.articles.length} артикулов...`);
            console.log(`[autoFillPieces] Справочник артикулов: ${Object.keys(this.packagingReference).length} записей`);
            console.log(`[autoFillPieces] Справочник раскроя: ${Object.keys(cuttingReference).length} позиций`);

            for (const article of this.articles) {
                const articleName = article.wb_name || article.vendor_code || '';
                const articleId = article.vendor_code || article.nm_id || String(article.mapping_id);

                // Приоритет 1: Справочник упаковки артикулов (БД)
                if (this.packagingReference[articleId]?.pieces_per_sheet) {
                    article.pieces_per_sheet = this.packagingReference[articleId].pieces_per_sheet;
                    if (this.packagingReference[articleId].pack_quantity) {
                        article.quantity_in_pack = this.packagingReference[articleId].pack_quantity;
                    }
                    alreadyFilled.add(articleId); // Пометить как заполненный
                    console.log(`[autoFill] ✓ ${articleId} = ${article.pieces_per_sheet} (БД)`);
                    fromDb++;
                    continue;
                }

                // Приоритет 2: Справочник раскроя по размерам
                const dimensions = parseArticleDimensions(articleName);

                if (dimensions && dimensions.width && dimensions.height) {
                    const key1 = `${dimensions.width}x${dimensions.height}`;
                    const key2 = `${dimensions.height}x${dimensions.width}`;
                    const pieces = cuttingReference[key1] || cuttingReference[key2];

                    if (pieces && pieces > 0) {
                        article.pieces_per_sheet = pieces;
                        alreadyFilled.add(articleId); // Пометить как заполненный
                        console.log(`[autoFill] ✓ ${articleName} (${key1}) = ${pieces} (раскрой)`);
                        fromCutting++;
                        continue;
                    }
                }

                // Приоритет 3: Нужен расчёт на сервере
                console.log(`[autoFill] ? ${articleName}: не найден, требуется расчёт`);
                notFoundArticles.push(article);
            }

            // Проход 3: Если не найдено в справочниках → запрос к серверу
            // Отправляем ТОЛЬКО артикулы без данных
            if (notFoundArticles.length > 0) {
                console.log(`[autoFill] ${notFoundArticles.length} артикулов требуют расчёта на сервере`);

                // Собираем ID артикулов для расчёта
                const articleIdsForCalc = notFoundArticles.map(a =>
                    a.vendor_code || a.nm_id || String(a.mapping_id)
                );

                const result = await App.fetch('/api/wb/auto-fill-pieces', {
                    method: 'POST',
                    body: {
                        product_id: this.selectedProduct.id,
                        base_width: baseWidth,
                        base_height: baseHeight,
                        article_ids: articleIdsForCalc // Передаём список артикулов для расчёта
                    }
                });

                if (result.success && result.pieces_data) {
                    // Применяем результаты ТОЛЬКО к артикулам из notFoundArticles
                    for (const article of notFoundArticles) {
                        const articleId = article.vendor_code || article.nm_id || String(article.mapping_id);

                        // Дополнительная проверка — не перезаписывать если уже заполнено
                        if (!alreadyFilled.has(articleId) && result.pieces_data[articleId]) {
                            article.pieces_per_sheet = result.pieces_data[articleId];
                            console.log(`[autoFill] ✓ ${articleId} = ${result.pieces_data[articleId]} (сервер)`);
                            fromServer++;
                        }
                    }
                } else if (result.success) {
                    // Fallback: если сервер не вернул pieces_data
                    fromServer = result.updated || 0;
                    console.log(`[autoFill] Сервер обновил ${fromServer} артикулов (без детализации)`);
                }
            }

            const totalUpdated = fromDb + fromCutting + fromServer;
            console.log(`[autoFillPieces] Итого: БД=${fromDb}, раскрой=${fromCutting}, сервер=${fromServer}`);

            // Результат — НЕ перезагружаем артикулы, чтобы не потерять данные из БД
            if (totalUpdated > 0) {
                const sheetInfo = `(лист ${baseWidth}×${baseHeight})`;
                App.showToast(`✓ Обновлено ${totalUpdated} артикулов ${sheetInfo}`, 'success');

                // Только перерендерить таблицу и пересчитать цены
                this.renderArticlesTable();
                this.recalculatePrices();
            } else {
                App.showToast('Не удалось определить размеры артикулов', 'warning');
            }

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
            console.error('[autoFillPieces] Исключение:', error);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
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
            this.saveStocksToStorage();
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

            this.saveStocksToStorage();
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
        this.saveStocksToStorage();
        App.showToast('Все остатки обнулены', 'info');
    },

    /**
     * Загрузка ТОЛЬКО остатков на WB
     * RETRY v3: батчи по 10, retry при 409, задержка 2 сек между батчами
     */
    async uploadStocksOnly() {
        console.log('[uploadStocksOnly] ===== ВЕРСИЯ С RETRY v3 =====');
        console.log('[uploadStocksOnly] Начало загрузки остатков');

        // Определяем какие артикулы загружать
        let articlesToUpload = [];
        if (this.selectedArticles.size > 0) {
            articlesToUpload = this.articles.filter(a => this.selectedArticles.has(String(a.mapping_id)));
            console.log('[uploadStocksOnly] Выбрано артикулов:', articlesToUpload.length);
        } else {
            articlesToUpload = this.articles;
            console.log('[uploadStocksOnly] Загружаем все артикулы:', articlesToUpload.length);
        }

        if (articlesToUpload.length === 0) {
            App.showToast('Нет товаров для загрузки остатков', 'warning');
            return;
        }

        // Проверяем выбран ли склад
        const warehouseId = document.getElementById('warehouseSelect')?.value;
        if (!warehouseId) {
            App.showToast('Выберите склад для загрузки остатков', 'warning');
            return;
        }

        // Фильтруем артикулы с остатками >= 0 (включая обнуление)
        // ВАЖНО: Исключаем КГТ-товары (is_oversized) — они не принимаются складом
        const articlesWithStock = articlesToUpload.filter(a =>
            a.stock !== undefined && a.stock !== null && !a.is_oversized
        );

        // Считаем сколько КГТ было пропущено
        const oversizedCount = articlesToUpload.filter(a => a.is_oversized && a.stock !== undefined && a.stock !== null).length;
        if (oversizedCount > 0) {
            console.log(`[uploadStocksOnly] Пропущено КГТ-товаров: ${oversizedCount}`);
        }

        if (articlesWithStock.length === 0) {
            if (oversizedCount > 0) {
                App.showToast(`Все ${oversizedCount} товаров с остатками — КГТ (крупногабарит), склад не принимает`, 'warning');
            } else {
                App.showToast('Нет товаров с указанными остатками', 'warning');
            }
            return;
        }

        // Подтверждение
        let confirmMsg = `Загрузить остатки для ${articlesWithStock.length} артикулов на Wildberries?\n\nСклад: ${warehouseId}`;
        if (oversizedCount > 0) {
            confirmMsg += `\n\n⚠️ КГТ-товары (${oversizedCount} шт) будут пропущены — склад не принимает крупногабарит.`;
        }
        const confirmed = await App.confirm(confirmMsg, 'Загрузка остатков');
        if (!confirmed) return;

        // Блокируем кнопку
        const btn = document.getElementById('uploadStocksOnlyBtn');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Загрузка...';
        }

        try {
            // Формируем массив остатков (только не-КГТ)
            const stocks = articlesWithStock.map(article => ({
                sku: article.barcode || article.vendor_code || '',
                nm_id: article.nm_id,
                amount: parseInt(article.stock) || 0
            }));

            console.log('[uploadStocksOnly] ★★★ RETRY v3 АКТИВЕН ★★★');
            console.log('[uploadStocksOnly] Всего SKU (без КГТ):', stocks.length);

            // Разбиваем на батчи по 10 штук (уменьшено с 20)
            const batchSize = 10;
            const batches = [];
            for (let i = 0; i < stocks.length; i += batchSize) {
                batches.push(stocks.slice(i, i + batchSize));
            }

            console.log('[uploadStocksOnly] Размер батча:', batchSize);
            console.log('[uploadStocksOnly] Батчей будет:', batches.length);
            console.log('[uploadStocksOnly] Склад:', warehouseId);

            let totalUpdated = 0;
            let totalErrors = 0;
            let allWarnings = [];  // Собираем предупреждения о пропущенных артикулах
            const maxRetries = 3;
            const retryDelay = 5000;  // 5 секунд между retry
            const batchDelay = 2000;  // 2 секунды между батчами

            for (let i = 0; i < batches.length; i++) {
                const batch = batches[i];

                // Обновляем прогресс на кнопке
                if (btn) {
                    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Батч ${i + 1}/${batches.length}...`;
                }

                console.log(`[uploadStocksOnly] Батч ${i + 1}/${batches.length}: ${batch.length} шт.`);

                let success = false;
                let lastError = null;

                // Retry логика
                for (let attempt = 1; attempt <= maxRetries && !success; attempt++) {
                    if (attempt > 1) {
                        console.log(`[uploadStocksOnly] Retry ${attempt}/${maxRetries} через ${retryDelay/1000} сек...`);
                        if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Retry ${attempt}...`;
                        await new Promise(resolve => setTimeout(resolve, retryDelay));
                    }

                    try {
                        const result = await App.fetch('/api/wb/upload-stocks', {
                            method: 'POST',
                            body: {
                                warehouse_id: parseInt(warehouseId),
                                stocks: batch
                            }
                        });

                        console.log(`[uploadStocksOnly] Батч ${i + 1} попытка ${attempt}:`, result);

                        if (result.success) {
                            totalUpdated += result.updated || batch.length;
                            success = true;
                            // Собираем warnings (пропущенные артикулы без баркодов и т.д.)
                            if (result.warnings && Array.isArray(result.warnings)) {
                                allWarnings = allWarnings.concat(result.warnings);
                            }
                        } else if (result.error && result.error.includes('CargoWarehouseRestriction')) {
                            // КГТ-товары — склад не принимает крупногабарит, retry бесполезен
                            lastError = result.error;
                            console.warn(`[uploadStocksOnly] КГТ-ошибка (склад не принимает крупногабарит), пропускаем батч`);
                            if (result.marked_as_oversized > 0) {
                                console.log(`[uploadStocksOnly] Помечено КГТ: ${result.marked_as_oversized} товаров`);
                            }
                            break; // Не делаем retry — это постоянная ошибка
                        } else if (result.error && result.error.includes('409')) {
                            // Другие HTTP 409 - нужен retry
                            lastError = result.error;
                            console.warn(`[uploadStocksOnly] HTTP 409, retry...`);
                        } else if (result.error && result.error.includes('429')) {
                            // Rate limit - увеличенная задержка
                            lastError = result.error;
                            console.warn(`[uploadStocksOnly] Rate limit, ждём 10 сек...`);
                            await new Promise(resolve => setTimeout(resolve, 10000));
                        } else {
                            // Другая ошибка - не retry
                            lastError = result.error;
                            console.error(`[uploadStocksOnly] Ошибка без retry:`, result.error);
                            break;
                        }
                    } catch (err) {
                        lastError = err.message;
                        console.error(`[uploadStocksOnly] Exception:`, err);
                    }
                }

                if (!success) {
                    totalErrors++;
                    console.error(`[uploadStocksOnly] Батч ${i + 1} FAILED после ${maxRetries} попыток:`, lastError);
                }

                // Пауза между батчами (кроме последнего)
                if (i < batches.length - 1) {
                    console.log(`[uploadStocksOnly] Пауза ${batchDelay/1000} сек...`);
                    await new Promise(resolve => setTimeout(resolve, batchDelay));
                }
            }

            // Показываем результаты
            this.showUploadResults({
                success: totalErrors === 0 || totalUpdated > 0,
                sent: stocks.length,
                updated: totalUpdated,
                errors: totalErrors > 0 ? [`Ошибки в ${totalErrors} батчах`] : [],
                error_count: totalErrors,
                warnings: allWarnings
            });

            // Итоговое уведомление
            if (totalErrors === 0 && totalUpdated > 0) {
                App.showToast(`Остатки загружены: ${totalUpdated} SKU`, 'success');
            } else if (totalUpdated > 0) {
                App.showToast(`Загружено ${totalUpdated} SKU, ошибки в ${totalErrors} батчах`, 'warning');
            } else {
                App.showToast('Ошибка загрузки остатков', 'danger');
            }

            // Сохраняем в localStorage
            this.saveStocksToStorage();

        } catch (error) {
            console.error('[uploadStocksOnly] Исключение:', error);
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
     * Шаг округления для диапазона 1000-9999₽ уменьшен до 10₽
     * для максимальной чувствительности к изменениям наценки (~1%)
     */
    roundPrice(price) {
        // Защита от нулевых и отрицательных цен (WB отклоняет такие)
        if (!price || price <= 0) {
            console.warn('[roundPrice] Невалидная цена:', price, '→ возвращаем 0');
            return 0;
        }

        let result;
        if (price < 100) {
            // До 100₽ — шаг 10₽: 9, 19, 29... 89, 99
            result = Math.ceil(price / 10) * 10 - 1;
        } else if (price < 500) {
            // 100-499₽ — шаг 50₽: 49, 99, 149, 199... 449, 499
            const r = price % 100;
            result = r < 50 ? Math.floor(price / 100) * 100 + 49 : Math.floor(price / 100) * 100 + 99;
        } else if (price < 1000) {
            // 500-999₽ — шаг 100₽: 499, 599, 699, 799, 899, 999
            result = Math.ceil(price / 100) * 100 - 1;
        } else if (price < 10000) {
            // 1000-9999₽ — шаг 10₽: 999, 1009, 1019... 9989, 9999
            result = Math.ceil(price / 10) * 10 - 1;
        } else if (price < 100000) {
            // 10000-99999₽ — шаг 100₽: 9999, 10099, 10199...
            result = Math.ceil(price / 100) * 100 - 1;
        } else {
            // 100000₽+ — шаг 1000₽
            result = Math.ceil(price / 1000) * 1000 - 1;
        }
        console.log(`[roundPrice] ${price} → ${result}`);
        return result;
    }
};

/**
 * Справочник раскроя листов для Wildberries
 * Управляет соотношениями: исходный лист → размер кусочка → количество
 * Использует универсальные API endpoints /api/cutting/*
 */
const WBCuttingReference = {
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
     * Флаг загрузки (для предотвращения повторных запросов)
     */
    loading: false,
    loaded: false,

    /**
     * Инициализация модуля
     */
    init() {
        console.log('WBCuttingReference.init() called');
        this.bindEvents();
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        // Переключение на вкладку - загрузка листов
        const cuttingTab = document.getElementById('wb-cutting-tab');
        console.log('WBCuttingReference.bindEvents(): cuttingTab =', cuttingTab);

        if (cuttingTab) {
            // Bootstrap событие (основное) - срабатывает ПОСЛЕ показа вкладки
            cuttingTab.addEventListener('shown.bs.tab', () => {
                console.log('WBCuttingReference: shown.bs.tab event fired');
                if (!this.loaded && !this.loading) {
                    this.loadSheets();
                }
            });

            // Клик по вкладке - запасной вариант с задержкой для Bootstrap
            cuttingTab.addEventListener('click', () => {
                console.log('WBCuttingReference: click event, loaded=' + this.loaded + ', loading=' + this.loading);
                if (!this.loaded && !this.loading) {
                    // Ждём пока Bootstrap покажет панель
                    setTimeout(() => {
                        if (!this.loaded && !this.loading) {
                            console.log('WBCuttingReference: click fallback - loading sheets');
                            this.loadSheets();
                        }
                    }, 200);
                }
            });
        } else {
            console.error('WBCuttingReference: wb-cutting-tab element NOT FOUND!');
        }

        // Автоподстановка размеров при выборе типа материала
        document.getElementById('wbNewSheetType')?.addEventListener('change', (e) => {
            this.autoFillSheetSize(e.target.value);
        });

        // Добавить лист
        document.getElementById('wbBtnAddSheet')?.addEventListener('click', () => this.addSheet());

        // Добавить размер кусочка
        document.getElementById('wbBtnAddPiece')?.addEventListener('click', () => this.showAddPieceModal());

        // Загрузить размеры из артикулов
        document.getElementById('wbBtnLoadFromArticles')?.addEventListener('click', () => this.loadFromArticles());

        // Сохранить изменения
        document.getElementById('wbBtnSavePieces')?.addEventListener('click', () => this.savePieces());

        // Сохранить новый размер
        document.getElementById('wbBtnSaveNewPiece')?.addEventListener('click', () => this.saveNewPiece());

        // Пресет размера кусочка
        document.getElementById('wbAddPiecePreset')?.addEventListener('change', (e) => {
            if (e.target.value) {
                const [w, h] = e.target.value.split('x').map(Number);
                document.getElementById('wbAddPieceWidth').value = w;
                document.getElementById('wbAddPieceHeight').value = h;
                this.updateAddPieceCalc();
            }
        });

        // Live preview при вводе размеров
        ['wbAddPieceWidth', 'wbAddPieceHeight'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => this.updateAddPieceCalc());
        });
    },

    /**
     * Автоподстановка размеров листа
     */
    autoFillSheetSize(materialType) {
        const size = this.sheetSizes[materialType];
        if (size) {
            document.getElementById('wbNewSheetWidth').value = size.w;
            document.getElementById('wbNewSheetHeight').value = size.h;
        }
    },

    /**
     * Загрузить список листов
     */
    async loadSheets() {
        console.log('WBCuttingReference.loadSheets() called, loading=' + this.loading);

        // Предотвращаем параллельные запросы
        if (this.loading) {
            console.log('WBCuttingReference: already loading, skipping');
            return;
        }

        this.loading = true;

        try {
            const response = await App.fetch('/api/cutting/sheets');
            console.log('WBCuttingReference: API response:', response);

            if (response.success) {
                this.sheets = response.sheets || [];
                this.loaded = true;
                console.log('WBCuttingReference: sheets loaded successfully, count=' + this.sheets.length);
                this.renderSheetsList();
            } else {
                console.error('WBCuttingReference: API returned error:', response);
                // Показываем сообщение об ошибке в контейнере
                const container = document.getElementById('wbSheetsList');
                if (container) {
                    container.innerHTML = '<div class="text-center text-danger py-3">Ошибка загрузки</div>';
                }
            }
        } catch (e) {
            console.error('WBCuttingReference: Ошибка загрузки листов:', e);
            App.showToast('Ошибка загрузки листов', 'danger');
            // Показываем сообщение об ошибке в контейнере
            const container = document.getElementById('wbSheetsList');
            if (container) {
                container.innerHTML = '<div class="text-center text-danger py-3">Ошибка загрузки</div>';
            }
        } finally {
            this.loading = false;
        }
    },

    /**
     * Отрисовать список листов
     */
    renderSheetsList() {
        const container = document.getElementById('wbSheetsList');
        console.log('WBCuttingReference.renderSheetsList(): container=', container, 'sheets=', this.sheets.length);
        if (!container) {
            console.error('WBCuttingReference: container #wbSheetsList not found!');
            return;
        }

        if (this.sheets.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-3">Нет листов. Добавьте первый!</div>';
            return;
        }

        container.innerHTML = this.sheets.map(sheet => `
            <a href="#" class="list-group-item list-group-item-action bg-dark border-secondary ${sheet.id == this.selectedSheetId ? 'active' : ''}"
               data-sheet-id="${sheet.id}" onclick="WBCuttingReference.selectSheet(${sheet.id}); return false;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${this.escapeHtml(sheet.material_name)}</strong><br>
                        <small class="text-muted">${sheet.sheet_width}×${sheet.sheet_height} мм</small>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="event.preventDefault(); event.stopPropagation(); WBCuttingReference.deleteSheet(${sheet.id});">
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
        const type = document.getElementById('wbNewSheetType').value;
        const typeSelect = document.getElementById('wbNewSheetType');
        const name = typeSelect.options[typeSelect.selectedIndex].text;
        const width = parseInt(document.getElementById('wbNewSheetWidth').value) || 0;
        const height = parseInt(document.getElementById('wbNewSheetHeight').value) || 0;

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
                this.loaded = false; // Сбрасываем флаг для перезагрузки
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
                    document.getElementById('wbSelectedSheetName').textContent = 'выберите лист';
                    document.getElementById('wbBtnAddPiece').disabled = true;
                    document.getElementById('wbBtnLoadFromArticles').disabled = true;
                    document.getElementById('wbBtnSavePieces').disabled = true;
                }
                this.loaded = false; // Сбрасываем флаг для перезагрузки
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
        document.getElementById('wbBtnAddPiece').disabled = false;
        document.getElementById('wbBtnLoadFromArticles').disabled = false;
        document.getElementById('wbBtnSavePieces').disabled = false;

        // Загружаем раскрой
        try {
            const response = await App.fetch(`/api/cutting/pieces?sheet_id=${sheetId}`);
            if (response.success) {
                this.selectedSheet = response.sheet;
                this.pieces = response.pieces || [];
                document.getElementById('wbSelectedSheetName').textContent =
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
        const tbody = document.getElementById('wbPiecesTableBody');
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
                        <input type="number" class="form-control form-control-sm wb-piece-actual-qty text-center"
                               value="${piece.actual_qty}" min="1" style="width: 80px; display: inline-block;">
                        ${diff ? '<i class="bi bi-exclamation-triangle text-warning ms-1" title="Отличается от авто-расчёта"></i>' : ''}
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-secondary btn-sm me-1"
                                onclick="WBCuttingReference.editPiece(${piece.id})" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="WBCuttingReference.deletePiece(${piece.id})" title="Удалить">
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

        // Очищаем поля
        document.getElementById('wbAddPieceName').value = '';
        document.getElementById('wbAddPiecePreset').value = '';
        document.getElementById('wbAddPieceWidth').value = '';
        document.getElementById('wbAddPieceHeight').value = '';
        document.getElementById('wbAddPieceQty').value = '';
        document.getElementById('wbAddPieceCalc').textContent = '-';

        const modal = new bootstrap.Modal(document.getElementById('wbAddPieceModal'));
        modal.show();
    },

    /**
     * Обновить авто-расчёт в модалке
     */
    updateAddPieceCalc() {
        if (!this.selectedSheet) return;

        const pieceW = parseInt(document.getElementById('wbAddPieceWidth')?.value) || 0;
        const pieceH = parseInt(document.getElementById('wbAddPieceHeight')?.value) || 0;

        if (pieceW > 0 && pieceH > 0) {
            const calc = this.calculatePieces(
                this.selectedSheet.sheet_width, this.selectedSheet.sheet_height,
                pieceW, pieceH
            );
            document.getElementById('wbAddPieceCalc').textContent = calc;
            document.getElementById('wbAddPieceQty').value = calc;
        } else {
            document.getElementById('wbAddPieceCalc').textContent = '-';
        }
    },

    /**
     * Сохранить новый размер
     */
    async saveNewPiece() {
        const nameEl = document.getElementById('wbAddPieceName');
        const widthEl = document.getElementById('wbAddPieceWidth');
        const heightEl = document.getElementById('wbAddPieceHeight');
        const qtyEl = document.getElementById('wbAddPieceQty');

        const name = nameEl?.value?.trim() || '';
        const width = parseInt(widthEl?.value) || 0;
        const height = parseInt(heightEl?.value) || 0;
        let qty = parseInt(qtyEl?.value) || 0;

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
                bootstrap.Modal.getInstance(document.getElementById('wbAddPieceModal'))?.hide();
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
     * Загрузить размеры из артикулов WB
     */
    async loadFromArticles() {
        if (!this.selectedSheetId) {
            App.showToast('Сначала выберите лист слева', 'warning');
            return;
        }

        const btn = document.getElementById('wbBtnLoadFromArticles');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            // Используем универсальный endpoint, который берёт размеры из обоих маркетплейсов
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
        const rows = document.querySelectorAll('#wbPiecesTableBody tr[data-piece-id]');
        const pieces = [];

        rows.forEach(row => {
            const input = row.querySelector('.wb-piece-actual-qty');
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
        const row = document.querySelector(`#wbPiecesTableBody tr[data-piece-id="${pieceId}"]`);
        if (!row) {
            App.showToast('Размер не найден', 'danger');
            return;
        }

        document.getElementById('wbEditPieceId').value = pieceId;
        document.getElementById('wbEditPieceName').value = row.dataset.pieceName || '';
        document.getElementById('wbEditPieceWidth').value = row.dataset.pieceWidth || '';
        document.getElementById('wbEditPieceHeight').value = row.dataset.pieceHeight || '';
        document.getElementById('wbEditPieceQty').value = row.dataset.actualQty || '';

        const modal = new bootstrap.Modal(document.getElementById('wbEditPieceModal'));
        modal.show();
    },

    /**
     * Сохранить изменения размера
     */
    async updatePiece() {
        const pieceId = document.getElementById('wbEditPieceId').value;
        const name = document.getElementById('wbEditPieceName').value.trim();
        const width = parseInt(document.getElementById('wbEditPieceWidth').value) || 0;
        const height = parseInt(document.getElementById('wbEditPieceHeight').value) || 0;
        let qty = parseInt(document.getElementById('wbEditPieceQty').value) || 0;

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
                bootstrap.Modal.getInstance(document.getElementById('wbEditPieceModal'))?.hide();
                this.selectSheet(this.selectedSheetId);
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
        // Минимальный размер кусочка — 50мм
        if (!pieceW || !pieceH || pieceW < 50 || pieceH < 50) {
            return 1;
        }
        // Вариант 1: стандартная ориентация
        const total1 = Math.floor(sheetW / pieceW) * Math.floor(sheetH / pieceH);
        // Вариант 2: повёрнутая ориентация
        const total2 = Math.floor(sheetW / pieceH) * Math.floor(sheetH / pieceW);
        // Максимум 10000
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
    WBCalculator.init();
    WBCuttingReference.init();
});
