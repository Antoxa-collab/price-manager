<?php
/**
 * Страница сопоставления товаров с Wildberries
 * 3-шаговый интерфейс: Загрузка -> Сопоставление -> Просмотр
 */
$pageTitle = 'Сопоставление товаров Wildberries';
$pageScript = 'wb-mapping';
include VIEWS_PATH . '/layout/header.php';
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-link-45deg me-2 text-danger"></i>
                Сопоставление товаров Wildberries
            </h4>
            <div>
                <span class="text-muted me-3" id="lastSyncTime">
                    <i class="bi bi-clock me-1"></i>
                    Последняя синхронизация: <span id="syncTimeValue">-</span>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Шаги процесса -->
<div class="row mb-4">
    <div class="col-12">
        <div class="steps-container d-flex justify-content-center align-items-center">
            <!-- Шаг 1: Загрузка -->
            <div class="step active" data-step="1" id="step1Indicator">
                <div class="step-circle">
                    <span class="step-number">1</span>
                    <i class="bi bi-check-lg step-check d-none"></i>
                </div>
                <div class="step-label">Загрузить с WB</div>
            </div>

            <div class="step-line"></div>

            <!-- Шаг 2: Сопоставление -->
            <div class="step" data-step="2" id="step2Indicator">
                <div class="step-circle">
                    <span class="step-number">2</span>
                    <i class="bi bi-check-lg step-check d-none"></i>
                </div>
                <div class="step-label">Сопоставить товары</div>
            </div>

            <div class="step-line"></div>

            <!-- Шаг 3: Просмотр -->
            <div class="step" data-step="3" id="step3Indicator">
                <div class="step-circle">
                    <span class="step-number">3</span>
                    <i class="bi bi-check-lg step-check d-none"></i>
                </div>
                <div class="step-label">Просмотр сопоставлений</div>
            </div>
        </div>
    </div>
</div>

<!-- ===== ШАГ 1: Кнопка загрузки ===== -->
<div class="step-content" id="step1Content">
    <div class="card bg-dark border-secondary">
        <div class="card-body text-center py-5">
            <i class="bi bi-cloud-download display-1 text-danger mb-3"></i>
            <h5 class="mb-3">Загрузите товары с Wildberries</h5>
            <p class="text-muted mb-4">
                Нажмите кнопку ниже, чтобы синхронизировать список товаров из вашего магазина на Wildberries
            </p>
            <button type="button" class="btn btn-danger btn-lg" id="syncWbBtn">
                <i class="bi bi-arrow-repeat me-2"></i>
                Загрузить товары с Wildberries
            </button>

            <!-- Прогресс загрузки -->
            <div class="mt-4 d-none" id="syncProgress">
                <div class="spinner-border text-danger me-2" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <span class="text-muted" id="syncProgressText">Загрузка товаров с Wildberries...</span>
            </div>
        </div>
    </div>

    <!-- Если уже есть товары, показываем кнопку пропуска -->
    <div class="text-center mt-3 d-none" id="skipStep1">
        <button type="button" class="btn btn-outline-secondary" id="skipToStep2Btn">
            <i class="bi bi-arrow-right me-1"></i>
            Пропустить и перейти к сопоставлению
        </button>
        <div class="text-muted small mt-2">
            У вас уже загружено <span id="cachedProductsCount">0</span> товаров с Wildberries
        </div>
    </div>
</div>

<!-- ===== ШАГ 2: Сопоставление товаров ===== -->
<div class="step-content d-none" id="step2Content">
    <div class="row">
        <!-- Левая панель: Наши товары -->
        <div class="col-lg-5 mb-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-box-seam me-2"></i>
                        Наши товары
                        <span class="badge bg-secondary ms-2" id="ourProductsCount">0</span>
                    </div>
                    <button type="button" class="btn btn-outline-success btn-sm" id="addProductBtn" title="Добавить товар">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <!-- Поиск -->
                    <div class="p-3 border-bottom border-secondary">
                        <input type="text" class="form-control form-control-sm" id="searchOurProducts" placeholder="Поиск по названию, артикулу...">
                    </div>

                    <!-- Список товаров -->
                    <div class="list-group list-group-flush overflow-auto" id="ourProductsList" style="max-height: 450px;">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-hourglass-split"></i> Загрузка...
                        </div>
                    </div>
                </div>
                <div class="card-footer border-secondary small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Выберите товар для сопоставления
                </div>
            </div>
        </div>

        <!-- Центр: Кнопки действий -->
        <div class="col-lg-2 d-flex align-items-center justify-content-center mb-4">
            <div class="text-center mapping-actions">
                <button type="button" class="btn btn-success btn-lg mb-3" id="createMappingBtn" disabled title="Сопоставить">
                    <i class="bi bi-link-45deg"></i>
                </button>
                <div class="small text-muted mb-4">Сопоставить</div>

                <div class="selected-info mb-4 p-2 rounded bg-body-tertiary d-none" id="selectedInfo">
                    <div class="small text-muted mb-1">Выбрано:</div>
                    <div class="small text-truncate" id="selectedOurProduct">-</div>
                    <i class="bi bi-arrow-down my-1"></i>
                    <div class="small text-truncate" id="selectedWbProduct">-</div>
                </div>
            </div>
        </div>

        <!-- Правая панель: Товары WB -->
        <div class="col-lg-5 mb-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-cloud me-2 text-danger"></i>
                        Товары на Wildberries
                        <span class="badge bg-danger ms-2" id="wbProductsCount">0</span>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="wbFilter" id="filterAll" value="all" checked>
                        <label class="btn btn-outline-secondary" for="filterAll">Все</label>

                        <input type="radio" class="btn-check" name="wbFilter" id="filterUnmapped" value="unmapped">
                        <label class="btn btn-outline-secondary" for="filterUnmapped">Без связи</label>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Поиск -->
                    <div class="p-3 border-bottom border-secondary">
                        <input type="text" class="form-control form-control-sm" id="searchWbProducts" placeholder="Поиск по названию, артикулу...">
                    </div>

                    <!-- Список товаров -->
                    <div class="list-group list-group-flush overflow-auto" id="wbProductsList" style="max-height: 450px;">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-hourglass-split"></i> Загрузка...
                        </div>
                    </div>
                </div>
                <div class="card-footer border-secondary small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Выберите карточку Wildberries
                </div>
            </div>
        </div>
    </div>

    <!-- Навигация между шагами -->
    <div class="d-flex justify-content-between mt-3">
        <button type="button" class="btn btn-outline-secondary" id="backToStep1Btn">
            <i class="bi bi-arrow-left me-1"></i> Назад
        </button>
        <button type="button" class="btn btn-primary" id="goToStep3Btn">
            Просмотр сопоставлений <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</div>

<!-- ===== ШАГ 3: Просмотр сопоставлений ===== -->
<div class="step-content d-none" id="step3Content">
    <!-- Статистика -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Наших товаров</div>
                            <h4 class="mb-0" id="statOurProducts">-</h4>
                        </div>
                        <i class="bi bi-box-seam display-6 text-secondary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Товаров на WB</div>
                            <h4 class="mb-0" id="statWbProducts">-</h4>
                        </div>
                        <i class="bi bi-cloud display-6 text-danger"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Сопоставлено</div>
                            <h4 class="mb-0">
                                <span id="statMapped">-</span>
                                <small class="text-muted fs-6" id="statMappedPercent"></small>
                            </h4>
                        </div>
                        <i class="bi bi-link-45deg display-6 text-success"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Таблица сопоставлений -->
    <div class="card bg-dark border-secondary">
        <div class="card-header border-secondary d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-list-check me-2"></i>
                Текущие сопоставления
            </div>
            <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" id="searchMappings" placeholder="Поиск..." style="width: 200px;">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-striped table-hover mb-0" id="mappingsTable">
                    <thead>
                        <tr>
                            <th>Наш товар</th>
                            <th>Артикул WB</th>
                            <th>Название на WB</th>
                            <th class="text-center" style="width: 120px;">В упаковке</th>
                            <th class="text-end" style="width: 120px;">Цена WB</th>
                            <th class="text-center" style="width: 100px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="mappingsTableBody">
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-hourglass-split"></i> Загрузка...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer border-secondary text-muted small">
            Всего сопоставлений: <span id="mappingsTotal">0</span>
        </div>
    </div>

    <!-- Навигация -->
    <div class="d-flex justify-content-between mt-3">
        <button type="button" class="btn btn-outline-secondary" id="backToStep2Btn">
            <i class="bi bi-arrow-left me-1"></i> Назад к сопоставлению
        </button>
        <a href="/wildberries" class="btn btn-primary">
            <i class="bi bi-calculator me-1"></i> Перейти к калькулятору
        </a>
    </div>
</div>

<!-- ===== Модальное окно: Добавить товар ===== -->
<div class="modal fade" id="addProductModal" tabindex="-1">
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
                <form id="addProductForm">
                    <div class="mb-3">
                        <label for="productName" class="form-label">Название товара <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="productName" required placeholder="Например: Фанера ФК 1/2 10мм">
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="productSku" class="form-label">Артикул (SKU)</label>
                            <input type="text" class="form-control" id="productSku" placeholder="Например: FK-12-10">
                        </div>
                        <div class="col-md-6">
                            <label for="productCategory" class="form-label">Категория</label>
                            <input type="text" class="form-control" id="productCategory" placeholder="Например: Фанера" list="categoryList">
                            <datalist id="categoryList">
                                <option value="Фанера">
                                <option value="OSB">
                                <option value="МДФ">
                                <option value="ДСП">
                                <option value="Брус">
                            </datalist>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="productMaterial" class="form-label">Материал</label>
                            <input type="text" class="form-control" id="productMaterial" placeholder="ФК, ФСФ..." list="materialList">
                            <datalist id="materialList">
                                <option value="ФК">
                                <option value="ФСФ">
                                <option value="ФОФ">
                                <option value="OSB-3">
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label for="productGrade" class="form-label">Сорт</label>
                            <input type="text" class="form-control" id="productGrade" placeholder="1/2, 2/4..." list="gradeList">
                            <datalist id="gradeList">
                                <option value="1/2">
                                <option value="2/2">
                                <option value="2/4">
                                <option value="3/4">
                                <option value="4/4">
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label for="productThickness" class="form-label">Толщина (мм)</label>
                            <input type="number" class="form-control" id="productThickness" step="0.5" min="0" placeholder="10">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="productCostPrice" class="form-label">Себестоимость (руб.) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="productCostPrice" step="0.01" min="0" required placeholder="1500.00">
                        </div>
                        <div class="col-md-6">
                            <label for="productBasePrice" class="form-label">Закупочная цена (руб.)</label>
                            <input type="number" class="form-control" id="productBasePrice" step="0.01" min="0" placeholder="1200.00">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="saveProductBtn">
                    <i class="bi bi-check-lg me-1"></i> Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Модальное окно: Редактирование количества в упаковке ===== -->
<div class="modal fade" id="editQuantityModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Количество в упаковке</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editQtyMappingId">
                <div class="mb-3">
                    <label class="form-label">Количество единиц</label>
                    <input type="number" class="form-control" id="editQtyValue" min="1" value="1">
                    <div class="form-text">Сколько листов/штук в одной карточке на WB</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="saveQtyBtn">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
