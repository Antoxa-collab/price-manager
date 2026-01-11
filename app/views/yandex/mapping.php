<?php
/**
 * Страница сопоставления товаров с Яндекс.Маркет
 * 3-шаговый интерфейс: Загрузка -> Сопоставление -> Просмотр
 */
$pageTitle = 'Сопоставление товаров Яндекс.Маркет';
$pageScript = 'ym-mapping';
include VIEWS_PATH . '/layout/header.php';
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-link-45deg me-2 text-warning"></i>
                Сопоставление товаров Яндекс.Маркет
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
                <div class="step-label">Загрузить с ЯМ</div>
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
            <i class="bi bi-cloud-download display-1 text-warning mb-3"></i>
            <h5 class="mb-3">Загрузите товары с Яндекс.Маркет</h5>
            <p class="text-muted mb-4">
                Нажмите кнопку ниже, чтобы синхронизировать список товаров из вашего магазина на Яндекс.Маркет
            </p>
            <button type="button" class="btn btn-warning btn-lg" id="syncYmBtn">
                <i class="bi bi-arrow-repeat me-2"></i>
                Загрузить товары с Яндекс.Маркет
            </button>

            <!-- Прогресс загрузки -->
            <div class="mt-4 d-none" id="syncProgress">
                <div class="spinner-border text-warning me-2" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <span class="text-muted" id="syncProgressText">Загрузка товаров с Яндекс.Маркет...</span>
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
            У вас уже загружено <span id="cachedProductsCount">0</span> товаров с Яндекс.Маркет
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
                    <div class="small text-truncate" id="selectedYmProduct">-</div>
                </div>

                <button type="button" class="btn btn-outline-secondary" id="goToStep3Btn">
                    <i class="bi bi-arrow-right me-1"></i>
                    К списку
                </button>
            </div>
        </div>

        <!-- Правая панель: Товары ЯМ -->
        <div class="col-lg-5 mb-4">
            <div class="card bg-dark border-secondary h-100">
                <div class="card-header border-secondary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-shop me-2 text-warning"></i>
                            Товары Яндекс.Маркет
                            <span class="badge bg-warning text-dark ms-2" id="ymProductsCount">0</span>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="hideLinkedYm">
                            <label class="form-check-label small" for="hideLinkedYm">Скрыть связанные</label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Поиск -->
                    <div class="p-3 border-bottom border-secondary">
                        <input type="text" class="form-control form-control-sm" id="searchYmProducts" placeholder="Поиск по названию, артикулу, баркоду...">
                    </div>

                    <!-- Панель массового выбора -->
                    <div class="bulk-select-panel p-2 border-bottom border-secondary bg-body-tertiary">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="ymSelectAll">
                                    <label class="form-check-label small" for="ymSelectAll">Выбрать все</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="ymSelectUnlinked">
                                    <label class="form-check-label small" for="ymSelectUnlinked">Только без связи</label>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-muted" id="ymSelectedCount">
                                    Выбрано: <strong class="text-success">0</strong>
                                </span>
                                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="ymClearSelection" title="Снять выбор">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Список товаров -->
                    <div class="list-group list-group-flush overflow-auto" id="ymProductsList" style="max-height: 400px;">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-hourglass-split"></i> Загрузка...
                        </div>
                    </div>
                </div>
                <div class="card-footer border-secondary small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Выберите товары для сопоставления (можно несколько)
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== ШАГ 3: Просмотр сопоставлений ===== -->
<div class="step-content d-none" id="step3Content">
    <div class="card bg-dark border-secondary">
        <div class="card-header border-secondary d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-table me-2"></i>
                Сопоставления
                <span class="badge bg-success ms-2" id="mappingsCount">0</span>
            </div>
            <div>
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="backToStep2Btn">
                    <i class="bi bi-arrow-left me-1"></i>
                    Добавить ещё
                </button>
                <button type="button" class="btn btn-warning btn-sm" id="refreshMappingsBtn">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Обновить
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-striped table-hover mb-0" id="mappingsTable">
                    <thead>
                        <tr>
                            <th>Наш товар</th>
                            <th>Товар ЯМ</th>
                            <th>OfferId</th>
                            <th>Упаковка</th>
                            <th>Раскрой</th>
                            <th class="text-end">Действия</th>
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
    </div>
</div>

<!-- Модальное окно добавления товара -->
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
                        <label for="newProductName" class="form-label">Название товара</label>
                        <input type="text" class="form-control" id="newProductName" required placeholder="Например: Фанера ФК 1/2 10мм">
                    </div>
                    <div class="mb-3">
                        <label for="newProductSku" class="form-label">Артикул (SKU)</label>
                        <input type="text" class="form-control" id="newProductSku" placeholder="Например: FAN-FK-12-10">
                    </div>
                    <div class="mb-3">
                        <label for="newProductCostPrice" class="form-label">Себестоимость</label>
                        <input type="number" step="0.01" class="form-control" id="newProductCostPrice" required placeholder="0.00">
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="saveNewProductBtn">
                    <i class="bi bi-check-lg me-1"></i>
                    Добавить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования сопоставления -->
<div class="modal fade" id="editMappingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Редактирование сопоставления
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editMappingForm">
                    <input type="hidden" id="editMappingId">
                    <div class="mb-3">
                        <label for="editQuantityInPack" class="form-label">Количество в упаковке</label>
                        <input type="number" class="form-control" id="editQuantityInPack" min="1" max="10000" value="1">
                        <div class="form-text">Сколько штук в одной упаковке товара на ЯМ</div>
                    </div>
                    <div class="mb-3">
                        <label for="editPiecesPerSheet" class="form-label">Из листа/упаковки</label>
                        <input type="number" class="form-control" id="editPiecesPerSheet" min="1" max="10000" value="1">
                        <div class="form-text">Сколько таких упаковок получается из одного листа/единицы товара</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="saveMappingBtn">
                    <i class="bi bi-check-lg me-1"></i>
                    Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
