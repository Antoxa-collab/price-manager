/**
 * Products Page - управление товарами
 */

const ProductsPage = {
    /**
     * Инициализация страницы
     */
    init() {
        this.bindEvents();
    },

    /**
     * Привязка событий
     */
    bindEvents() {
        // Сохранение нового товара
        const saveNewBtn = document.getElementById('saveNewProductBtn');
        if (saveNewBtn) {
            saveNewBtn.addEventListener('click', () => this.createProduct());
        }

        // Сохранение отдельного товара (кнопка в строке)
        document.querySelectorAll('.save-product-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = e.currentTarget.dataset.id;
                this.saveProduct(id);
            });
        });

        // Удаление товара
        document.querySelectorAll('.delete-product-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = e.currentTarget.dataset.id;
                const confirmed = await App.confirm('Удалить этот товар?', 'Подтверждение удаления');
                if (confirmed) {
                    this.deleteProduct(id);
                }
            });
        });

        // Enter в модальном окне
        const modal = document.getElementById('productModal');
        if (modal) {
            modal.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.createProduct();
                }
            });

            // Фокус на поле ввода при открытии модалки
            modal.addEventListener('shown.bs.modal', () => {
                document.getElementById('newProductName').focus();
            });
        }

        // Автосохранение при изменении полей (с debounce)
        const debouncedSave = App.debounce((id) => {
            this.saveProduct(id, true); // тихое сохранение
        }, 1500);

        document.querySelectorAll('.cost-price-input, .markup-min-input, .markup-your-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const id = e.target.dataset.id;
                debouncedSave(id);
            });
        });
    },

    /**
     * Создание нового товара
     */
    async createProduct() {
        const name = document.getElementById('newProductName').value.trim();
        const sku = document.getElementById('newProductSku').value.trim();
        const category = document.getElementById('newProductCategory').value.trim();
        const costPrice = parseFloat(document.getElementById('newProductCost').value) || 0;
        const markupMin = parseFloat(document.getElementById('newProductMarkupMin').value) || 20;
        const markupYour = parseFloat(document.getElementById('newProductMarkupYour').value) || 5;

        if (!name) {
            App.showToast('Введите название товара', 'warning');
            document.getElementById('newProductName').focus();
            return;
        }

        if (costPrice <= 0) {
            App.showToast('Укажите закупочную цену', 'warning');
            document.getElementById('newProductCost').focus();
            return;
        }

        try {
            const data = await App.fetch('/api/products/create', {
                method: 'POST',
                body: {
                    name: name,
                    sku: sku,
                    category: category,
                    cost_price: costPrice,
                    markup_min_price: markupMin,
                    markup_your_price: markupYour
                }
            });

            if (data.success) {
                App.showToast('Товар успешно создан', 'success');
                // Перезагружаем страницу чтобы показать новый товар
                window.location.reload();
            } else {
                App.showToast(data.message || 'Ошибка создания товара', 'danger');
            }
        } catch (error) {
            console.error('Create product error:', error);
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Сохранение товара
     * @param {number} id - ID товара
     * @param {boolean} silent - Не показывать уведомление
     */
    async saveProduct(id, silent = false) {
        const row = document.querySelector(`tr[data-id="${id}"]`);
        if (!row) return;

        const costPrice = parseFloat(row.querySelector('.cost-price-input').value) || 0;
        const markupMin = parseFloat(row.querySelector('.markup-min-input').value) || 20;
        const markupYour = parseFloat(row.querySelector('.markup-your-input').value) || 5;

        try {
            const data = await App.fetch('/api/products/save', {
                method: 'POST',
                body: {
                    id: id,
                    cost_price: costPrice,
                    markup_min_price: markupMin,
                    markup_your_price: markupYour
                }
            });

            if (data.success) {
                if (!silent) {
                    App.showToast('Товар сохранён', 'success');
                }
                // Подсветить строку на секунду
                row.style.transition = 'background-color 0.3s';
                row.style.backgroundColor = 'rgba(25, 135, 84, 0.3)';
                setTimeout(() => {
                    row.style.backgroundColor = '';
                }, 500);
            } else {
                App.showToast(data.message || 'Ошибка сохранения', 'danger');
            }
        } catch (error) {
            console.error('Save product error:', error);
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Удаление товара
     * @param {number} id - ID товара
     */
    async deleteProduct(id) {
        try {
            const data = await App.fetch('/api/products/delete', {
                method: 'POST',
                body: { id: id }
            });

            if (data.success) {
                App.showToast('Товар удалён', 'success');
                // Удаляем строку из таблицы
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
                // Обновляем счётчик
                this.updateCounter();
            } else {
                App.showToast(data.message || 'Ошибка удаления', 'danger');
            }
        } catch (error) {
            console.error('Delete product error:', error);
            App.showToast('Ошибка: ' + error.message, 'danger');
        }
    },

    /**
     * Обновление счётчика товаров
     */
    updateCounter() {
        const count = document.querySelectorAll('#productsTable tr[data-id]').length;
        const badge = document.querySelector('.card-header .badge');
        if (badge) {
            badge.textContent = count;
        }
    }
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => ProductsPage.init());
