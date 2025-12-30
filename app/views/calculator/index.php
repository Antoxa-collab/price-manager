<?php
$pageTitle = 'Калькулятор цен';
$pageScript = 'calculator';
include VIEWS_PATH . '/layout/header.php';
?>

<div class="row">
    <div class="col-12">
        <h4 class="mb-4">
            <i class="bi bi-calculator me-2"></i>
            Калькулятор цен для маркетплейсов
        </h4>
    </div>
</div>

<div class="row">
    <!-- Форма ввода данных -->
    <div class="col-lg-5 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-pencil-square me-2"></i>
                Параметры товара
            </div>
            <div class="card-body">
                <form id="calculatorForm">
                    <?= csrfField() ?>

                    <!-- Материал -->
                    <div class="mb-3">
                        <label for="material" class="form-label">Материал</label>
                        <select class="form-select" id="material" name="material" required>
                            <option value="">Выберите материал</option>
                            <?php foreach (Calculator::getMaterialsList() as $material): ?>
                            <option value="<?= e($material['id']) ?>" data-name="<?= e($material['name']) ?>">
                                <?= e($material['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Сорт -->
                    <div class="mb-3">
                        <label for="grade" class="form-label">Сорт</label>
                        <select class="form-select" id="grade" name="grade" required>
                            <option value="">Выберите сорт</option>
                            <?php foreach (Calculator::getGradesList() as $grade): ?>
                            <option value="<?= e($grade) ?>"><?= e($grade) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Толщина -->
                    <div class="mb-3">
                        <label for="thickness" class="form-label">Толщина (мм)</label>
                        <select class="form-select" id="thickness" name="thickness" required>
                            <option value="">Выберите толщину</option>
                            <?php foreach (Calculator::getThicknessList() as $thickness): ?>
                            <option value="<?= e($thickness) ?>"><?= e($thickness) ?> мм</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Цена за лист -->
                    <div class="mb-3">
                        <label for="basePrice" class="form-label">Цена за лист (закупочная)</label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control"
                                   id="basePrice"
                                   name="base_price"
                                   min="0"
                                   step="0.01"
                                   placeholder="0.00"
                                   required>
                            <span class="input-group-text">руб.</span>
                        </div>
                    </div>

                    <hr class="border-secondary">

                    <!-- Наценки -->
                    <h6 class="mb-3">Наценки</h6>

                    <div class="mb-3">
                        <label for="markupRetail" class="form-label">
                            Мелкий опт (от 1 шт)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control"
                                   id="markupRetail"
                                   name="markup_retail"
                                   min="0"
                                   max="1000"
                                   step="0.1"
                                   value="30"
                                   required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="markupMedium" class="form-label">
                            Средний опт (от 10 шт)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control"
                                   id="markupMedium"
                                   name="markup_medium"
                                   min="0"
                                   max="1000"
                                   step="0.1"
                                   value="20"
                                   required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="markupWholesale" class="form-label">
                            Крупный опт (от 50 шт)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control"
                                   id="markupWholesale"
                                   name="markup_wholesale"
                                   min="0"
                                   max="1000"
                                   step="0.1"
                                   value="10"
                                   required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <hr class="border-secondary">

                    <!-- Остатки и артикулы -->
                    <div class="mb-3">
                        <label for="stock" class="form-label">Остатки на складе</label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control"
                                   id="stock"
                                   name="stock"
                                   min="0"
                                   value="0">
                            <span class="input-group-text">шт.</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="sellerArticle" class="form-label">Артикул продавца</label>
                        <input type="text"
                               class="form-control"
                               id="sellerArticle"
                               name="seller_article"
                               placeholder="Ваш внутренний артикул">
                    </div>

                    <div class="mb-3">
                        <label for="wbArticle" class="form-label">Артикул WB (nmID)</label>
                        <input type="text"
                               class="form-control"
                               id="wbArticle"
                               name="wb_article"
                               placeholder="ID номенклатуры Wildberries">
                    </div>

                    <div class="mb-3">
                        <label for="ozonArticle" class="form-label">Артикул Ozon (product_id)</label>
                        <input type="text"
                               class="form-control"
                               id="ozonArticle"
                               name="ozon_article"
                               placeholder="ID товара Ozon">
                    </div>

                    <!-- Кнопка расчёта -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg" id="calculateBtn">
                            <i class="bi bi-calculator me-2"></i>
                            Рассчитать
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Результаты расчёта -->
    <div class="col-lg-7 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-table me-2"></i>
                Результаты расчёта
            </div>
            <div class="card-body">
                <!-- Таблица результатов -->
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover" id="resultsTable">
                        <thead>
                            <tr>
                                <th>Тип цены</th>
                                <th class="text-center">Наценка</th>
                                <th class="text-end">До округления</th>
                                <th class="text-end">После округления</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="rowRetail">
                                <td>Мелкий опт (от 1 шт)</td>
                                <td class="text-center" id="retailMarkup">-</td>
                                <td class="text-end" id="retailRaw">-</td>
                                <td class="text-end fw-bold" id="retailRounded">-</td>
                            </tr>
                            <tr id="rowMedium">
                                <td>Средний опт (от 10 шт)</td>
                                <td class="text-center" id="mediumMarkup">-</td>
                                <td class="text-end" id="mediumRaw">-</td>
                                <td class="text-end fw-bold" id="mediumRounded">-</td>
                            </tr>
                            <tr id="rowWholesale">
                                <td>Крупный опт (от 50 шт)</td>
                                <td class="text-center" id="wholesaleMarkup">-</td>
                                <td class="text-end" id="wholesaleRaw">-</td>
                                <td class="text-end fw-bold" id="wholesaleRounded">-</td>
                            </tr>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="2"><strong>Цена для Wildberries:</strong></td>
                                <td colspan="2" class="text-end fs-5 text-primary" id="wbPrice">-</td>
                            </tr>
                            <tr>
                                <td colspan="2"><strong>Цена для Ozon:</strong></td>
                                <td colspan="2" class="text-end fs-5 text-info" id="ozonPrice">-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Кнопки действий -->
                <div class="row g-2 mt-4" id="actionButtons" style="display: none;">
                    <div class="col-md-6 col-lg-4">
                        <button type="button" class="btn btn-success w-100" id="saveProductBtn">
                            <i class="bi bi-save me-1"></i> Сохранить товар
                        </button>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <button type="button" class="btn btn-primary w-100" id="uploadWbBtn">
                            <i class="bi bi-cloud-upload me-1"></i> Загрузить на WB
                        </button>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <button type="button" class="btn btn-info w-100" id="uploadOzonBtn">
                            <i class="bi bi-cloud-upload me-1"></i> Загрузить на Ozon
                        </button>
                    </div>
                    <div class="col-md-6 col-lg-6">
                        <button type="button" class="btn btn-warning w-100" id="zeroWbBtn">
                            <i class="bi bi-dash-circle me-1"></i> Обнулить остатки WB
                        </button>
                    </div>
                    <div class="col-md-6 col-lg-6">
                        <button type="button" class="btn btn-warning w-100" id="zeroOzonBtn">
                            <i class="bi bi-dash-circle me-1"></i> Обнулить остатки Ozon
                        </button>
                    </div>
                </div>

                <!-- Сообщение о пустом расчёте -->
                <div id="emptyMessage" class="text-center text-muted py-5">
                    <i class="bi bi-calculator display-4"></i>
                    <p class="mt-3">Заполните форму и нажмите "Рассчитать"<br>для получения цен</p>
                </div>
            </div>
        </div>

        <!-- Информация о округлении -->
        <div class="card bg-dark border-secondary mt-4">
            <div class="card-header border-secondary">
                <i class="bi bi-info-circle me-2"></i>
                Правила округления цен
            </div>
            <div class="card-body">
                <div class="row small">
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><span class="badge bg-secondary">До 100 руб.</span> округление до 9 (89, 99)</li>
                            <li><span class="badge bg-secondary">100-500 руб.</span> до 49, 99 (149, 199, 249...)</li>
                            <li><span class="badge bg-secondary">500-1000 руб.</span> до 99 (599, 699, 799...)</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><span class="badge bg-secondary">1000-5000 руб.</span> до 99, 499, 999</li>
                            <li><span class="badge bg-secondary">5000-10000 руб.</span> до 999 (5999, 6999...)</li>
                            <li><span class="badge bg-secondary">Более 10000 руб.</span> до 999 (10999, 11999...)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
