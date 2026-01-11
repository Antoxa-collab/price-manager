/**
 * Price Manager - Сопоставление товаров Яндекс.Маркет
 * 3-шаговый интерфейс: Загрузка -> Сопоставление -> Просмотр
 */

/**
 * Копирование текста в буфер обмена с fallback для HTTP
 * @param {string} text - текст для копирования
 * @returns {Promise<boolean>} - успех операции
 */
function copyToClipboard(text) {
    return new Promise((resolve) => {
        // Способ 1: Clipboard API (работает только по HTTPS)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
                .then(() => resolve(true))
                .catch(() => resolve(fallbackCopy(text)));
        } else {
            // Способ 2: Fallback через textarea (работает по HTTP)
            resolve(fallbackCopy(text));
        }
    });
}

/**
 * Fallback копирование через временный textarea
 */
function fallbackCopy(text) {
    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        const success = document.execCommand('copy');
        document.body.removeChild(textarea);
        return success;
    } catch (err) {
        console.error('Fallback copy failed:', err);
        return false;
    }
}

const YMMapping = {
    // Данные
    ourProducts: [],
    ymProducts: [],
    mappings: [],

    // Выбранные элементы
    selectedOurProduct: null,
    selectedYmProducts: new Set(), // Множественный выбор товаров ЯМ

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
        document.getElementById('syncYmBtn')?.addEventListener('click', () => this.syncFromYM());
        document.getElementById('skipToStep2Btn')?.addEventListener('click', () => this.goToStep(2));

        // === Шаг 2: Сопоставление ===
        document.getElementById('goToStep3Btn')?.addEventListener('click', () => this.goToStep(3));
        document.getElementById('createMappingBtn')?.addEventListener('click', () => this.createMapping());

        // Поиск товаров
        document.getElementById('searchOurProducts')?.addEventListener('input',
            App.debounce(() => this.renderOurProducts(), 300));
        document.getElementById('searchYmProducts')?.addEventListener('input',
            App.debounce(() => {
                this.renderYmProducts();
                this.updateBulkSelectState();
            }, 300));

        // Фильтр связанных товаров ЯМ
        document.getElementById('hideLinkedYm')?.addEventListener('change', () => {
            this.renderYmProducts();
            this.updateBulkSelectState();
        });

        // === Массовый выбор товаров ЯМ ===
        const ymSelectAll = document.getElementById('ymSelectAll');
        const ymSelectUnlinked = document.getElementById('ymSelectUnlinked');
        const ymClearSelection = document.getElementById('ymClearSelection');

        console.log('[YMMapping] Binding bulk select events:', {
            ymSelectAll: !!ymSelectAll,
            ymSelectUnlinked: !!ymSelectUnlinked,
            ymClearSelection: !!ymClearSelection
        });

        ymSelectAll?.addEventListener('change', (e) => {
            console.log('[YMMapping] ymSelectAll change event fired');
            this.selectAllYmProducts(e.target.checked);
        });
        ymSelectUnlinked?.addEventListener('change', (e) => {
            console.log('[YMMapping] ymSelectUnlinked change event fired');
            this.selectUnlinkedYmProducts(e.target.checked);
        });
        ymClearSelection?.addEventListener('click', () => {
            console.log('[YMMapping] ymClearSelection click event fired');
            this.clearYmSelection();
        });

        // Добавление товара
        document.getElementById('addProductBtn')?.addEventListener('click', () => this.openAddProductModal());
        document.getElementById('saveNewProductBtn')?.addEventListener('click', () => this.saveNewProduct());

        // === Шаг 3: Просмотр ===
        document.getElementById('backToStep2Btn')?.addEventListener('click', () => this.goToStep(2));
        document.getElementById('refreshMappingsBtn')?.addEventListener('click', () => this.loadMappings());

        // Редактирование сопоставления
        document.getElementById('saveMappingBtn')?.addEventListener('click', () => this.saveMappingEdit());
    },

    /**
     * Проверка начального состояния (есть ли уже товары)
     */
    async checkInitialState() {
        try {
            // Загружаем информацию о кэше
            const data = await App.fetch('/api/yandex/products?limit=1');

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
            this.loadYmProducts()
        ]);
    },

    /**
     * Загрузка данных для шага 3
     */
    async loadStep3Data() {
        await this.loadMappings();
    },

    /**
     * Синхронизация товаров с Яндекс.Маркет
     */
    async syncFromYM() {
        const syncBtn = document.getElementById('syncYmBtn');
        const progressDiv = document.getElementById('syncProgress');
        const progressText = document.getElementById('syncProgressText');

        try {
            // Показываем прогресс
            syncBtn.disabled = true;
            progressDiv?.classList.remove('d-none');
            progressText.textContent = 'Подключение к Яндекс.Маркет...';

            const data = await App.fetch('/api/yandex/sync-products', {
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
     * Загрузка товаров ЯМ из кэша
     */
    async loadYmProducts() {
        try {
            // Загружаем все товары (limit=0 означает без лимита)
            const data = await App.fetch('/api/yandex/products?limit=0');
            this.ymProducts = data.products || [];
            this.renderYmProducts();
        } catch (error) {
            App.showToast('Ошибка загрузки товаров ЯМ: ' + error.message, 'danger');
        }
    },

    /**
     * Загрузка сопоставлений
     */
    async loadMappings() {
        try {
            const data = await App.fetch('/api/yandex/mapping');
            this.mappings = data.mappings || [];
            this.renderMappings();
            document.getElementById('mappingsCount').textContent = this.mappings.length;
        } catch (error) {
            App.showToast('Ошибка загрузки сопоставлений: ' + error.message, 'danger');
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

        // Кнопки копирования названия
        list.querySelectorAll('.btn-copy-name').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const text = btn.closest('.product-name-wrapper').querySelector('.product-name-text').textContent;
                copyToClipboard(text).then((success) => {
                    if (success) {
                        App.showToast('Название скопировано', 'success');
                    } else {
                        App.showToast('Не удалось скопировать', 'danger');
                    }
                });
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
     * Рендеринг списка товаров ЯМ
     */
    renderYmProducts() {
        const list = document.getElementById('ymProductsList');
        if (!list) return;

        const searchTerm = (document.getElementById('searchYmProducts')?.value || '').toLowerCase();
        const hideLinked = document.getElementById('hideLinkedYm')?.checked || false;

        let filtered = this.ymProducts.filter(p => {
            // Поиск
            if (searchTerm) {
                const matches = (p.name || '').toLowerCase().includes(searchTerm) ||
                               (p.offer_id || '').toLowerCase().includes(searchTerm) ||
                               (p.barcode || '').toLowerCase().includes(searchTerm);
                if (!matches) return false;
            }

            // Скрыть связанные
            if (hideLinked && p.is_mapped) return false;

            return true;
        });

        document.getElementById('ymProductsCount').textContent = filtered.length;

        if (filtered.length === 0) {
            list.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cloud display-6"></i>
                    <p class="mt-2 mb-0">Нет товаров</p>
                    <small>Синхронизируйте товары с Яндекс.Маркет</small>
                </div>
            `;
            return;
        }

        list.innerHTML = filtered.map(product => {
            const isSelected = this.selectedYmProducts.has(product.offer_id);

            // Бейдж сопоставления
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
                <div class="list-group-item list-group-item-action bg-dark text-white border-secondary ym-product-item
                    ${product.is_mapped ? 'mapped' : ''} ${isSelected ? 'selected' : ''}"
                   data-id="${product.offer_id}">
                    <div class="d-flex align-items-start">
                        <div class="form-check me-2 mt-1">
                            <input class="form-check-input ym-product-checkbox" type="checkbox"
                                   value="${product.offer_id}"
                                   ${isSelected ? 'checked' : ''}
                                   ${product.is_mapped ? 'disabled' : ''}>
                        </div>
                        <div class="flex-grow-1 me-2 ym-product-content" style="max-width: calc(100% - 100px);">
                            <div class="product-name-wrapper">
                                <strong class="product-name-text text-truncate d-block">${App.escapeHtml(product.name || '')}</strong>
                                <button type="button" class="btn-copy-name" title="Копировать название">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="small text-muted">
                                ${App.escapeHtml(product.offer_id || '')}
                                ${product.barcode ? ` | ${product.barcode}` : ''}
                            </div>
                            ${mappingBadge}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark">${App.formatPrice(product.price || 0)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Привязываем события
        this.bindYmCheckboxEvents();
    },

    /**
     * Привязка событий чекбоксов товаров ЯМ
     */
    bindYmCheckboxEvents() {
        // Индивидуальные чекбоксы
        document.querySelectorAll('.ym-product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                e.stopPropagation();
                const productId = checkbox.value;
                if (checkbox.checked) {
                    this.selectedYmProducts.add(productId);
                } else {
                    this.selectedYmProducts.delete(productId);
                }
                this.updateYmSelectionUI();
            });
        });

        // Клик на контент товара тоже переключает чекбокс
        document.querySelectorAll('.ym-product-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // Не реагируем если кликнули на чекбокс или кнопку копирования
                if (e.target.closest('.form-check-input') || e.target.closest('.btn-copy-name')) return;

                const checkbox = item.querySelector('.ym-product-checkbox');
                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = !checkbox.checked;
                    const productId = checkbox.value;
                    if (checkbox.checked) {
                        this.selectedYmProducts.add(productId);
                    } else {
                        this.selectedYmProducts.delete(productId);
                    }
                    this.updateYmSelectionUI();
                }
            });
        });

        // Кнопки копирования названия
        document.querySelectorAll('.ym-product-item .btn-copy-name').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const text = btn.closest('.product-name-wrapper').querySelector('.product-name-text').textContent;
                copyToClipboard(text).then((success) => {
                    if (success) {
                        App.showToast('Название скопировано', 'success');
                    } else {
                        App.showToast('Не удалось скопировать', 'danger');
                    }
                });
            });
        });
    },

    /**
     * Обновление UI после изменения выбора товаров ЯМ
     */
    updateYmSelectionUI() {
        // Обновляем визуальное выделение строк
        document.querySelectorAll('.ym-product-item').forEach(item => {
            const productId = item.dataset.id;
            item.classList.toggle('selected', this.selectedYmProducts.has(productId));
        });

        // Обновляем счётчик выбранных
        this.updateSelectedCounter();

        // Обновляем состояние чекбоксов массового выбора
        this.updateBulkSelectState();

        // Обновляем кнопку сопоставления
        this.updateMappingButton();
        this.updateSelectedInfo();
    },

    /**
     * Обновление счётчика выбранных товаров
     */
    updateSelectedCounter() {
        const counter = document.querySelector('#ymSelectedCount strong');
        console.log('[YMMapping] updateSelectedCounter:', this.selectedYmProducts.size, 'counter element:', !!counter);

        if (counter) {
            counter.textContent = this.selectedYmProducts.size;
        }

        // Показать/скрыть кнопку очистки
        const clearBtn = document.getElementById('ymClearSelection');
        if (clearBtn) {
            clearBtn.classList.toggle('d-none', this.selectedYmProducts.size === 0);
        }
    },

    /**
     * Обновление состояния чекбоксов массового выбора
     */
    updateBulkSelectState() {
        const selectAllCheckbox = document.getElementById('ymSelectAll');
        const selectUnlinkedCheckbox = document.getElementById('ymSelectUnlinked');

        if (!selectAllCheckbox) return;

        // Получаем видимые товары (не связанные)
        const visibleCheckboxes = document.querySelectorAll('.ym-product-checkbox:not(:disabled)');
        const checkedCount = document.querySelectorAll('.ym-product-checkbox:checked').length;
        const totalVisible = visibleCheckboxes.length;

        // Обновляем состояние "Выбрать все"
        if (checkedCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedCount === totalVisible && totalVisible > 0) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }

        // Обновляем состояние "Только без связи"
        if (selectUnlinkedCheckbox) {
            // Проверяем, все ли несвязанные выбраны
            const unlinkedItems = document.querySelectorAll('.ym-product-item:not(.mapped)');
            let allUnlinkedSelected = true;
            let anyUnlinkedSelected = false;

            unlinkedItems.forEach(item => {
                const cb = item.querySelector('.ym-product-checkbox');
                if (cb && !cb.disabled) {
                    if (cb.checked) {
                        anyUnlinkedSelected = true;
                    } else {
                        allUnlinkedSelected = false;
                    }
                }
            });

            selectUnlinkedCheckbox.checked = allUnlinkedSelected && anyUnlinkedSelected;
        }
    },

    /**
     * Выбрать все видимые товары ЯМ
     */
    selectAllYmProducts(checked) {
        console.log('[YMMapping] selectAllYmProducts called, checked:', checked);

        const checkboxes = document.querySelectorAll('.ym-product-checkbox:not(:disabled)');
        console.log('[YMMapping] Found checkboxes:', checkboxes.length);

        if (checkboxes.length === 0) {
            console.warn('[YMMapping] No checkboxes found! Products may not be loaded yet.');
            return;
        }

        checkboxes.forEach(cb => {
            cb.checked = checked;
            const productId = cb.value;
            if (checked) {
                this.selectedYmProducts.add(productId);
            } else {
                this.selectedYmProducts.delete(productId);
            }
        });

        // Снимаем "Только без связи" если снимаем "Выбрать все"
        if (!checked) {
            const selectUnlinkedCheckbox = document.getElementById('ymSelectUnlinked');
            if (selectUnlinkedCheckbox) selectUnlinkedCheckbox.checked = false;
        }

        console.log('[YMMapping] Selected products:', this.selectedYmProducts.size);
        this.updateYmSelectionUI();
    },

    /**
     * Выбрать только несопоставленные товары ЯМ
     */
    selectUnlinkedYmProducts(checked) {
        console.log('[YMMapping] selectUnlinkedYmProducts called, checked:', checked);

        // Сначала снимаем все выборы
        this.clearYmSelection(false); // false = не обновлять UI сразу

        if (checked) {
            // Выбираем только несвязанные (БЕЗ класса .mapped)
            const unlinkedItems = document.querySelectorAll('.ym-product-item:not(.mapped)');
            console.log('[YMMapping] Found unlinked items:', unlinkedItems.length);

            unlinkedItems.forEach(item => {
                const cb = item.querySelector('.ym-product-checkbox');
                if (cb && !cb.disabled) {
                    cb.checked = true;
                    this.selectedYmProducts.add(cb.value);
                }
            });
        }

        // Снимаем "Выбрать все"
        const selectAllCheckbox = document.getElementById('ymSelectAll');
        if (selectAllCheckbox) selectAllCheckbox.checked = false;

        console.log('[YMMapping] Selected unlinked products:', this.selectedYmProducts.size);
        this.updateYmSelectionUI();
    },

    /**
     * Снять выбор со всех товаров ЯМ
     */
    clearYmSelection(updateUI = true) {
        const checkboxes = document.querySelectorAll('.ym-product-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });

        this.selectedYmProducts.clear();

        // Сбрасываем чекбоксы массового выбора
        const selectAllCheckbox = document.getElementById('ymSelectAll');
        const selectUnlinkedCheckbox = document.getElementById('ymSelectUnlinked');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
        if (selectUnlinkedCheckbox) {
            selectUnlinkedCheckbox.checked = false;
        }

        if (updateUI) {
            this.updateYmSelectionUI();
        }
    },

    /**
     * Рендеринг таблицы сопоставлений
     */
    renderMappings() {
        const tbody = document.getElementById('mappingsTableBody');
        if (!tbody) return;

        document.getElementById('mappingsCount').textContent = this.mappings.length;

        if (this.mappings.length === 0) {
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

        tbody.innerHTML = this.mappings.map(mapping => `
            <tr data-id="${mapping.mapping_id}">
                <td>
                    <strong>${App.escapeHtml(mapping.name || '')}</strong>
                    <br><small class="text-muted">${App.escapeHtml(mapping.sku || '')}</small>
                </td>
                <td class="text-truncate" style="max-width: 250px;" title="${App.escapeHtml(mapping.ym_name || '')}">
                    ${App.escapeHtml(mapping.ym_name || '-')}
                </td>
                <td>
                    <code>${App.escapeHtml(mapping.offer_id || '')}</code>
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary edit-mapping-btn"
                            data-id="${mapping.mapping_id}"
                            data-qty="${mapping.quantity_in_pack || 1}"
                            data-pieces="${mapping.pieces_per_sheet || 1}">
                        ${mapping.quantity_in_pack || 1} шт.
                    </button>
                </td>
                <td class="text-center">${mapping.pieces_per_sheet || 1}</td>
                <td class="text-end">
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

        tbody.querySelectorAll('.edit-mapping-btn').forEach(btn => {
            btn.addEventListener('click', () => this.openEditMappingModal(
                btn.dataset.id,
                btn.dataset.qty,
                btn.dataset.pieces
            ));
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
     * Обновление информации о выбранных товарах
     */
    updateSelectedInfo() {
        const infoDiv = document.getElementById('selectedInfo');
        if (!infoDiv) return;

        if (this.selectedOurProduct || this.selectedYmProducts.size > 0) {
            infoDiv.classList.remove('d-none');
            document.getElementById('selectedOurProduct').textContent =
                this.selectedOurProduct ? this.selectedOurProduct.name : '-';

            // Показываем количество выбранных товаров ЯМ
            const ymText = this.selectedYmProducts.size > 0
                ? `${this.selectedYmProducts.size} товар(ов)`
                : '-';
            document.getElementById('selectedYmProduct').textContent = ymText;
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

        // Кнопка активна если выбран наш товар и хотя бы один товар ЯМ
        const canCreate = this.selectedOurProduct && this.selectedYmProducts.size > 0;
        btn.disabled = !canCreate;
    },

    /**
     * Создание сопоставления (массовое)
     */
    async createMapping() {
        if (!this.selectedOurProduct || this.selectedYmProducts.size === 0) return;

        const btn = document.getElementById('createMappingBtn');
        const originalBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        let successCount = 0;
        let errorCount = 0;

        // Сопоставляем каждый выбранный товар
        for (const ymOfferId of this.selectedYmProducts) {
            const ymProduct = this.ymProducts.find(p => p.offer_id === ymOfferId);
            if (!ymProduct) continue;

            try {
                console.log('[YMMapping] Creating mapping:', {
                    product_id: this.selectedOurProduct.id,
                    offer_id: ymProduct.offer_id
                });

                const response = await App.fetch('/api/yandex/mapping', {
                    method: 'POST',
                    body: {
                        action: 'create',
                        product_id: this.selectedOurProduct.id,
                        offer_id: ymProduct.offer_id
                    }
                });

                console.log('[YMMapping] Mapping response:', response);

                // Обновляем флаг сопоставления в локальных данных
                ymProduct.is_mapped = true;
                ymProduct.our_product_name = this.selectedOurProduct.name;
                successCount++;

            } catch (error) {
                console.error('[YMMapping] Mapping error for', ymOfferId, error);
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

        // Сбрасываем выбор и перерендериваем
        this.selectedYmProducts.clear();
        this.renderYmProducts();
        this.renderOurProducts(); // Обновить счётчики связей
        this.updateMappingButton();
        this.updateSelectedInfo();

        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
    },

    /**
     * Удаление сопоставления
     */
    async deleteMapping(mappingId) {
        const confirmed = await App.confirm('Удалить это сопоставление?', 'Подтверждение');
        if (!confirmed) return;

        try {
            const data = await App.fetch('/api/yandex/mapping', {
                method: 'DELETE',
                body: { mapping_id: mappingId }
            });

            App.showToast(data.message || 'Сопоставление удалено', 'success');

            // Перезагружаем данные
            await this.loadMappings();
            await this.loadYmProducts();

        } catch (error) {
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
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
    async saveNewProduct() {
        const name = document.getElementById('newProductName')?.value?.trim();
        const costPrice = parseFloat(document.getElementById('newProductCostPrice')?.value) || 0;

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
                    sku: document.getElementById('newProductSku')?.value?.trim() || '',
                    cost_price: costPrice,
                    base_price: costPrice
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
     * Открытие модального окна редактирования сопоставления
     */
    openEditMappingModal(mappingId, qty, pieces) {
        document.getElementById('editMappingId').value = mappingId;
        document.getElementById('editQuantityInPack').value = qty || 1;
        document.getElementById('editPiecesPerSheet').value = pieces || 1;

        const modal = new bootstrap.Modal(document.getElementById('editMappingModal'));
        modal.show();
    },

    /**
     * Сохранение редактирования сопоставления
     */
    async saveMappingEdit() {
        const mappingId = document.getElementById('editMappingId').value;
        const quantityInPack = parseInt(document.getElementById('editQuantityInPack').value) || 1;
        const piecesPerSheet = parseInt(document.getElementById('editPiecesPerSheet').value) || 1;

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

            bootstrap.Modal.getInstance(document.getElementById('editMappingModal'))?.hide();
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
document.addEventListener('DOMContentLoaded', () => YMMapping.init());
