<?php
/**
 * Страница управления товарами
 */
$pageTitle = 'Товары';
$pageScript = 'products';
include VIEWS_PATH . '/layout/header.php';

// Получаем товары из БД
$db = Database::getInstance();
$products = $db->fetchAll("
    SELECT p.*,
           (SELECT COUNT(*) FROM product_mappings pm WHERE pm.product_id = p.id AND pm.is_active = 1) as mappings_count
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.name
");
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-box-seam me-2 text-primary"></i>
                Товары
            </h4>
            <div>
                <a href="/ozon" class="btn btn-outline-info me-2">
                    <i class="bi bi-calculator me-1"></i> Калькулятор Ozon
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                    <i class="bi bi-plus-lg me-1"></i> Добавить товар
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Таблица товаров -->
<div class="card bg-dark border-secondary">
    <div class="card-header border-secondary">
        <i class="bi bi-list-ul me-2"></i>
        Список товаров
        <span class="badge bg-secondary ms-2"><?= count($products) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Артикул</th>
                        <th class="text-end" style="width: 150px;">Закупочная цена</th>
                        <th class="text-center" style="width: 100px;">Наценка мин.</th>
                        <th class="text-center" style="width: 100px;">Наценка доп.</th>
                        <th class="text-center" style="width: 100px;">Сопоставлений</th>
                        <th class="text-center" style="width: 150px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="productsTable">
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox display-4"></i>
                            <p class="mt-3">Нет товаров. Добавьте первый товар.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($products as $product): ?>
                    <tr data-id="<?= $product['id'] ?>">
                        <td>
                            <strong><?= e($product['name']) ?></strong>
                            <?php if (!empty($product['category'])): ?>
                            <br><small class="text-muted"><?= e($product['category']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($product['sku'] ?? '-') ?></code></td>
                        <td class="text-end">
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control form-control-sm cost-price-input"
                                       value="<?= $product['cost_price'] ?? 0 ?>"
                                       data-id="<?= $product['id'] ?>"
                                       step="0.01" min="0">
                                <span class="input-group-text">₽</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control form-control-sm markup-min-input"
                                       value="<?= $product['markup_min_price'] ?? 20 ?>"
                                       data-id="<?= $product['id'] ?>"
                                       step="0.1" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control form-control-sm markup-your-input"
                                       value="<?= $product['markup_your_price'] ?? 5 ?>"
                                       data-id="<?= $product['id'] ?>"
                                       step="0.1" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php if ($product['mappings_count'] > 0): ?>
                            <a href="/ozon/mapping" class="badge bg-success text-decoration-none">
                                <?= $product['mappings_count'] ?> связей
                            </a>
                            <?php else: ?>
                            <span class="badge bg-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-warning edit-product-btn"
                                    data-id="<?= $product['id'] ?>" title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success save-product-btn"
                                    data-id="<?= $product['id'] ?>" title="Сохранить">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <a href="/ozon" class="btn btn-sm btn-outline-info" title="Калькулятор">
                                <i class="bi bi-calculator"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-danger delete-product-btn"
                                    data-id="<?= $product['id'] ?>" title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модальное окно добавления товара -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Добавить товар
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newProductName" placeholder="Например: Фанера ФК 1520x1520 4мм">
                </div>
                <div class="mb-3">
                    <label class="form-label">Артикул (SKU)</label>
                    <input type="text" class="form-control" id="newProductSku" placeholder="Например: FAN-FK-1520-4">
                </div>
                <div class="mb-3">
                    <label class="form-label">Категория</label>
                    <input type="text" class="form-control" id="newProductCategory" placeholder="Например: Фанера">
                </div>
                <div class="mb-3">
                    <label class="form-label">Закупочная цена (₽) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="newProductCost" step="0.01" min="0" value="0">
                    <div class="form-text">Цена закупки за 1 единицу (лист, штуку)</div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label">Наценка мин. (%)</label>
                        <input type="number" class="form-control" id="newProductMarkupMin" value="20" step="0.1" min="0">
                        <div class="form-text">Для минимальной цены</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Наценка доп. (%)</label>
                        <input type="number" class="form-control" id="newProductMarkupYour" value="5" step="0.1" min="0">
                        <div class="form-text">Поверх минимальной</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="saveNewProductBtn">
                    <i class="bi bi-check-lg me-1"></i> Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования товара -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    Редактировать товар
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editProductId">
                <div class="mb-3">
                    <label class="form-label">Название <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editProductName" placeholder="Например: Фанера ФК 1520x1520 4мм">
                </div>
                <div class="mb-3">
                    <label class="form-label">Артикул (SKU)</label>
                    <input type="text" class="form-control" id="editProductSku" placeholder="Например: FAN-FK-1520-4">
                </div>
                <div class="mb-3">
                    <label class="form-label">Категория</label>
                    <input type="text" class="form-control" id="editProductCategory" placeholder="Например: Фанера">
                </div>
                <div class="mb-3">
                    <label class="form-label">Закупочная цена (₽) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="editProductCost" step="0.01" min="0" value="0">
                    <div class="form-text">Цена закупки за 1 единицу (лист, штуку)</div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label">Наценка мин. (%)</label>
                        <input type="number" class="form-control" id="editProductMarkupMin" value="20" step="0.1" min="0">
                        <div class="form-text">Для минимальной цены</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Наценка доп. (%)</label>
                        <input type="number" class="form-control" id="editProductMarkupYour" value="5" step="0.1" min="0">
                        <div class="form-text">Поверх минимальной</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-warning" id="updateProductBtn">
                    <i class="bi bi-check-lg me-1"></i> Сохранить изменения
                </button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
