/**
 * Price Manager - Сопоставление товаров Wildberries
 * 3-шаговый интерфейс: Загрузка -> Сопоставление -> Просмотр
 */

const WBMapping = {
    // Данные
    ourProducts: [],
    wbProducts: [],
    mappings: [],

    // Выбранные элементы
    selectedOurProduct: null,
    selectedWbProduct: null,

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
        document.getElementById('syncWbBtn')?.addEventListener('click', () => this.syncFromWB());
        document.getElementById('skipToStep2Btn')?.addEventListener('click', () => this.goToStep(2));

        // === Шаг 2: Сопоставление ===
        document.getElementById('backToStep1Btn')?.addEventListener('click', () => this.goToStep(1));
        document.getElementById('goToStep3Btn')?.addEventListener('click', () => this.goToStep(3));
        document.getElementById('createMappingBtn')?.addEventListener('click', () => this.createMapping());

        // Поиск товаров
        document.getElementById('searchOurProducts')?.addEventListener('input',
            App.debounce(() => this.renderOurProducts(), 300));
        document.getElementById('searchWbProducts')?.addEventListener('input',
            App.debounce(() => this.renderWbProducts(), 300));

        // Фильтр товаров WB
        document.querySelectorAll('input[name="wbFilter"]').forEach(radio => {
            radio.addEventListener('change', () => this.renderWbProducts());
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
            const data = await App.fetch('/api/wb/products?limit=1');

            if (data.stats && data.stats.total_products > 0) {
                // Есть кэшированные товары - показываем кнопку пропуска
                document.getElementById('skipStep1')?.classList.remove('d-none');
                document.getElementById('cachedProductsCount').textContent = data.stats.total_products;

                // Показываем время последней синхронизации
                if (data.stats.last_sync) {
                    this.lastSyncTime = data.stats.last_sync;
                    document.getElementById('syncTimeValue').textContent = this.formatSyncTime(data.stats.last_sync);
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
            this.loadWbProducts()
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
     * Синхронизация товаров с Wildberries
     */
    async syncFromWB() {
        const syncBtn = document.getElementById('syncWbBtn');
        const progressDiv = document.getElementById('syncProgress');
        const progressText = document.getElementById('syncProgressText');

        try {
            // Показываем прогресс
            syncBtn.disabled = true;
            progressDiv?.classList.remove('d-none');
            progressText.textContent = 'Подключение к Wildberries...';

            const data = await App.fetch('/api/wb/sync-products', {
                method: 'POST',
                body: {},
                timeout: 120000
            });

            progressText.textContent = 'Синхронизация завершена!';

            App.showToast(data.message || `Загружено ${data.synced || 0} товаров`, 'success');

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
            const data = await App.fetch('/api/products');
            this.ourProducts = data.products || [];
            this.renderOurProducts();
        } catch (error) {
            App.showToast('Ошибка загрузки товаров: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка товаров WB из кэша
     */
    async loadWbProducts() {
        try {
            const data = await App.fetch('/api/wb/products');
            this.wbProducts = data.products || [];
            this.renderWbProducts();
        } catch (error) {
            App.showToast('Ошибка загрузки товаров WB: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка сопоставлений
     */
    async loadMappings() {
        try {
            const data = await App.fetch('/api/wb/mapping');
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
            const [productsData, mappingsData] = await Promise.all([
                App.fetch('/api/products'),
                App.fetch('/api/wb/products?limit=1')
            ]);

            const ourCount = (productsData.products || []).length;
            const wbCount = mappingsData.stats?.total_products || 0;
            const mappedCount = mappingsData.stats?.mapped_count || 0;

            document.getElementById('statOurProducts').textContent = ourCount;
            document.getElementById('statWbProducts').textContent = wbCount;
            document.getElementById('statMapped').textContent = mappedCount;

            if (wbCount > 0) {
                const percent = Math.round((mappedCount / wbCount) * 100);
                document.getElementById('statMappedPercent').textContent = `(${percent}%)`;
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
            const mappingCount = this.getMappingCountForProduct(product.id);

            return `
                <a href="#" class="list-group-item list-group-item-action bg-dark text-white border-secondary our-product-item"
                   data-id="${product.id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 me-2">
                            <strong>${App.escapeHtml(product.name || '')}</strong>
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
     * Рендеринг списка товаров WB
     */
    renderWbProducts() {
        const list = document.getElementById('wbProductsList');
        if (!list) return;

        const searchTerm = (document.getElementById('searchWbProducts')?.value || '').toLowerCase();
        const filterValue = document.querySelector('input[name="wbFilter"]:checked')?.value || 'all';

        let filtered = this.wbProducts.filter(p => {
            // Поиск (title - название из кэша WB)
            if (searchTerm) {
                const matches = (p.title || p.name || '').toLowerCase().includes(searchTerm) ||
                               (p.vendor_code || '').toLowerCase().includes(searchTerm) ||
                               String(p.nm_id || '').includes(searchTerm);
                if (!matches) return false;
            }

            // Фильтр по сопоставлению
            if (filterValue === 'unmapped' && p.is_mapped) return false;

            return true;
        });

        document.getElementById('wbProductsCount').textContent = filtered.length;

        if (filtered.length === 0) {
            list.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cloud display-6"></i>
                    <p class="mt-2 mb-0">Нет товаров</p>
                    <small>Синхронизируйте товары с Wildberries</small>
                </div>
            `;
            return;
        }

        list.innerHTML = filtered.map(product => {
            // Название товара WB (в кэше поле называется title)
            const wbProductName = product.title || product.name || '';

            // Бейдж сопоставления с названием нашего товара
            let mappingBadge = '';
            if (product.is_mapped) {
                const ourName = product.our_product_name || '';
                const truncatedName = ourName.length > 25
                    ? ourName.substring(0, 25) + '...'
                    : ourName;
                mappingBadge = `
                    <div class="mapped-info mt-1">
                        <span class="badge bg-success">
                            <i class="bi bi-check-lg"></i> Сопоставлено
                        </span>
                        ${ourName ? `<div class="small text-success mt-1" title="${App.escapeHtml(ourName)}">
                            <i class="bi bi-arrow-right"></i> ${App.escapeHtml(truncatedName)}
                        </div>` : ''}
                    </div>`;
            }

            return `
                <a href="#" class="list-group-item list-group-item-action bg-dark text-white border-secondary wb-product-item
                    ${product.is_mapped ? 'mapped' : ''}"
                   data-id="${product.nm_id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 me-2" style="max-width: 75%;">
                            <strong class="text-truncate d-block">${App.escapeHtml(wbProductName)}</strong>
                            <div class="small text-muted">${App.escapeHtml(product.vendor_code || '')} | nmID: ${product.nm_id}</div>
                            ${mappingBadge}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-danger">${App.formatPrice(product.price || 0)}</span>
                        </div>
                    </div>
                </a>
            `;
        }).join('');

        // Привязываем события
        list.querySelectorAll('.wb-product-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                this.selectWbProduct(item.dataset.id);
            });
        });
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
                (m.vendor_code || '').toLowerCase().includes(searchTerm) ||
                (m.wb_name || '').toLowerCase().includes(searchTerm)
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
                    <code>${App.escapeHtml(mapping.vendor_code || '')}</code>
                    <br><small class="text-muted">nmID: ${mapping.nm_id || ''}</small>
                </td>
                <td class="text-truncate" style="max-width: 250px;" title="${App.escapeHtml(mapping.wb_name || '')}">
                    ${App.escapeHtml(mapping.wb_name || '-')}
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-qty-btn"
                            data-id="${mapping.mapping_id}" data-qty="${mapping.quantity_in_pack || 1}">
                        ${mapping.quantity_in_pack || 1} шт.
                    </button>
                </td>
                <td class="text-end">${App.formatPrice(mapping.wb_price || 0)}</td>
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

        document.querySelectorAll('.our-product-item').forEach(item => {
            item.classList.toggle('active', item.dataset.id === id);
        });

        this.updateSelectedInfo();
        this.updateMappingButton();
    },

    /**
     * Выбор товара WB
     */
    selectWbProduct(id) {
        this.selectedWbProduct = this.wbProducts.find(p => String(p.nm_id) === String(id)) || null;

        document.querySelectorAll('.wb-product-item').forEach(item => {
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

        if (this.selectedOurProduct || this.selectedWbProduct) {
            infoDiv.classList.remove('d-none');
            document.getElementById('selectedOurProduct').textContent =
                this.selectedOurProduct ? this.selectedOurProduct.name : '-';
            document.getElementById('selectedWbProduct').textContent =
                this.selectedWbProduct ? (this.selectedWbProduct.title || this.selectedWbProduct.name || '-') : '-';
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

        btn.disabled = !(
            this.selectedOurProduct &&
            this.selectedWbProduct &&
            !this.selectedWbProduct.is_mapped
        );
    },

    /**
     * Создание сопоставления
     */
    async createMapping() {
        if (!this.selectedOurProduct || !this.selectedWbProduct) return;

        try {
            const data = await App.fetch('/api/wb/mapping', {
                method: 'POST',
                body: {
                    action: 'create',
                    product_id: this.selectedOurProduct.id,
                    nm_id: this.selectedWbProduct.nm_id
                }
            });

            App.showToast(data.message || 'Сопоставление создано', 'success');

            // Обновляем флаг сопоставления в локальных данных
            const wbProduct = this.wbProducts.find(p =>
                String(p.nm_id) === String(this.selectedWbProduct.nm_id)
            );
            if (wbProduct) {
                wbProduct.is_mapped = true;
            }

            // Перезагружаем сопоставления
            await this.loadMappings();

            // Сбрасываем выбор
            this.clearSelections();
            this.renderWbProducts();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Удаление сопоставления
     */
    async deleteMapping(mappingId) {
        const confirmed = await App.confirm('Удалить это сопоставление?', 'Подтверждение');
        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/wb/mapping', {
                method: 'DELETE',
                body: { mapping_id: mappingId }
            });

            App.showToast(data.message || 'Сопоставление удалено', 'success');

            // Перезагружаем данные
            await this.loadMappings();
            await this.loadWbProducts();
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
        this.selectedWbProduct = null;

        document.querySelectorAll('.our-product-item, .wb-product-item').forEach(item => {
            item.classList.remove('active');
        });

        this.updateSelectedInfo();
        this.updateMappingButton();
    },

    /**
     * Открытие модального окна добавления товара
     */
    openAddProductModal() {
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
            const data = await App.fetch('/api/products', {
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
            await App.fetch('/api/wb/mapping', {
                method: 'POST',
                body: {
                    action: 'update_pack',
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
document.addEventListener('DOMContentLoaded', () => WBMapping.init());
