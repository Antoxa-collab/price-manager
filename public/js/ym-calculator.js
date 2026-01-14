/**
 * Price Manager - Калькулятор цен Яндекс.Маркет
 * Расчёт и загрузка цен на маркетплейс
 */

const YMCalculator = {
    // Данные
    products: [],           // Товары с сопоставлениями
    articles: [],           // Артикулы выбранного товара
    warehouses: [],         // Склады ЯМ
    selectedProduct: null,  // Выбранный товар
    selectedArticles: new Set(), // Выбранные артикулы для загрузки
    syncStats: null,        // Статистика синхронизации
    
    // Сортировка таблицы
    sortColumn: null,       // 'article' или 'name'
    sortDirection: 'asc',   // 'asc' или 'desc'
    sortStorageKey: 'ym_calculator_sort', // Ключ localStorage
    
    // Справочник упаковки по артикулам
    packagingReference: {},

    /**
     * Инициализация модуля
     */
    init() {
        console.log('YMCalculator.init() started');
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
        this.loadPackagingReference(); // Загрузка справочника упаковки по артикулам
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
                const discountBaseMultiplier = document.getElementById('discountBaseMultiplier');

                // Загружаем только если значения были сохранены (не дефолтные нули)
                if (data.settings.markup_min > 0 && markupMin) {
                    markupMin.value = data.settings.markup_min;
                }
                if (data.settings.discount_base_multiplier > 0 && discountBaseMultiplier) {
                    discountBaseMultiplier.value = data.settings.discount_base_multiplier;
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
        const discountBaseMultiplier = parseFloat(document.getElementById('discountBaseMultiplier')?.value) || 10;

        try {
            await App.fetch('/api/calculator/settings', {
                method: 'POST',
                body: new URLSearchParams({
                    marketplace: 'yandex',
                    markup_min: markupMin,
                    markup_extra: 0, // ЯМ не использует доп.наценку
                    discount_base_multiplier: discountBaseMultiplier
                })
            });
            console.log('YMCalculator: settings saved');
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
                valA = (a.offer_id || a.vendor_code || a.article || '').toString().toLowerCase();
                valB = (b.offer_id || b.vendor_code || b.article || '').toString().toLowerCase();
            } else if (this.sortColumn === 'name') {
                valA = (a.ym_name || a.name || a.title || '').toLowerCase();
                valB = (b.ym_name || b.name || b.title || '').toLowerCase();
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
        document.querySelectorAll('#ymArticlesTable th.sortable').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        
        if (this.sortColumn) {
            const activeHeader = document.querySelector(`#ymArticlesTable th[data-sort="${this.sortColumn}"]`);
            if (activeHeader) {
                activeHeader.classList.add(this.sortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        }
    },

    /**
     * Загрузка справочника упаковки по артикулам из БД
     */
    async loadPackagingReference() {
        try {
            const response = await App.fetch('/api/article-packaging/list');
            if (response.success && response.data) {
                this.packagingReference = response.data;
                console.log('[loadPackagingReference] Загружено записей:', Object.keys(this.packagingReference).length);
            }
        } catch (error) {
            console.warn('[loadPackagingReference] Ошибка загрузки:', error);
            this.packagingReference = {};
        }
        return this.packagingReference;
    },

    /**
     * Сохранение упаковки артикула в БД
     */
    async savePackagingToDb(articleId, articleName, piecesPerSheet, packQuantity, sheetName) {
        if (!articleId || !piecesPerSheet) {
            console.warn('[savePackagingToDb] Недостаточно данных для сохранения');
            return false;
        }
        
        try {
            const response = await App.fetch('/api/article-packaging/save', {
                method: 'POST',
                body: {
                    article_id: articleId,
                    article_name: articleName || '',
                    pieces_per_sheet: piecesPerSheet,
                    pack_quantity: packQuantity || 1,
                    sheet_name: sheetName || ''
                }
            });
            
            if (response.success) {
                // Обновляем локальный кэш
                this.packagingReference[articleId] = {
                    pieces_per_sheet: piecesPerSheet,
                    pack_quantity: packQuantity || 1,
                    sheet_name: sheetName || ''
                };
                console.log(`[savePackagingToDb] Сохранено: ${articleId} = ${piecesPerSheet} шт`);
                return true;
            }
        } catch (error) {
            console.error('[savePackagingToDb] Ошибка:', error);
        }
        return false;
    },

    /**
     * Инициализация функционала массовой скидки
     */
    initBulkDiscount() {
        const applyBtn = document.getElementById('ymApplyBulkDiscount');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyBulkDiscount());
        }
    },

    /**
     * Массовое применение скидки ко всем артикулам текущего товара
     */
    async applyBulkDiscount() {
        const discountInput = document.getElementById('ymBulkDiscount');
        const discount = parseFloat(discountInput?.value) || 0;

        if (discount < 0 || discount > 100) {
            App.showToast('Скидка должна быть от 0 до 100%', 'warning');
            return;
        }

        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        try {
            const result = await App.fetch('/api/yandex/bulk-discount', {
                method: 'POST',
                body: {
                    product_id: this.selectedProduct.id,
                    discount: discount
                }
            });

            if (result.success) {
                App.showToast(`Скидка ${discount}% применена к ${result.updated || 0} артикулам`, 'success');
                // Обновляем артикулы
                await this.loadArticles(this.selectedProduct.id);
                this.recalculatePrices();
            } else {
                App.showToast('Ошибка: ' + (result.message || 'неизвестная'), 'danger');
            }
        } catch (error) {
            console.error('applyBulkDiscount error:', error);
            App.showToast('Ошибка применения скидки: ' + error.message, 'danger');
        }
    },

    /**
     * Инициализация функционала минимальной цены
     */
    initMinPriceThreshold() {
        const btn = document.getElementById('ymApplyMinPrice');
        console.log('[initMinPriceThreshold] btn:', btn);

        if (!btn) {
            console.error('ОШИБКА: кнопка ymApplyMinPrice не найдена!');
            return;
        }

        btn.onclick = () => {
            console.log('>>> КЛИК ПО КНОПКЕ МИН.ЦЕНЫ <<<');
            this.applyMinPriceThreshold();
        };

        console.log('[initMinPriceThreshold] onclick привязан');
    },

    /**
     * ПРОСТАЯ версия: поднять все цены ниже минимума
     * БЕЗ округления - просто подставляем значение
     */
    applyMinPriceThreshold() {
        // 1. Получить значение минимальной цены
        const minPriceInput = document.getElementById('ymMinPriceThreshold');
        const minPrice = parseFloat(minPriceInput?.value);

        if (!minPrice || minPrice <= 0) {
            App.showToast('Укажите минимальную цену больше 0', 'warning');
            return;
        }

        // 2. Найти все input с ценами в таблице
        const priceInputs = document.querySelectorAll('.price-input');

        if (priceInputs.length === 0) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        // 3. Пройти по каждому input и поднять цену если нужно
        let updated = 0;

        priceInputs.forEach(input => {
            const currentPrice = parseFloat(input.value) || 0;

            if (currentPrice > 0 && currentPrice < minPrice) {
                input.value = minPrice;  // Просто подставляем значение БЕЗ округления
                updated++;
            }
        });

        if (updated > 0) {
            App.showToast(`Поднято цен: ${updated}`, 'success');
        } else {
            App.showToast('Все цены уже выше минимума', 'info');
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
        // При изменении множителя зачёркнутой цены - обычный пересчёт
        document.getElementById('discountBaseMultiplier')?.addEventListener('input', () => this.recalculatePrices());

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
        document.getElementById('ymAutoFillBtn')?.addEventListener('click', () => this.autoFillPieces());

        // Управление остатками
        document.getElementById('applyBulkStockBtn')?.addEventListener('click', () => this.applyBulkStock(false));
        document.getElementById('applyAllStockBtn')?.addEventListener('click', () => this.applyBulkStock(true));
        document.getElementById('zeroStockBtn')?.addEventListener('click', () => this.zeroAllStocks());

        // Загрузка только остатков
        document.getElementById('uploadStocksOnlyBtn')?.addEventListener('click', () => this.uploadStocksOnly());

        // Синхронизация с ЯМ
        document.getElementById('syncYmBtn')?.addEventListener('click', () => this.syncWithYM());

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
     * Загрузка складов ЯМ
     */
    async loadWarehouses() {
        console.log('[loadWarehouses] Начинаем загрузку складов...');
        try {
            const response = await App.fetch('/api/yandex/warehouses');
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
                    console.warn('  2. Нет FBS складов в ЛК Яндекс.Маркет');
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

        // Получаем сохранённый склад
        const savedWarehouseId = localStorage.getItem('ym_selected_warehouse');
        let hasSelectedWarehouse = false;

        this.warehouses.forEach((wh, index) => {
            const option = document.createElement('option');
            option.value = wh.id;
            option.textContent = wh.name;
            // Автовыбор сохранённого склада
            if (savedWarehouseId && String(wh.id) === savedWarehouseId) {
                option.selected = true;
                hasSelectedWarehouse = true;
            }
            select.appendChild(option);
        });

        // Если склад не был сохранён — автоматически выбираем первый
        if (!hasSelectedWarehouse && this.warehouses.length > 0) {
            select.value = this.warehouses[0].id;
            localStorage.setItem('ym_selected_warehouse', select.value);
            console.log('[renderWarehouseSelect] Автовыбор первого склада:', select.value);
        }

        // Сохраняем выбор склада при изменении
        select.removeEventListener('change', this._warehouseChangeHandler);
        this._warehouseChangeHandler = () => {
            localStorage.setItem('ym_selected_warehouse', select.value);
            console.log('[renderWarehouseSelect] Склад сохранён:', select.value);
        };
        select.addEventListener('change', this._warehouseChangeHandler);

        console.log('[renderWarehouseSelect] Отрисовано складов:', this.warehouses.length, ', сохранённый:', savedWarehouseId, ', выбран:', select.value);
    },

    /**
     * Загрузка списка НАШИХ товаров с сопоставлениями ЯМ
     */
    async loadProducts() {
        console.log('loadProducts() called');
        try {
            // Загружаем НАШИ товары из таблицы products (не товары ЯМ!)
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

        // Очищаем и добавляем placeholder
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
            // Используем name из таблицы products (НАШИ товары)
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
            // Загружаем артикулы ЯМ связанные с нашим товаром
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
            const data = await App.fetch(`/api/mapping/min-prices?product_id=${productId}&marketplace=yandex`);
            if (data.success && data.prices) {
                // data.prices = { mapping_id: min_price, ... }
                this.articles.forEach(article => {
                    if (data.prices[article.mapping_id]) {
                        article.custom_min_price = data.prices[article.mapping_id];
                        article.has_custom_min_price = true;
                    }
                });
                console.log('YMCalculator: custom min prices loaded', data.prices);
            }
        } catch (e) {
            console.warn('Не удалось загрузить кастомные мин. цены:', e);
        }
    },

    /**
     * Пересчёт цен
     * Формула для ЯМ:
     * cost = (cost_price / pieces_per_sheet) × quantity_in_pack
     * price_before_discount = cost × (1 + markup_min / 100)
     * price_after_discount = price_before_discount × (1 - ym_discount / 100)
     *
     * @param {boolean} forceRecalc - Принудительно пересчитать все артикулы, игнорируя кастомные цены
     */
    recalculatePrices(forceRecalc = false) {
        if (!this.selectedProduct) return;

        if (forceRecalc) {
            console.log('[recalculatePrices] FORCE RECALC - игнорируем кастомные цены');
        }

        const markupPercent = parseFloat(document.getElementById('markupMin').value) || 0;
        const multiplier = parseFloat(document.getElementById('discountBaseMultiplier')?.value) || 10;
        const costPrice = this.selectedProduct.cost_price || 0;
        const basePrice = this.selectedProduct.base_price || costPrice;

        // Расчёт для базовой единицы (1 шт из листа)
        const unitCost = costPrice;
        const unitPrice = this.roundPrice(unitCost * (1 + markupPercent / 100));  // Цена (price.value)
        const unitDiscountBase = this.roundPrice(unitPrice * multiplier);  // Зачёркнутая (price.discountBase)

        // Показываем блок с ценами для 1 листа/единицы
        document.getElementById('calculatedPricesBlock')?.classList.remove('d-none');
        document.getElementById('calcCostPrice').textContent = App.formatPrice(costPrice);
        document.getElementById('calcBasePrice').textContent = App.formatPrice(basePrice);
        document.getElementById('calcMinPrice').textContent = App.formatPrice(unitPrice);  // Цена
        document.getElementById('calcFinalPrice').textContent = App.formatPrice(unitDiscountBase);  // Зачёркнуто

        // Пересчитываем цены для каждого артикула
        this.articles.forEach(article => {
            const piecesPerSheet = article.pieces_per_sheet || 1;
            const quantityInPack = article.quantity_in_pack || 1;

            // Себестоимость: (закупка / кол-во из листа) × кол-во в упаковке
            const articleCost = (costPrice / piecesPerSheet) * quantityInPack;

            // Приоритет цен:
            // 1. Кэшированная цена (cached_price) - если была сохранена ранее
            // 2. Кастомная минимальная цена (custom_min_price)
            // 3. Расчёт по формуле
            if (!forceRecalc && article.cached_price > 0) {
                // Есть кэшированная цена из БД - используем её
                article.calculated_price = article.cached_price;
                article.min_price_edited = true;
                console.log(`[recalc] ${article.offer_id}: CACHED price=${article.cached_price}`);
            } else if (!forceRecalc && article.has_custom_min_price && article.custom_min_price > 0) {
                // Есть сохранённая кастомная цена - используем её
                article.calculated_price = article.custom_min_price;
                article.min_price_edited = true;
                console.log(`[recalc] ${article.offer_id}: SKIP (has_custom_min_price=${article.custom_min_price})`);
            } else {
                // Рассчитываем по формуле (или принудительно пересчитываем)
                const rawPrice = articleCost * (1 + markupPercent / 100);
                article.calculated_price = this.roundPrice(rawPrice);
                // При принудительном пересчёте сбрасываем флаги
                if (forceRecalc) {
                    article.cached_price = null;
                    article.has_custom_min_price = false;
                    article.min_price_edited = false;
                    console.log(`[recalc] ${article.offer_id}: FORCE cost=${articleCost.toFixed(2)}, markup=${markupPercent}%, raw=${rawPrice.toFixed(2)}, final=${article.calculated_price}`);
                } else {
                    console.log(`[recalc] ${article.offer_id}: cost=${articleCost.toFixed(2)}, markup=${markupPercent}%, raw=${rawPrice.toFixed(2)}, final=${article.calculated_price}`);
                }
            }

            // Зачёркнутая цена (price.discountBase) = Цена × Множитель
            article.price_value = article.calculated_price;
            article.discount_base = this.roundPrice(article.calculated_price * multiplier);

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
     * Обработчик изменения цены
     * Пересчитывает "Зачёркнуто" (discountBase) для конкретной строки
     */
    onPriceChange(input) {
        const row = input.closest('tr');
        const mappingId = input.dataset.id;
        const newPrice = parseFloat(input.value) || 0;
        const originalPrice = parseFloat(input.dataset.original) || 0;

        // Обновляем данные в массиве articles
        const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
        if (article) {
            article.calculated_price = newPrice;
            article.custom_min_price = newPrice; // Помечаем как изменённую вручную
            article.min_price_edited = true; // Флаг для сохранения в БД

            // Пересчёт "Зачёркнуто" = Цена × Множитель
            const multiplier = parseFloat(document.getElementById('discountBaseMultiplier')?.value) || 10;
            const discountBase = this.roundPrice(newPrice * multiplier);
            article.price_value = newPrice;
            article.discount_base = discountBase;

            // Обновляем отображение "Зачёркнуто" в строке
            const discountBaseSpan = row.querySelector('.discount-base-display');
            if (discountBaseSpan) {
                discountBaseSpan.textContent = App.formatPrice(discountBase);
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
                                ? 'У этого товара нет привязанных артикулов Яндекс.Маркет'
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
                this.openPackModal(btn.dataset.id, btn.dataset.offerid, btn.dataset.pieces, btn.dataset.qty);
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

        // Цена для ЯМ (price.value) - основная цена с наценкой
        const priceValue = article.calculated_price || 0;

        // Зачёркнутая цена (price.discountBase) = Цена × Множитель
        const multiplier = parseFloat(document.getElementById('discountBaseMultiplier')?.value) || 10;
        const discountBase = this.roundPrice(priceValue * multiplier);

        // Сохраняем для выгрузки
        article.price_value = priceValue;
        article.discount_base = discountBase;

        // КГТ-товары помечаются и блокируются для загрузки остатков
        const rowClass = isOversized ? 'oversized-item' : '';
        const checkboxDisabled = isOversized ? 'disabled' : '';
        const oversizedBadge = isOversized ? '<span class="badge bg-warning text-dark ms-1" title="КГТ - не принимается складом">КГТ</span>' : '';

        return `
            <tr data-mapping-id="${article.mapping_id}" data-offer-id="${article.offer_id || ''}" data-status="${article.status || 'new'}" class="${rowClass}">
                <td>
                    <input type="checkbox" class="form-check-input article-checkbox"
                           data-id="${article.mapping_id}" ${isSelected && !isOversized ? 'checked' : ''} ${checkboxDisabled}>
                </td>
                <td>
                    <code>${App.escapeHtml(article.offer_id || article.shop_sku || '')}</code>${oversizedBadge}
                    <div class="small text-muted">SKU: ${article.shop_sku || '-'}</div>
                    <div class="small ${article.barcode ? 'text-muted' : 'text-danger'}">
                        ${article.barcode ? `barcode: ${article.barcode}` : 'barcode: ❌ нет'}
                    </div>
                </td>
                <td class="text-truncate" style="max-width: 200px;" title="${App.escapeHtml(article.ym_name || '')}">
                    ${App.escapeHtml(article.ym_name || '-')}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-pack-btn"
                            data-id="${article.mapping_id}"
                            data-offerid="${article.offer_id}"
                            data-qty="${quantityInPack}"
                            data-pieces="${piecesPerSheet}"
                            title="Из листа: ${piecesPerSheet}, В упаковке: ${quantityInPack}">
                        ${piecesPerSheet}/${quantityInPack}
                    </button>
                </td>
                <td class="text-end">
                    <input type="number" class="form-control form-control-sm price-input text-end fw-bold text-warning ${article.has_custom_min_price ? 'price-modified' : ''}"
                           value="${priceValue}"
                           data-id="${article.mapping_id}"
                           data-original="${priceValue}"
                           min="0" step="1"
                           style="width: 80px; display: inline-block;"
                           title="${article.has_custom_min_price ? 'Сохранённая цена' : 'Рассчитанная цена'}">
                    ${article.has_custom_min_price ? '<i class="bi bi-bookmark-fill text-success ms-1" title="Сохранённая цена"></i>' : ''}
                </td>
                <td class="text-end">
                    <span class="fw-bold text-success discount-base-display" data-id="${article.mapping_id}">
                        ${App.formatPrice(discountBase)}
                    </span>
                </td>
                <td class="text-end">
                    ${article.ym_price > 0 ? App.formatPrice(article.ym_price) : '<span class="text-muted">-</span>'}
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
        const ymAutoFillBtn = document.getElementById('ymAutoFillBtn');
        if (ymAutoFillBtn) {
            ymAutoFillBtn.disabled = !hasArticles;
        }

        document.getElementById('uploadSelectedBtn').disabled = !hasSelected;
        document.getElementById('uploadAllBtn').disabled = !hasArticles;

        const uploadStocksOnlyBtn = document.getElementById('uploadStocksOnlyBtn');
        if (uploadStocksOnlyBtn) {
            uploadStocksOnlyBtn.disabled = !hasArticles;
        }
    },

    /**
     * Сохранение настроек артикулов (цена, остатки, раскрой)
     */
    async saveMarkups() {
        if (!this.selectedProduct) {
            App.showToast('Сначала выберите товар', 'warning');
            return;
        }

        console.log('[saveMarkups] Starting...');

        // Собираем данные из DOM (текущие значения в input'ах)
        const markups = [];

        document.querySelectorAll('#articlesTableBody tr[data-mapping-id]').forEach(row => {
            const mappingId = row.dataset.mappingId;

            // Находим input'ы в строке
            const priceInput = row.querySelector('.price-input');
            const stockInput = row.querySelector('.stock-input');

            // pieces_per_sheet берём из this.articles (в DOM только кнопка)
            const article = this.articles.find(a => String(a.mapping_id) === String(mappingId));
            const piecesPerSheet = article ? article.pieces_per_sheet : null;

            const data = {
                mapping_id: parseInt(mappingId),
                pieces_per_sheet: piecesPerSheet,
                price: priceInput ? parseFloat(priceInput.value) || null : null,
                stock: stockInput ? parseInt(stockInput.value) || null : null
            };

            console.log('[saveMarkups] Row:', data);
            markups.push(data);
        });

        console.log('[saveMarkups] Total markups:', markups.length);

        if (markups.length === 0) {
            App.showToast('Нет данных для сохранения', 'warning');
            return;
        }

        try {
            const response = await App.fetch('/api/yandex/mapping', {
                method: 'POST',
                body: {
                    action: 'save_markups',
                    product_id: this.selectedProduct.id,
                    markups: markups
                }
            });

            console.log('[saveMarkups] Response:', response);

            if (response.success) {
                App.showToast(`Сохранено: ${response.saved || markups.length} артикулов`, 'success');

                // Синхронизируем this.articles с сохранёнными данными
                markups.forEach(m => {
                    const article = this.articles.find(a => String(a.mapping_id) === String(m.mapping_id));
                    if (article) {
                        if (m.pieces_per_sheet) article.pieces_per_sheet = m.pieces_per_sheet;
                        if (m.price) {
                            article.price = m.price;
                            article.calculated_price = m.price;
                            article.cached_price = m.price;
                        }
                        if (m.stock !== null) {
                            article.stock = m.stock;
                            article.cached_stock = m.stock;
                        }
                    }
                });

                console.log('[saveMarkups] Articles synced');
                
                // Дополнительно: сохранить справочник раскроя
                await this.saveCuttingReference();
            } else {
                App.showToast('Ошибка: ' + (response.message || 'неизвестная'), 'danger');
            }
        } catch (error) {
            console.error('[saveMarkups] Error:', error);
            App.showToast('Ошибка сохранения: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузить выбранные артикулы на ЯМ (цены + остатки)
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

        // 2. Небольшая задержка чтобы ЯМ успел обработать цены
        console.log('[uploadSelected] Пауза 2 сек перед загрузкой остатков...');
        await new Promise(resolve => setTimeout(resolve, 2000));

        // 3. Загружаем остатки (если есть склад и артикулы с остатками)
        await this._uploadStocksAfterPrices(articlesToUpload);
    },

    /**
     * Загрузить все артикулы товара на ЯМ (цены + остатки)
     */
    async uploadAll() {
        if (this.articles.length === 0) {
            App.showToast('Нет артикулов для загрузки', 'warning');
            return;
        }

        // 1. Загружаем цены
        await this.uploadPrices(this.articles);

        // 2. Небольшая задержка чтобы ЯМ успел обработать цены
        console.log('[uploadAll] Пауза 2 сек перед загрузкой остатков...');
        await new Promise(resolve => setTimeout(resolve, 2000));

        // 3. Загружаем остатки (если есть склад и артикулы с остатками)
        await this._uploadStocksAfterPrices(this.articles);
    },

    /**
     * Вспомогательный метод: загрузка остатков после цен
     * @param {Array} articles - Артикулы для загрузки
     */
    async _uploadStocksAfterPrices(articles) {
        console.log('[_uploadStocksAfterPrices] ===== ЗАГРУЗКА ОСТАТКОВ =====');
        let warehouseId = document.getElementById('warehouseSelect')?.value;

        // Если склад не выбран — попробовать первый доступный
        if (!warehouseId && this.warehouses && this.warehouses.length > 0) {
            warehouseId = this.warehouses[0].id;
            console.log('[_uploadStocksAfterPrices] Используем первый склад:', warehouseId);
        }

        if (!warehouseId) {
            if (articles.some(a => parseInt(a.stock) > 0)) {
                console.warn('[_uploadStocksAfterPrices] Нет доступных складов, остатки не загружены');
                App.showToast('Склад не выбран — остатки не загружены', 'warning');
            }
            return;
        }

        // Фильтруем артикулы с остатками >= 0 (включая обнуление)
        // ВАЖНО: Исключаем КГТ-товары (is_oversized) — они не принимаются складом
        const articlesWithStocks = articles.filter(a => {
            const hasStock = a.stock !== undefined && a.stock !== null;
            const hasIdentifier = a.barcode || a.offer_id || a.shop_sku;
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
            sku: a.shop_sku || a.offer_id || '',
            offer_id: a.offer_id,
            amount: parseInt(a.stock) || 0
        }));

        console.log('[_uploadStocksAfterPrices] Всего SKU (без КГТ):', stocks.length);

        try {
            const result = await App.fetch('/api/yandex/upload-stocks', {
                method: 'POST',
                body: {
                    warehouse_id: parseInt(warehouseId),
                    stocks: stocks
                }
            });

            console.log('[_uploadStocksAfterPrices] Результат:', result);

            if (result.success) {
                App.showToast(`Остатки загружены: ${result.updated || stocks.length} SKU`, 'success');
            } else {
                console.error('[_uploadStocksAfterPrices] Ошибка:', result.error);
            }
        } catch (err) {
            console.error('[_uploadStocksAfterPrices] Exception:', err);
        }
    },

    /**
     * Загрузка цен на Яндекс.Маркет
     * Отправляет: price = "Цена для ЯМ", discount = скидка артикула
     */
    async uploadPrices(articles) {
        // Пересчитываем цены для всех артикулов перед выгрузкой
        const multiplier = parseFloat(document.getElementById('discountBaseMultiplier')?.value) || 10;
        articles.forEach(a => {
            const priceValue = a.calculated_price || 0;
            a.price_value = priceValue;
            a.discount_base = this.roundPrice(priceValue * multiplier);
        });

        const confirmMsg = `Загрузить цены для ${articles.length} артикулов на Яндекс.Маркет?\n\nБудет отправлено:\n• Цена (price.value)\n• Зачёркнутая цена (price.discountBase) = Цена × ${multiplier}`;

        const confirmed = await App.confirm(confirmMsg, 'Подтверждение загрузки');
        if (!confirmed) return;

        try {
            // Формируем данные для отправки
            // Формат ЯМ API: { offers: [{ offerId, price: { value, currencyId, discountBase } }] }
            const allPricesData = articles.map(a => ({
                offerId: a.offer_id,
                price: Math.round(a.price_value),           // Основная цена (price.value)
                discountBase: Math.round(a.discount_base),  // Зачёркнутая цена (price.discountBase)
                shop_sku: a.shop_sku  // Для логирования
            }));

            // Фильтруем невалидные цены (ЯМ отклоняет price <= 0)
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

            // Логируем отправляемые данные
            console.log('[uploadPrices] Отправляем на ЯМ:', pricesData.length, 'из', allPricesData.length);
            console.log('[uploadPrices] Данные:', pricesData);

            const data = await App.fetch('/api/yandex/upload-prices', {
                method: 'POST',
                body: { prices: pricesData.map(({shop_sku, ...rest}) => rest) }  // Убираем shop_sku перед отправкой
            });

            console.log('[uploadPrices] Ответ сервера:', data);

            // Обработка rate limit
            if (data.error_code === 'RATE_LIMIT' || data.error === 'rate_limit') {
                App.showToast(data.message || 'ЯМ ограничивает частоту запросов. Подождите 5-10 минут.', 'warning');
                this.showUploadResults({
                    ...data,
                    errors: [{ offerId: 0, error: 'Rate Limit: подождите 5-10 минут перед повторной загрузкой' }]
                });
                return;
            }

            App.showToast(data.message || 'Цены загружены', data.success ? 'success' : 'warning');

            // Показываем результаты
            this.showUploadResults(data);

            // Сохраняем настройки наценок (чтобы при следующем входе восстановились)
            await this.saveSettings();

            // Сохраняем кастомные цены
            await this.saveCustomMinPrices(articles);

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

        localStorage.setItem('ym_stocks_' + this.selectedProduct.id, JSON.stringify(stocks));
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
        const errorOfferIds = data.error_offer_ids || [];
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

        // Находим артикулы с ошибками для отображения offer_id
        const errorDetails = errors.map(e => {
            if (typeof e === 'object' && e.offerId) {
                const article = this.articles.find(a => a.offer_id === e.offerId);
                return {
                    offerId: e.offerId,
                    shopSku: article?.shop_sku || '?',
                    error: e.error || 'Неизвестная ошибка',
                    price: e.price,
                    discount: e.discount
                };
            }
            return { offerId: 0, shopSku: '?', error: typeof e === 'string' ? e : JSON.stringify(e) };
        });

        // Подсвечиваем строки с ошибками в таблице
        if (errorOfferIds.length > 0) {
            this.highlightErrorRows(errorOfferIds);
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
                        <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Ошибки ЯМ (${errorDetails.length}):</h6>
                        <div class="table-responsive mt-2" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>OfferId</th>
                                        <th>SKU</th>
                                        <th>Ошибка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${errorDetails.map(e => `
                                        <tr>
                                            <td><code>${e.offerId}</code></td>
                                            <td><small>${App.escapeHtml(e.shopSku)}</small></td>
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
                        <small class="text-muted">Для загрузки остатков требуется баркод. Обновите товары ЯМ.</small>
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
    highlightErrorRows(errorOfferIds) {
        // Убираем предыдущую подсветку
        document.querySelectorAll('#articlesTable tbody tr.table-danger').forEach(row => {
            row.classList.remove('table-danger');
        });

        // Подсвечиваем строки с ошибками
        errorOfferIds.forEach(offerId => {
            const row = document.querySelector(`#articlesTable tbody tr[data-offer-id="${offerId}"]`);
            if (row) {
                row.classList.add('table-danger');
                console.log(`[highlightErrorRows] Подсвечена строка offerId=${offerId}`);
            }
        });
    },

    /**
     * Открыть модальное окно редактирования параметров упаковки
     */
    openPackModal(mappingId, offerId, piecesPerSheet, quantityInPack) {
        document.getElementById('editPackMappingId').value = mappingId;
        document.getElementById('editPackOfferId').value = offerId;
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
        const sheetSelect = document.getElementById('ymSheetSelect');
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
            await App.fetch('/api/yandex/mapping', {
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
                
                // 3. Сохранить в справочник раскроя (КРИТИЧНО!)
                const sheetSelect = document.getElementById('ymSheetSelect');
                const sheetName = (sheetSelect?.selectedOptions[0]?.textContent || '').trim();
                
                // ПРИОРИТЕТ: используем offer_id как уникальный ID артикула
                const articleName = article.ym_name || article.vendor_code || '';
                const articleId = article.offer_id || article.vendor_code || String(mappingId);
                
                console.log(`[savePackSettings] Сохраняем в БД: articleId=${articleId}, pieces=${piecesPerSheet}, pack=${quantityInPack}`);
                
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
                        console.log('[savePackSettings] Размеры не определены, сохраняем по артикулу');
                        App.showToast('✅ Сохранено в справочник артикулов', 'success');
                    }
                    
                    // 4. Сохраняем в справочник упаковки по артикулу
                    await this.savePackagingToDb(articleId, articleName, piecesPerSheet, quantityInPack, sheetName);
                } else {
                    // Сохраняем по артикулу даже без листа
                    await this.savePackagingToDb(articleId, articleName, piecesPerSheet, quantityInPack, '');
                    App.showToast('✅ Сохранено', 'success');
                }
            }

            this.recalculatePrices();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Автозаполнение pieces_per_sheet и quantity_in_pack из артикулов ЯМ (старый метод)
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
                // Парсим артикул ЯМ
                const parseResult = await App.fetch(`/api/yandex/parse-article?article=${encodeURIComponent(article.offer_id || '')}`);

                if (parseResult.success && parseResult.data) {
                    const { pieces_per_sheet, quantity_in_pack } = parseResult.data;

                    if (pieces_per_sheet || quantity_in_pack) {
                        await App.fetch('/api/yandex/mapping', {
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
     * Алгоритм:
     * 1. Для каждого артикула парсим размер из названия
     * 2. Проверяем справочник раскроя (cutting-reference.js)
     * 3. Если найдено в справочнике → используем значение
     * 4. Если не найдено → отправляем запрос на сервер для расчёта
     */
    /**
     * Автозаполнение pieces_per_sheet из справочника раскроя (новый метод)
     * Использует таблицы cutting_sheets и cutting_pieces
     * 
     * ПРАВИЛЬНАЯ ЛОГИКА:
     * - Артикулы берём из таблицы (привязаны к выбранному ТОВАРУ слева)
     * - Лист справа — это только параметр для расчёта количества деталей
     */
    
    /**
     * Сохранить справочник раскроя в БД
     */
    async saveCuttingReference() {
        const sheetSelect = document.getElementById('ymSheetSelect');
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
            const name = article.offer_id || article.ym_name || '';
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

        const sheetSelect = document.getElementById('ymSheetSelect');
        const selectedOption = sheetSelect?.selectedOptions[0];

        if (!selectedOption) {
            App.showToast('Выберите лист для расчёта', 'warning');
            return;
        }

        const baseWidth = parseInt(selectedOption.dataset.width) || 1520;
        const baseHeight = parseInt(selectedOption.dataset.height) || 1520;
        const sheetName = (selectedOption.textContent || '').trim();

        const btn = document.getElementById('ymAutoFillBtn');
        const originalHtml = btn?.innerHTML || '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            // Загрузить справочники
            await this.loadPackagingReference();
            const cuttingReference = await this.loadCuttingReference(sheetName);

            let fromPackaging = 0;
            let fromCutting = 0;
            let fromServer = 0;
            const notFoundArticles = [];
            const alreadyFilled = new Set(); // Артикулы с данными из БД/раскроя

            console.log(`[autoFillPieces] Обработка ${this.articles.length} артикулов...`);
            console.log(`[autoFillPieces] Справочник артикулов: ${Object.keys(this.packagingReference).length} записей`);
            console.log(`[autoFillPieces] Справочник раскроя: ${Object.keys(cuttingReference).length} позиций`);

            for (const article of this.articles) {
                // Получить название и ID артикула
                const articleName = article.offer_id || article.ym_name || '';
                const articleId = article.offer_id || article.vendor_code || String(article.mapping_id);

                // ПРИОРИТЕТ 1: Проверяем справочник упаковки по артикулу
                if (this.packagingReference[articleId]?.pieces_per_sheet) {
                    const packData = this.packagingReference[articleId];
                    article.pieces_per_sheet = packData.pieces_per_sheet;
                    if (packData.pack_quantity) {
                        article.quantity_in_pack = packData.pack_quantity;
                    }
                    alreadyFilled.add(articleId); // Пометить как заполненный
                    console.log(`[autoFill] ✓ ${articleId} = ${packData.pieces_per_sheet} (БД)`);
                    fromPackaging++;
                    continue;
                }

                // Парсить размеры из названия
                const dimensions = parseArticleDimensions(articleName);

                if (!dimensions || !dimensions.width || !dimensions.height) {
                    console.log(`[autoFill] ? ${articleName}: размеры не определены`);
                    notFoundArticles.push(article);
                    continue;
                }

                // ПРИОРИТЕТ 2: Поискать в справочнике раскроя
                const key1 = `${dimensions.width}x${dimensions.height}`;
                const key2 = `${dimensions.height}x${dimensions.width}`;

                const pieces = cuttingReference[key1] || cuttingReference[key2];

                if (pieces && pieces > 0) {
                    article.pieces_per_sheet = pieces;
                    alreadyFilled.add(articleId); // Пометить как заполненный
                    console.log(`[autoFill] ✓ ${articleName} (${key1}) = ${pieces} (раскрой)`);
                    fromCutting++;
                } else {
                    console.log(`[autoFill] ? ${articleName}: не найден, требуется расчёт`);
                    notFoundArticles.push(article);
                }
            }

            // Проход 3: Если не найдено в справочниках → запрос к серверу
            // Отправляем ТОЛЬКО артикулы без данных
            if (notFoundArticles.length > 0) {
                console.log(`[autoFill] ${notFoundArticles.length} артикулов требуют расчёта на сервере`);

                // Собираем ID артикулов для расчёта
                const articleIdsForCalc = notFoundArticles.map(a =>
                    a.offer_id || a.vendor_code || String(a.mapping_id)
                );

                const result = await App.fetch('/api/yandex/auto-fill-pieces', {
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
                        const articleId = article.offer_id || article.vendor_code || String(article.mapping_id);

                        // Дополнительная проверка — не перезаписывать если уже заполнено
                        if (!alreadyFilled.has(articleId) && result.pieces_data[articleId]) {
                            article.pieces_per_sheet = result.pieces_data[articleId];
                            console.log(`[autoFill] ✓ ${articleId} = ${result.pieces_data[articleId]} (сервер)`);
                            fromServer++;
                        }
                    }
                } else if (result.success) {
                    // Fallback: если сервер не вернул pieces_data, перезагружаем только notFoundArticles
                    fromServer = result.updated || 0;
                    console.log(`[autoFill] Сервер обновил ${fromServer} артикулов (без детализации)`);
                }
            }

            const totalUpdated = fromPackaging + fromCutting + fromServer;
            console.log(`[autoFillPieces] Итого: БД=${fromPackaging}, раскрой=${fromCutting}, сервер=${fromServer}`);

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
     * Загрузка ТОЛЬКО остатков на ЯМ
     */
    async uploadStocksOnly() {
        console.log('[uploadStocksOnly] ===== ЗАГРУЗКА ОСТАТКОВ =====');
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
        let warehouseId = document.getElementById('warehouseSelect')?.value;

        // Если склад не выбран — попробовать первый доступный
        if (!warehouseId && this.warehouses && this.warehouses.length > 0) {
            warehouseId = this.warehouses[0].id;
            console.log('[uploadStocksOnly] Используем первый склад:', warehouseId);
        }

        if (!warehouseId) {
            App.showToast('Нет доступных складов для загрузки остатков', 'warning');
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
        let confirmMsg = `Загрузить остатки для ${articlesWithStock.length} артикулов на Яндекс.Маркет?\n\nСклад: ${warehouseId}`;
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
                sku: article.shop_sku || article.offer_id || '',
                offer_id: article.offer_id,
                amount: parseInt(article.stock) || 0
            }));

            console.log('[uploadStocksOnly] Всего SKU (без КГТ):', stocks.length);

            const result = await App.fetch('/api/yandex/upload-stocks', {
                method: 'POST',
                body: {
                    warehouse_id: parseInt(warehouseId),
                    stocks: stocks
                }
            });

            console.log('[uploadStocksOnly] Результат:', result);

            // Показываем результаты
            this.showUploadResults({
                success: result.success,
                sent: stocks.length,
                updated: result.updated || 0,
                errors: result.errors || [],
                error_count: result.error_count || 0,
                warnings: result.warnings || []
            });

            // Итоговое уведомление
            if (result.success) {
                App.showToast(`Остатки загружены: ${result.updated || stocks.length} SKU`, 'success');
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
        const articleName = article?.offer_id || article?.ym_name || mappingId;

        const confirmed = await App.confirm(
            `Удалить связь с артикулом "${articleName}"?`,
            'Подтверждение удаления'
        );

        if (!confirmed) return;

        try {
            const result = await App.fetch('/api/yandex/mapping', {
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
     * Синхронизация с Яндекс.Маркет
     */
    async syncWithYM() {
        const modal = new bootstrap.Modal(document.getElementById('syncModal'));
        modal.show();

        // Показываем загрузку
        document.getElementById('syncModalLoading').classList.remove('d-none');
        document.getElementById('syncModalResult').classList.add('d-none');
        document.getElementById('syncModalError').classList.add('d-none');
        document.getElementById('syncModalFooter').classList.add('d-none');

        try {
            const result = await App.fetch('/api/yandex/sync-products', {
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
     * Округление цены до "красивого" значения с 9 на конце
     * 93 → 99, 150 → 199, 834 → 899, 1250 → 1299
     */
    roundPrice(price) {
        // Защита от нулевых и отрицательных цен
        if (!price || price <= 0) {
            console.warn('[roundPrice] Невалидная цена:', price, '→ возвращаем 0');
            return 0;
        }

        let result;
        if (price < 100) {
            // До 99: округляем до X9 (93 → 99, 45 → 49)
            result = Math.ceil(price / 10) * 10 - 1;
        } else if (price < 1000) {
            // До 999: округляем до X99 (150 → 199, 834 → 899)
            result = Math.ceil(price / 100) * 100 - 1;
        } else if (price < 10000) {
            // До 9999: округляем до X99 (1250 → 1299, 5600 → 5699)
            result = Math.ceil(price / 100) * 100 - 1;
        } else if (price < 100000) {
            // До 99999: округляем до X999 (12500 → 12999)
            result = Math.ceil(price / 1000) * 1000 - 1;
        } else {
            // Больше 100000: округляем до X9999
            result = Math.ceil(price / 10000) * 10000 - 1;
        }

        // Защита от отрицательных результатов (если price < 10)
        if (result < 1) result = Math.ceil(price);

        console.log(`[roundPrice] ${price} → ${result}`);
        return result;
    }
};

/**
 * Справочник раскроя листов для Яндекс.Маркет
 * Управляет соотношениями: исходный лист → размер кусочка → количество
 * Использует универсальные API endpoints /api/cutting/*
 */
const YMCuttingReference = {
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
        console.log('YMCuttingReference.init() called');
        this.bindEvents();
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        // Переключение на вкладку - загрузка листов
        const cuttingTab = document.getElementById('ym-cutting-tab');
        console.log('YMCuttingReference.bindEvents(): cuttingTab =', cuttingTab);

        if (cuttingTab) {
            // Bootstrap событие (основное) - срабатывает ПОСЛЕ показа вкладки
            cuttingTab.addEventListener('shown.bs.tab', () => {
                console.log('YMCuttingReference: shown.bs.tab event fired');
                if (!this.loaded && !this.loading) {
                    this.loadSheets();
                }
            });

            // Клик по вкладке - запасной вариант с задержкой для Bootstrap
            cuttingTab.addEventListener('click', () => {
                console.log('YMCuttingReference: click event, loaded=' + this.loaded + ', loading=' + this.loading);
                if (!this.loaded && !this.loading) {
                    // Ждём пока Bootstrap покажет панель
                    setTimeout(() => {
                        if (!this.loaded && !this.loading) {
                            console.log('YMCuttingReference: click fallback - loading sheets');
                            this.loadSheets();
                        }
                    }, 200);
                }
            });
        } else {
            console.error('YMCuttingReference: ym-cutting-tab element NOT FOUND!');
        }

        // Автоподстановка размеров при выборе типа материала
        document.getElementById('ymNewSheetType')?.addEventListener('change', (e) => {
            this.autoFillSheetSize(e.target.value);
        });

        // Добавить лист
        document.getElementById('ymBtnAddSheet')?.addEventListener('click', () => this.addSheet());

        // Добавить размер кусочка
        document.getElementById('ymBtnAddPiece')?.addEventListener('click', () => this.showAddPieceModal());

        // Загрузить размеры из артикулов
        document.getElementById('ymBtnLoadFromArticles')?.addEventListener('click', () => this.loadFromArticles());

        // Сохранить изменения
        document.getElementById('ymBtnSavePieces')?.addEventListener('click', () => this.savePieces());

        // Сохранить новый размер
        document.getElementById('ymBtnSaveNewPiece')?.addEventListener('click', () => this.saveNewPiece());

        // Пресет размера кусочка
        document.getElementById('ymAddPiecePreset')?.addEventListener('change', (e) => {
            if (e.target.value) {
                const [w, h] = e.target.value.split('x').map(Number);
                document.getElementById('ymAddPieceWidth').value = w;
                document.getElementById('ymAddPieceHeight').value = h;
                this.updateAddPieceCalc();
            }
        });

        // Live preview при вводе размеров
        ['ymAddPieceWidth', 'ymAddPieceHeight'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => this.updateAddPieceCalc());
        });
    },

    /**
     * Автоподстановка размеров листа
     */
    autoFillSheetSize(materialType) {
        const size = this.sheetSizes[materialType];
        if (size) {
            document.getElementById('ymNewSheetWidth').value = size.w;
            document.getElementById('ymNewSheetHeight').value = size.h;
        }
    },

    /**
     * Загрузить список листов
     */
    async loadSheets() {
        console.log('YMCuttingReference.loadSheets() called, loading=' + this.loading);

        // Предотвращаем параллельные запросы
        if (this.loading) {
            console.log('YMCuttingReference: already loading, skipping');
            return;
        }

        this.loading = true;

        try {
            const response = await App.fetch('/api/cutting/sheets');
            console.log('YMCuttingReference: API response:', response);

            if (response.success) {
                this.sheets = response.sheets || [];
                this.loaded = true;
                console.log('YMCuttingReference: sheets loaded successfully, count=' + this.sheets.length);
                this.renderSheetsList();
            } else {
                console.error('YMCuttingReference: API returned error:', response);
                // Показываем сообщение об ошибке в контейнере
                const container = document.getElementById('ymSheetsList');
                if (container) {
                    container.innerHTML = '<div class="text-center text-danger py-3">Ошибка загрузки</div>';
                }
            }
        } catch (e) {
            console.error('YMCuttingReference: Ошибка загрузки листов:', e);
            App.showToast('Ошибка загрузки листов', 'danger');
            // Показываем сообщение об ошибке в контейнере
            const container = document.getElementById('ymSheetsList');
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
        const container = document.getElementById('ymSheetsList');
        console.log('YMCuttingReference.renderSheetsList(): container=', container, 'sheets=', this.sheets.length);
        if (!container) {
            console.error('YMCuttingReference: container #ymSheetsList not found!');
            return;
        }

        if (this.sheets.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-3">Нет листов. Добавьте первый!</div>';
            return;
        }

        container.innerHTML = this.sheets.map(sheet => `
            <a href="#" class="list-group-item list-group-item-action bg-dark border-secondary ${sheet.id == this.selectedSheetId ? 'active' : ''}"
               data-sheet-id="${sheet.id}" onclick="YMCuttingReference.selectSheet(${sheet.id}); return false;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${this.escapeHtml(sheet.material_name)}</strong><br>
                        <small class="text-muted">${sheet.sheet_width}×${sheet.sheet_height} мм</small>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="event.preventDefault(); event.stopPropagation(); YMCuttingReference.deleteSheet(${sheet.id});">
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
        const type = document.getElementById('ymNewSheetType').value;
        const typeSelect = document.getElementById('ymNewSheetType');
        const name = typeSelect.options[typeSelect.selectedIndex].text;
        const width = parseInt(document.getElementById('ymNewSheetWidth').value) || 0;
        const height = parseInt(document.getElementById('ymNewSheetHeight').value) || 0;

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
                    document.getElementById('ymSelectedSheetName').textContent = 'выберите лист';
                    document.getElementById('ymBtnAddPiece').disabled = true;
                    document.getElementById('ymBtnLoadFromArticles').disabled = true;
                    document.getElementById('ymBtnSavePieces').disabled = true;
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
        document.getElementById('ymBtnAddPiece').disabled = false;
        document.getElementById('ymBtnLoadFromArticles').disabled = false;
        document.getElementById('ymBtnSavePieces').disabled = false;

        // Загружаем раскрой
        try {
            const response = await App.fetch(`/api/cutting/pieces?sheet_id=${sheetId}`);
            if (response.success) {
                this.selectedSheet = response.sheet;
                this.pieces = response.pieces || [];
                document.getElementById('ymSelectedSheetName').textContent =
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
        const tbody = document.getElementById('ymPiecesTableBody');
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
                        <input type="number" class="form-control form-control-sm ym-piece-actual-qty text-center"
                               value="${piece.actual_qty}" min="1" style="width: 80px; display: inline-block;">
                        ${diff ? '<i class="bi bi-exclamation-triangle text-warning ms-1" title="Отличается от авто-расчёта"></i>' : ''}
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-secondary btn-sm me-1"
                                onclick="YMCuttingReference.editPiece(${piece.id})" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="YMCuttingReference.deletePiece(${piece.id})" title="Удалить">
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
        document.getElementById('ymAddPieceName').value = '';
        document.getElementById('ymAddPiecePreset').value = '';
        document.getElementById('ymAddPieceWidth').value = '';
        document.getElementById('ymAddPieceHeight').value = '';
        document.getElementById('ymAddPieceQty').value = '';
        document.getElementById('ymAddPieceCalc').textContent = '-';

        const modal = new bootstrap.Modal(document.getElementById('ymAddPieceModal'));
        modal.show();
    },

    /**
     * Обновить авто-расчёт в модалке
     */
    updateAddPieceCalc() {
        if (!this.selectedSheet) return;

        const pieceW = parseInt(document.getElementById('ymAddPieceWidth')?.value) || 0;
        const pieceH = parseInt(document.getElementById('ymAddPieceHeight')?.value) || 0;

        if (pieceW > 0 && pieceH > 0) {
            const calc = this.calculatePieces(
                this.selectedSheet.sheet_width, this.selectedSheet.sheet_height,
                pieceW, pieceH
            );
            document.getElementById('ymAddPieceCalc').textContent = calc;
            document.getElementById('ymAddPieceQty').value = calc;
        } else {
            document.getElementById('ymAddPieceCalc').textContent = '-';
        }
    },

    /**
     * Сохранить новый размер
     */
    async saveNewPiece() {
        const nameEl = document.getElementById('ymAddPieceName');
        const widthEl = document.getElementById('ymAddPieceWidth');
        const heightEl = document.getElementById('ymAddPieceHeight');
        const qtyEl = document.getElementById('ymAddPieceQty');

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
                bootstrap.Modal.getInstance(document.getElementById('ymAddPieceModal'))?.hide();
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
     * Загрузить размеры из артикулов ЯМ
     */
    async loadFromArticles() {
        if (!this.selectedSheetId) {
            App.showToast('Сначала выберите лист слева', 'warning');
            return;
        }

        const btn = document.getElementById('ymBtnLoadFromArticles');
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
        const rows = document.querySelectorAll('#ymPiecesTableBody tr[data-piece-id]');
        const pieces = [];

        rows.forEach(row => {
            const input = row.querySelector('.ym-piece-actual-qty');
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
        const row = document.querySelector(`#ymPiecesTableBody tr[data-piece-id="${pieceId}"]`);
        if (!row) {
            App.showToast('Размер не найден', 'danger');
            return;
        }

        document.getElementById('ymEditPieceId').value = pieceId;
        document.getElementById('ymEditPieceName').value = row.dataset.pieceName || '';
        document.getElementById('ymEditPieceWidth').value = row.dataset.pieceWidth || '';
        document.getElementById('ymEditPieceHeight').value = row.dataset.pieceHeight || '';
        document.getElementById('ymEditPieceQty').value = row.dataset.actualQty || '';

        const modal = new bootstrap.Modal(document.getElementById('ymEditPieceModal'));
        modal.show();
    },

    /**
     * Сохранить изменения размера
     */
    async updatePiece() {
        const pieceId = document.getElementById('ymEditPieceId').value;
        const name = document.getElementById('ymEditPieceName').value.trim();
        const width = parseInt(document.getElementById('ymEditPieceWidth').value) || 0;
        const height = parseInt(document.getElementById('ymEditPieceHeight').value) || 0;
        let qty = parseInt(document.getElementById('ymEditPieceQty').value) || 0;

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
                bootstrap.Modal.getInstance(document.getElementById('ymEditPieceModal'))?.hide();
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
    YMCalculator.init();
    YMCuttingReference.init();
});

// Привязка кнопки минимальной цены через setTimeout (рабочий вариант)
setTimeout(() => {
    const btn = document.getElementById('ymApplyMinPrice');

    if (btn) {
        btn.onclick = function() {
            const minPrice = parseFloat(document.getElementById('ymMinPriceThreshold')?.value);

            if (!minPrice || minPrice <= 0) {
                if (typeof App !== 'undefined' && App.showToast) {
                    App.showToast('Укажите минимальную цену', 'warning');
                }
                return;
            }

            let updated = 0;

            // Обходим строки таблицы чтобы получить mapping_id
            document.querySelectorAll('#articlesTableBody tr[data-mapping-id]').forEach(row => {
                const priceInput = row.querySelector('.price-input');
                if (!priceInput) return;

                const price = parseFloat(priceInput.value) || 0;

                if (price > 0 && price < minPrice) {
                    // 1. Обновить DOM
                    priceInput.value = minPrice;

                    // 2. Обновить YMCalculator.articles (КРИТИЧЕСКИ ВАЖНО для выгрузки!)
                    const mappingId = row.dataset.mappingId;
                    if (mappingId && typeof YMCalculator !== 'undefined' && YMCalculator.articles) {
                        const article = YMCalculator.articles.find(a =>
                            String(a.mapping_id) === String(mappingId)
                        );
                        if (article) {
                            article.price = minPrice;
                            article.calculated_price = minPrice;
                            article.price_value = minPrice;
                            console.log(`[minPrice] Updated article ${mappingId}: ${price} → ${minPrice}`);
                        }
                    }

                    updated++;
                }
            });

            if (typeof App !== 'undefined' && App.showToast) {
                if (updated > 0) {
                    App.showToast('Поднято цен: ' + updated, 'success');
                } else {
                    App.showToast('Нет цен ниже минимума', 'info');
                }
            }
        };
    }
}, 2000);
