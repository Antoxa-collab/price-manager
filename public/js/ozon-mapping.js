/**
 * Price Manager - Сопоставление товаров Ozon
 * 3-шаговый интерфейс: Загрузка -> Сопоставление -> Просмотр
 */

const OzonMapping = {
    // Данные
    ourProducts: [],
    ozonProducts: [],
    mappings: [],

    // Выбранные элементы
    selectedOurProduct: null,
    selectedOzonProducts: new Set(), // Множественный выбор товаров Ozon

    // Текущий шаг (1, 2, 3)
    currentStep: 1,

    // Время последней синхронизации
    lastSyncTime: null,

    /**
     * Инициализация модуля
     */
    init() {
        this.bindEvents();
        this.checkInitialState();
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        // === Шаг 1: Загрузка ===
        document.getElementById('syncOzonBtn')?.addEventListener('click', () => this.syncFromOzon());
        document.getElementById('skipToStep2Btn')?.addEventListener('click', () => this.goToStep(2));

        // === Шаг 2: Сопоставление ===
        document.getElementById('backToStep1Btn')?.addEventListener('click', () => this.goToStep(1));
        document.getElementById('goToStep3Btn')?.addEventListener('click', () => this.goToStep(3));
        document.getElementById('createMappingBtn')?.addEventListener('click', () => this.createMapping());

        // Поиск товаров
        document.getElementById('searchOurProducts')?.addEventListener('input',
            App.debounce(() => this.renderOurProducts(), 300));
        document.getElementById('searchOzonProducts')?.addEventListener('input',
            App.debounce(() => this.renderOzonProducts(), 300));

        // Фильтр товаров Ozon
        document.querySelectorAll('input[name="ozonFilter"]').forEach(radio => {
            radio.addEventListener('change', () => this.renderOzonProducts());
        });

        // Добавление товара
        document.getElementById('addProductBtn')?.addEventListener('click', () => this.openAddProductModal());
        document.getElementById('saveProductBtn')?.addEventListener('click', () => this.saveProduct());

        // === Шаг 3: Просмотр ===
        document.getElementById('backToStep2Btn')?.addEventListener('click', () => this.goToStep(2));
        document.getElementById('searchMappings')?.addEventListener('input',
            App.debounce(() => this.renderMappings(), 300));

        // Редактирование количества в упаковке
        document.getElementById('saveQtyBtn')?.addEventListener('click', () => this.saveQuantity());
    },

    /**
     * Проверка начального состояния (есть ли уже товары)
     */
    async checkInitialState() {
        try {
            // Загружаем информацию о кэше
            const cacheInfo = await App.fetch('/api/ozon/cache-info');

            if (cacheInfo.count > 0) {
                // Есть кэшированные товары - показываем кнопку пропуска
                document.getElementById('skipStep1')?.classList.remove('d-none');
                document.getElementById('cachedProductsCount').textContent = cacheInfo.count;

                // Показываем время последней синхронизации
                if (cacheInfo.last_sync) {
                    this.lastSyncTime = cacheInfo.last_sync;
                    document.getElementById('syncTimeValue').textContent = this.formatSyncTime(cacheInfo.last_sync);
                }
            }

            // Загружаем статистику
            await this.loadStatistics();
        } catch (error) {
            console.error('Ошибка проверки состояния:', error);
        }
    },

    /**
     * Переход к определённому шагу
     */
    goToStep(step) {
        this.currentStep = step;

        // Скрываем все контенты шагов
        document.querySelectorAll('.step-content').forEach(el => el.classList.add('d-none'));

        // Показываем нужный контент
        document.getElementById(`step${step}Content`)?.classList.remove('d-none');

        // Обновляем индикаторы шагов
        this.updateStepIndicators();

        // Загружаем данные для шага
        if (step === 2) {
            this.loadStep2Data();
        } else if (step === 3) {
            this.loadStep3Data();
        }
    },

    /**
     * Обновление индикаторов шагов
     */
    updateStepIndicators() {
        for (let i = 1; i <= 3; i++) {
            const indicator = document.getElementById(`step${i}Indicator`);
            if (!indicator) continue;

            // Убираем все классы
            indicator.classList.remove('active', 'completed');

            // Добавляем нужные классы
            if (i < this.currentStep) {
                indicator.classList.add('completed');
                // Показываем галочку вместо номера
                indicator.querySelector('.step-number')?.classList.add('d-none');
                indicator.querySelector('.step-check')?.classList.remove('d-none');
            } else if (i === this.currentStep) {
                indicator.classList.add('active');
                indicator.querySelector('.step-number')?.classList.remove('d-none');
                indicator.querySelector('.step-check')?.classList.add('d-none');
            } else {
                indicator.querySelector('.step-number')?.classList.remove('d-none');
                indicator.querySelector('.step-check')?.classList.add('d-none');
            }
        }
    },

    /**
     * Загрузка данных для шага 2
     */
    async loadStep2Data() {
        await Promise.all([
            this.loadOurProducts(),
            this.loadOzonProducts()
        ]);
    },

    /**
     * Загрузка данных для шага 3
     */
    async loadStep3Data() {
        await Promise.all([
            this.loadMappings(),
            this.loadStatistics()
        ]);
    },

    /**
     * Синхронизация товаров с Ozon
     */
    async syncFromOzon() {
        const syncBtn = document.getElementById('syncOzonBtn');
        const progressDiv = document.getElementById('syncProgress');
        const progressText = document.getElementById('syncProgressText');

        try {
            // Показываем прогресс
            syncBtn.disabled = true;
            progressDiv?.classList.remove('d-none');
            progressText.textContent = 'Подключение к Ozon...';

            const data = await App.fetch('/api/ozon/sync', {
                method: 'POST',
                body: {}
            });

            progressText.textContent = 'Синхронизация завершена!';

            App.showToast(data.message || `Загружено ${data.count || 0} товаров`, 'success');

            // Обновляем время синхронизации
            this.lastSyncTime = new Date().toISOString();
            document.getElementById('syncTimeValue').textContent = 'только что';

            // Переходим к шагу 2
            setTimeout(() => {
                this.goToStep(2);
            }, 1000);

        } catch (error) {
            App.showToast('Ошибка синхронизации: ' + error.message, 'danger');
            progressDiv?.classList.add('d-none');
        } finally {
            syncBtn.disabled = false;
        }
    },

    /**
     * Загрузка наших товаров
     */
    async loadOurProducts() {
        try {
            const data = await App.fetch('/api/ozon/our-products');
            this.ourProducts = data.products || [];
            this.renderOurProducts();
        } catch (error) {
            App.showToast('Ошибка загрузки товаров: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка товаров Ozon из кэша
     */
    async loadOzonProducts() {
        try {
            const data = await App.fetch('/api/ozon/cached-products');
            this.ozonProducts = data.products || [];
            this.renderOzonProducts();
        } catch (error) {
            App.showToast('Ошибка загрузки товаров Ozon: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка сопоставлений
     */
    async loadMappings() {
        try {
            const data = await App.fetch('/api/ozon/mappings');
            this.mappings = data.mappings || [];
            this.renderMappings();
            document.getElementById('mappingsTotal').textContent = this.mappings.length;
        } catch (error) {
            App.showToast('Ошибка загрузки сопоставлений: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка статистики
     */
    async loadStatistics() {
        try {
            const data = await App.fetch('/api/ozon/statistics');

            document.getElementById('statOurProducts').textContent = data.total_our_products || 0;
            document.getElementById('statOzonProducts').textContent = data.total_marketplace || 0;
            document.getElementById('statMapped').textContent = data.mapped_marketplace || 0;

            if (data.mapping_percent) {
                document.getElementById('statMappedPercent').textContent = `(${data.mapping_percent}%)`;
            }
        } catch (error) {
            console.error('Ошибка загрузки статистики:', error);
        }
    },

    /**
     * Рендеринг списка наших товаров
     */
    renderOurProducts() {
        const list = document.getElementById('ourProductsList');
        if (!list) return;

        const searchTerm = (document.getElementById('searchOurProducts')?.value || '').toLowerCase();

        const filtered = this.ourProducts.filter(p => {
            if (!searchTerm) return true;
            return (p.name || '').toLowerCase().includes(searchTerm) ||
                   (p.sku || '').toLowerCase().includes(searchTerm);
        });

        document.getElementById('ourProductsCount').textContent = filtered.length;

        if (filtered.length === 0) {
            list.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox display-6"></i>
                    <p class="mt-2 mb-0">Нет товаров</p>
                    <button type="button" class="btn btn-outline-success btn-sm mt-2" id="addProductBtnEmpty">
                        <i class="bi bi-plus-lg me-1"></i> Добавить товар
                    </button>
                </div>
            `;
            document.getElementById('addProductBtnEmpty')?.addEventListener('click', () => this.openAddProductModal());
            return;
        }

        list.innerHTML = filtered.map(product => {
            // Считаем количество сопоставлений для товара
            const mappingCount = this.getMappingCountForProduct(product.id);

            return `
                <a href="#" class="list-group-item list-group-item-action bg-dark text-white border-secondary our-product-item"
                   data-id="${product.id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 me-2">
                            <div class="product-name-wrapper">
                                <strong class="product-name-text">${App.escapeHtml(product.name || '')}</strong>
                                <button type="button" class="btn-copy-name" title="Копировать название">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="small text-muted">
                                ${App.escapeHtml(product.sku || '')}
                                ${product.grade ? ` | Сорт: ${App.escapeHtml(product.grade)}` : ''}
                                ${product.thickness ? ` | ${product.thickness}мм` : ''}
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-secondary">${App.formatPrice(product.cost_price || product.base_price || 0)}</span>
                            ${mappingCount > 0 ? `<br><span class="badge bg-success mt-1">${mappingCount} связей</span>` : ''}
                        </div>
                    </div>
                </a>
            `;
        }).join('');

        // Привязываем события
        list.querySelectorAll('.our-product-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                this.selectOurProduct(item.dataset.id);
            });
        });
    },

    /**
     * Получение количества сопоставлений для товара
     */
    getMappingCountForProduct(productId) {
        return this.mappings.filter(m => String(m.product_id) === String(productId)).length;
    },

    /**
     * Рендеринг списка товаров Ozon
     */
    renderOzonProducts() {
        const list = document.getElementById('ozonProductsList');
        if (!list) return;

        const searchTerm = (document.getElementById('searchOzonProducts')?.value || '').toLowerCase();
        const filterValue = document.querySelector('input[name="ozonFilter"]:checked')?.value || 'all';

        let filtered = this.ozonProducts.filter(p => {
            // Поиск
            if (searchTerm) {
                const matches = (p.name || '').toLowerCase().includes(searchTerm) ||
                               (p.offer_id || '').toLowerCase().includes(searchTerm) ||
                               (p.sku || '').toLowerCase().includes(searchTerm);
                if (!matches) return false;
            }

            // Фильтр по сопоставлению
            if (filterValue === 'unmapped' && p.is_mapped) return false;

            return true;
        });

        // Количество несопоставленных
        const unmappedCount = filtered.filter(p => !p.is_mapped).length;

        document.getElementById('ozonProductsCount').textContent = filtered.length;

        if (filtered.length === 0) {
            list.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cloud display-6"></i>
                    <p class="mt-2 mb-0">Нет товаров</p>
                    <small>Синхронизируйте товары с Ozon</small>
                </div>
            `;
            return;
        }

        // Заголовок с чекбоксом "Выбрать все" и счётчиком
        const headerHtml = `
            <div class="select-all-header d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded border border-secondary">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="selectAllOzon"
                           ${unmappedCount === 0 ? 'disabled' : ''}>
                    <label class="form-check-label small" for="selectAllOzon">
                        Выбрать все без связи (${unmappedCount})
                    </label>
                </div>
                <span class="badge bg-primary" id="selectedOzonCount">
                    Выбрано: ${this.selectedOzonProducts.size}
                </span>
            </div>
        `;

        const itemsHtml = filtered.map(product => {
            // Формируем бейдж сопоставления с названием товара
            let mappingBadge = '';
            if (product.is_mapped) {
                const productName = product.our_product_name || '';
                const truncatedName = productName.length > 30
                    ? productName.substring(0, 30) + '...'
                    : productName;
                mappingBadge = `<span class="badge mapping-badge bg-success mt-1" title="${App.escapeHtml(productName)}">
                    <i class="bi bi-link-45deg"></i> Сопоставлен${productName ? ': ' + App.escapeHtml(truncatedName) : ''}
                </span>`;
            }

            const isSelected = this.selectedOzonProducts.has(String(product.product_id));

            return `
                <div class="list-group-item list-group-item-action bg-dark text-white border-secondary ozon-product-item
                    ${product.is_mapped ? 'mapped' : ''} ${isSelected ? 'selected' : ''}"
                   data-id="${product.product_id}">
                    <div class="d-flex align-items-start">
                        <div class="form-check me-2 mt-1">
                            <input class="form-check-input ozon-product-checkbox" type="checkbox"
                                   value="${product.product_id}"
                                   ${isSelected ? 'checked' : ''}
                                   ${product.is_mapped ? 'disabled' : ''}>
                        </div>
                        <div class="flex-grow-1 me-2 ozon-product-content" style="max-width: calc(100% - 100px);">
                            <div class="product-name-wrapper">
                                <strong class="product-name-text text-truncate d-block">${App.escapeHtml(product.name || '')}</strong>
                                <button type="button" class="btn-copy-name" title="Копировать название">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="small text-muted">${App.escapeHtml(product.offer_id || product.sku || '')}</div>
                            ${mappingBadge}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-info">${App.formatPrice(product.price || 0)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        list.innerHTML = headerHtml + itemsHtml;

        // Привязываем события чекбоксов
        this.bindOzonCheckboxEvents();
    },

    /**
     * Привязка событий чекбоксов товаров Ozon
     */
    bindOzonCheckboxEvents() {
        // Чекбокс "Выбрать все"
        const selectAllCheckbox = document.getElementById('selectAllOzon');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleSelectAllOzon(e.target.checked);
            });
        }

        // Индивидуальные чекбоксы
        document.querySelectorAll('.ozon-product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                e.stopPropagation();
                const productId = String(checkbox.value);
                if (checkbox.checked) {
                    this.selectedOzonProducts.add(productId);
                } else {
                    this.selectedOzonProducts.delete(productId);
                }
                this.updateOzonSelectionUI();
            });
        });

        // Клик на контент товара тоже переключает чекбокс
        document.querySelectorAll('.ozon-product-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // Не реагируем если кликнули на чекбокс или кнопку копирования
                if (e.target.closest('.form-check-input') || e.target.closest('.btn-copy-name')) return;

                const checkbox = item.querySelector('.ozon-product-checkbox');
                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = !checkbox.checked;
                    const productId = String(checkbox.value);
                    if (checkbox.checked) {
                        this.selectedOzonProducts.add(productId);
                    } else {
                        this.selectedOzonProducts.delete(productId);
                    }
                    this.updateOzonSelectionUI();
                }
            });
        });
    },

    /**
     * Выбрать/снять все товары Ozon
     */
    toggleSelectAllOzon(checked) {
        const checkboxes = document.querySelectorAll('.ozon-product-checkbox:not(:disabled)');

        if (checked) {
            checkboxes.forEach(cb => {
                this.selectedOzonProducts.add(String(cb.value));
                cb.checked = true;
            });
        } else {
            checkboxes.forEach(cb => {
                this.selectedOzonProducts.delete(String(cb.value));
                cb.checked = false;
            });
        }

        this.updateOzonSelectionUI();
    },

    /**
     * Обновление UI после изменения выбора товаров Ozon
     */
    updateOzonSelectionUI() {
        // Обновляем счётчик
        const counter = document.getElementById('selectedOzonCount');
        if (counter) {
            counter.textContent = `Выбрано: ${this.selectedOzonProducts.size}`;
        }

        // Обновляем визуальное выделение строк
        document.querySelectorAll('.ozon-product-item').forEach(item => {
            const productId = String(item.dataset.id);
            item.classList.toggle('selected', this.selectedOzonProducts.has(productId));
        });

        // Обновляем состояние "Выбрать все"
        const selectAll = document.getElementById('selectAllOzon');
        if (selectAll) {
            const allCheckboxes = document.querySelectorAll('.ozon-product-checkbox:not(:disabled)');
            const checkedCount = document.querySelectorAll('.ozon-product-checkbox:checked').length;
            selectAll.checked = allCheckboxes.length > 0 && checkedCount === allCheckboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
        }

        // Обновляем кнопку сопоставления
        this.updateMappingButton();
        this.updateSelectedInfo();
    },

    /**
     * Рендеринг таблицы сопоставлений
     */
    renderMappings() {
        const tbody = document.getElementById('mappingsTableBody');
        if (!tbody) return;

        const searchTerm = (document.getElementById('searchMappings')?.value || '').toLowerCase();

        let filtered = this.mappings;
        if (searchTerm) {
            filtered = this.mappings.filter(m =>
                (m.name || '').toLowerCase().includes(searchTerm) ||
                (m.marketplace_offer_id || '').toLowerCase().includes(searchTerm) ||
                (m.marketplace_name || '').toLowerCase().includes(searchTerm)
            );
        }

        document.getElementById('mappingsTotal').textContent = filtered.length;

        if (filtered.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="bi bi-link-45deg display-6"></i>
                        <p class="mt-2 mb-0">Нет сопоставлений</p>
                        <small>Перейдите на шаг 2 для создания сопоставлений</small>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = filtered.map(mapping => `
            <tr data-id="${mapping.mapping_id}">
                <td>
                    <strong>${App.escapeHtml(mapping.name || '')}</strong>
                    <br><small class="text-muted">${App.escapeHtml(mapping.sku || '')}</small>
                </td>
                <td>
                    <code>${App.escapeHtml(mapping.marketplace_offer_id || mapping.marketplace_product_id || '')}</code>
                </td>
                <td class="text-truncate" style="max-width: 250px;" title="${App.escapeHtml(mapping.marketplace_name || '')}">
                    ${App.escapeHtml(mapping.marketplace_name || '-')}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-qty-btn"
                            data-id="${mapping.mapping_id}" data-qty="${mapping.quantity_in_pack || 1}">
                        ${mapping.quantity_in_pack || 1} шт.
                    </button>
                </td>
                <td class="text-end">${App.formatPrice(mapping.mp_price || 0)}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger delete-mapping-btn" data-id="${mapping.mapping_id}" title="Удалить сопоставление">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');

        // Привязываем события
        tbody.querySelectorAll('.delete-mapping-btn').forEach(btn => {
            btn.addEventListener('click', () => this.deleteMapping(btn.dataset.id));
        });

        tbody.querySelectorAll('.edit-qty-btn').forEach(btn => {
            btn.addEventListener('click', () => this.openQuantityModal(btn.dataset.id, btn.dataset.qty));
        });
    },

    /**
     * Выбор нашего товара
     */
    selectOurProduct(id) {
        this.selectedOurProduct = this.ourProducts.find(p => String(p.id) === String(id)) || null;

        // Обновляем визуальное выделение
        document.querySelectorAll('.our-product-item').forEach(item => {
            item.classList.toggle('active', item.dataset.id === id);
        });

        this.updateSelectedInfo();
        this.updateMappingButton();
    },


    /**
     * Обновление информации о выбранных товарах
     */
    updateSelectedInfo() {
        const infoDiv = document.getElementById('selectedInfo');
        if (!infoDiv) return;

        if (this.selectedOurProduct || this.selectedOzonProducts.size > 0) {
            infoDiv.classList.remove('d-none');
            document.getElementById('selectedOurProduct').textContent =
                this.selectedOurProduct ? this.selectedOurProduct.name : '-';

            // Показываем количество выбранных товаров Ozon
            const ozonText = this.selectedOzonProducts.size > 0
                ? `${this.selectedOzonProducts.size} товар(ов)`
                : '-';
            document.getElementById('selectedOzonProduct').textContent = ozonText;
        } else {
            infoDiv.classList.add('d-none');
        }
    },

    /**
     * Обновление состояния кнопки сопоставления
     */
    updateMappingButton() {
        const btn = document.getElementById('createMappingBtn');
        if (!btn) return;

        // Кнопка активна если выбран наш товар и хотя бы один товар Ozon
        const canCreate = this.selectedOurProduct && this.selectedOzonProducts.size > 0;
        btn.disabled = !canCreate;

        // Обновляем текст кнопки с количеством
        if (this.selectedOzonProducts.size > 1) {
            btn.innerHTML = `<i class="bi bi-link-45deg me-1"></i> Сопоставить (${this.selectedOzonProducts.size})`;
        } else {
            btn.innerHTML = `<i class="bi bi-link-45deg me-1"></i> Сопоставить`;
        }
    },

    /**
     * Создание сопоставления (массовое)
     */
    async createMapping() {
        if (!this.selectedOurProduct || this.selectedOzonProducts.size === 0) return;

        const btn = document.getElementById('createMappingBtn');
        const originalBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Сопоставление...';

        let successCount = 0;
        let errorCount = 0;

        // Сопоставляем каждый выбранный товар
        for (const ozonProductId of this.selectedOzonProducts) {
            const ozonProduct = this.ozonProducts.find(p => String(p.product_id) === ozonProductId);
            if (!ozonProduct) continue;

            try {
                await App.fetch('/api/ozon/create-mapping', {
                    method: 'POST',
                    body: {
                        product_id: this.selectedOurProduct.id,
                        marketplace_product_id: ozonProduct.product_id,
                        marketplace_sku: ozonProduct.sku,
                        marketplace_offer_id: ozonProduct.offer_id,
                        marketplace_name: ozonProduct.name
                    }
                });

                // Обновляем флаг сопоставления в локальных данных
                ozonProduct.is_mapped = true;
                ozonProduct.our_product_name = this.selectedOurProduct.name;
                successCount++;

            } catch (error) {
                console.error('Mapping error for', ozonProductId, error);
                errorCount++;
            }
        }

        // Показываем результат
        if (successCount > 0) {
            App.showToast(`Успешно сопоставлено: ${successCount} товар(ов)`, 'success');
        }
        if (errorCount > 0) {
            App.showToast(`Ошибок: ${errorCount}`, 'danger');
        }

        // Перезагружаем сопоставления
        await this.loadMappings();
        await this.loadStatistics();

        // Сбрасываем выбор и перерендериваем
        this.selectedOzonProducts.clear();
        this.renderOzonProducts();
        this.updateMappingButton();
        this.updateSelectedInfo();

        btn.disabled = false;
        btn.innerHTML = originalBtnText;
    },

    /**
     * Удаление сопоставления
     */
    async deleteMapping(mappingId) {
        const confirmed = await App.confirm('Удалить это сопоставление?', 'Подтверждение');
        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/ozon/delete-mapping', {
                method: 'POST',
                body: { mapping_id: mappingId }
            });

            App.showToast(data.message || 'Сопоставление удалено', 'success');

            // Перезагружаем данные
            await this.loadMappings();
            await this.loadOzonProducts();
            await this.loadStatistics();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Сброс выбора
     */
    clearSelections() {
        this.selectedOurProduct = null;
        this.selectedOzonProducts.clear();

        document.querySelectorAll('.our-product-item').forEach(item => {
            item.classList.remove('active');
        });

        document.querySelectorAll('.ozon-product-item').forEach(item => {
            item.classList.remove('active', 'selected');
        });

        document.querySelectorAll('.ozon-product-checkbox').forEach(cb => {
            cb.checked = false;
        });

        const selectAll = document.getElementById('selectAllOzon');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }

        this.updateSelectedInfo();
        this.updateMappingButton();
    },

    /**
     * Открытие модального окна добавления товара
     */
    openAddProductModal() {
        // Очищаем форму
        document.getElementById('addProductForm')?.reset();

        const modal = new bootstrap.Modal(document.getElementById('addProductModal'));
        modal.show();
    },

    /**
     * Сохранение нового товара
     */
    async saveProduct() {
        const name = document.getElementById('productName')?.value?.trim();
        const costPrice = parseFloat(document.getElementById('productCostPrice')?.value) || 0;

        if (!name) {
            App.showToast('Введите название товара', 'warning');
            return;
        }

        if (costPrice <= 0) {
            App.showToast('Введите себестоимость', 'warning');
            return;
        }

        try {
            const data = await App.fetch('/api/ozon/save-product', {
                method: 'POST',
                body: {
                    name: name,
                    sku: document.getElementById('productSku')?.value?.trim() || '',
                    category: document.getElementById('productCategory')?.value?.trim() || '',
                    material_type: document.getElementById('productMaterial')?.value?.trim() || '',
                    grade: document.getElementById('productGrade')?.value?.trim() || '',
                    thickness: parseFloat(document.getElementById('productThickness')?.value) || null,
                    cost_price: costPrice,
                    base_price: parseFloat(document.getElementById('productBasePrice')?.value) || costPrice
                }
            });

            bootstrap.Modal.getInstance(document.getElementById('addProductModal'))?.hide();
            App.showToast(data.message || 'Товар добавлен', 'success');

            // Перезагружаем список товаров
            await this.loadOurProducts();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Открытие модального окна редактирования количества в упаковке
     */
    openQuantityModal(mappingId, currentQty) {
        document.getElementById('editQtyMappingId').value = mappingId;
        document.getElementById('editQtyValue').value = currentQty;

        const modal = new bootstrap.Modal(document.getElementById('editQuantityModal'));
        modal.show();
    },

    /**
     * Сохранение количества в упаковке
     */
    async saveQuantity() {
        const mappingId = document.getElementById('editQtyMappingId').value;
        const quantity = parseInt(document.getElementById('editQtyValue').value) || 1;

        try {
            await App.fetch('/api/ozon/update-quantity', {
                method: 'POST',
                body: {
                    mapping_id: mappingId,
                    quantity_in_pack: quantity
                }
            });

            bootstrap.Modal.getInstance(document.getElementById('editQuantityModal'))?.hide();
            App.showToast('Сохранено', 'success');
            await this.loadMappings();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Форматирование времени синхронизации
     */
    formatSyncTime(isoString) {
        if (!isoString) return '-';

        const date = new Date(isoString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) return 'только что';
        if (diffMins < 60) return `${diffMins} мин. назад`;

        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return `${diffHours} ч. назад`;

        return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => OzonMapping.init());
