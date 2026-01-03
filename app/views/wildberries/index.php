<?php
/**
 * Калькулятор цен Wildberries
 * Расчёт и загрузка цен на маркетплейс
 */
$pageTitle = 'Калькулятор цен Wildberries';
$pageScript = 'wb-calculator';
include VIEWS_PATH . '/layout/header.php';
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-calculator me-2 text-danger"></i>
                <span class="hide-mobile">Калькулятор цен Wildberries</span>
                <span class="show-mobile d-none">Калькулятор WB</span>
            </h4>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" id="syncWbBtn">
                    <i class="bi bi-arrow-repeat"></i><span class="hide-mobile ms-1">Синхронизация</span>
                </button>
                <a href="/wildberries/mapping" class="btn btn-outline-secondary">
                    <i class="bi bi-link-45deg"></i><span class="hide-mobile ms-1">Сопоставления</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Статистика синхронизации -->
<div class="row mb-4 d-none" id="syncStatsRow">
    <div class="col-12">
        <div class="alert alert-info mb-0">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-info-circle me-2"></i>
                    <span id="syncStatsText">Товаров в кэше: 0 | Сопоставлено: 0</span>
                </div>
                <small class="text-muted" id="lastSyncTime"></small>
            </div>
        </div>
    </div>
</div>

<!-- Блок выбора товара и настройки наценок -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary">
        <i class="bi bi-box-seam me-2"></i>
        Выбор товара и расчёт цен
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <!-- Выбор товара -->
            <div class="col-md-4">
                <label for="productSelect" class="form-label">
                    Товар <span class="text-danger">*</span>
                </label>
                <select class="form-select" id="productSelect">
                    <option value="">Выберите товар...</option>
                </select>
                <div class="form-text" id="productInfo">
                    Выберите товар с привязанными артикулами WB
                </div>
            </div>

            <!-- Наценка для минимальной цены -->
            <div class="col-md-2">
                <label for="markupMin" class="form-label">
                    Наценка мин. (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Наценка для минимальной цены"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="markupMin" min="0" max="1000" step="0.1" value="20">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Скидка WB -->
            <div class="col-md-2">
                <label for="wbDiscount" class="form-label">
                    Скидка WB (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Скидка, которая будет отображаться на WB"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="wbDiscount" min="0" max="95" step="1" value="0">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Кнопки управления -->
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-primary" id="recalculateBtn" disabled title="Пересчитать цены">
                        <i class="bi bi-calculator me-1"></i> Пересчитать
                    </button>
                    <button type="button" class="btn btn-outline-info" id="autoFillBtn" disabled title="Автозаполнение из артикулов">
                        <i class="bi bi-magic me-1"></i> Авто
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="saveMarkupsBtn" disabled title="Сохранить настройки">
                        <i class="bi bi-save me-1"></i> Сохранить
                    </button>
                </div>
            </div>
        </div>

        <!-- Блок с расчётными ценами -->
        <div class="row mt-4 g-3 d-none" id="calculatedPricesBlock">
            <div class="col-md-3">
                <div class="metric-card metric-cost">
                    <div class="metric-value" id="calcCostPrice">-</div>
                    <div class="metric-label">Себестоимость</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card metric-purchase">
                    <div class="metric-value" id="calcBasePrice">-</div>
                    <div class="metric-label">Закупочная (1 шт)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card metric-min">
                    <div class="metric-value" id="calcMinPrice">-</div>
                    <div class="metric-label">Цена до скидки</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card metric-your">
                    <div class="metric-value" id="calcFinalPrice">-</div>
                    <div class="metric-label">Цена после скидки</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Управление остатками -->
<div class="card bg-dark border-secondary mb-4 d-none" id="stockManagementCard">
    <div class="card-header border-secondary">
        <i class="bi bi-box-seam me-2"></i>Управление остатками
    </div>
    <div class="card-body">
        <div class="row align-items-end g-3">
            <div class="col-auto">
                <label class="form-label">Склад WB</label>
                <select class="form-select" id="warehouseSelect" style="width: 200px">
                    <option value="">Загрузка...</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">Остатки</label>
                <input type="number" class="form-control" id="bulkStock"
                       value="0" min="0" style="width: 120px">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-secondary" id="applyBulkStockBtn">
                    <i class="bi bi-arrow-down-circle me-1"></i>Применить к выбранным
                </button>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-info" id="applyAllStockBtn">
                    <i class="bi bi-arrow-repeat me-1"></i>Применить ко всем
                </button>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-danger" id="zeroStockBtn">
                    <i class="bi bi-x-circle me-1"></i>Обнулить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Таблица привязанных артикулов WB -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-list-ul me-2"></i>
            Привязанные артикулы Wildberries
            <span class="badge bg-secondary ms-2" id="articlesCount">0</span>
        </div>
        <div class="d-none" id="tableActions">
            <span class="text-muted me-3">
                <i class="bi bi-check-square me-1"></i>
                Выбрано: <span id="selectedCount">0</span>
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover mb-0" id="articlesTable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllCheckbox" title="Выбрать все">
                        </th>
                        <th>Артикул WB</th>
                        <th>Название</th>
                        <th class="text-center" style="width: 100px;">Из листа/Упак.</th>
                        <th class="text-end" style="width: 130px;">
                            <span class="text-warning">Цена</span>
                        </th>
                        <th class="text-end" style="width: 80px;">
                            <span class="text-info">Скидка</span>
                        </th>
                        <th class="text-end" style="width: 130px;">На WB</th>
                        <th class="text-center" style="width: 100px;">Остатки</th>
                        <th class="text-center" style="width: 80px;">Статус</th>
                        <th class="text-center" style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody id="articlesTableBody">
                    <tr>
                        <td colspan="10" class="text-center text-muted py-5">
                            <i class="bi bi-inbox display-4"></i>
                            <p class="mt-3">Выберите товар для просмотра привязанных артикулов</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Кнопки загрузки цен -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2 flex-wrap w-100 w-md-auto">
                <button type="button" class="btn btn-danger flex-grow-1 flex-md-grow-0" id="uploadSelectedBtn" disabled>
                    <i class="bi bi-cloud-upload me-1"></i>
                    <span class="hide-mobile">Загрузить выбранные на WB</span>
                    <span class="show-mobile d-none">Выбранные</span>
                </button>
                <button type="button" class="btn btn-outline-danger flex-grow-1 flex-md-grow-0" id="uploadAllBtn" disabled>
                    <i class="bi bi-cloud-upload-fill me-1"></i>
                    <span class="hide-mobile">Загрузить все артикулы товара</span>
                    <span class="show-mobile d-none">Все</span>
                </button>
                <button type="button" class="btn btn-outline-info flex-grow-1 flex-md-grow-0" id="uploadStocksOnlyBtn" disabled>
                    <i class="bi bi-box-seam me-1"></i>
                    <span class="hide-mobile">Загрузить только остатки</span>
                    <span class="show-mobile d-none">Остатки</span>
                </button>
            </div>
            <div class="text-muted small hide-mobile">
                <i class="bi bi-info-circle me-1"></i>
                Цены загружаются с указанной скидкой
            </div>
        </div>
    </div>
</div>

<!-- Результаты загрузки -->
<div class="card bg-dark border-secondary d-none" id="uploadResultsCard">
    <div class="card-header border-secondary">
        <i class="bi bi-journal-check me-2"></i>
        Результаты загрузки
    </div>
    <div class="card-body">
        <div class="row g-3" id="uploadResultsContent">
        </div>
    </div>
</div>

<!-- Модальное окно редактирования параметров упаковки -->
<div class="modal fade" id="editPackModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Параметры упаковки</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPackMappingId">
                <input type="hidden" id="editPackNmId">

                <div class="row">
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">Из 1 листа (шт)</label>
                            <input type="number" class="form-control" id="editPiecesPerSheet" min="1" value="1">
                            <div class="form-text">Сколько единиц получается из 1 закупочного листа</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">В упаковке (шт)</label>
                            <input type="number" class="form-control" id="editQuantityInPack" min="1" value="1">
                            <div class="form-text">Сколько штук в карточке на WB</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mb-0" id="packPreview">
                    <div class="text-muted small mb-1">Формула: (Закупка / Из листа) × В упаковке</div>
                    <div class="fw-bold">Себестоимость: -</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="savePackBtn">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно синхронизации -->
<div class="modal fade" id="syncModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Синхронизация с Wildberries</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div id="syncModalLoading">
                    <div class="spinner-border text-danger mb-3" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <p class="mb-0">Синхронизация товаров и цен...</p>
                </div>
                <div id="syncModalResult" class="d-none">
                    <i class="bi bi-check-circle-fill text-success display-4"></i>
                    <p class="mt-3 mb-0" id="syncResultText"></p>
                </div>
                <div id="syncModalError" class="d-none">
                    <i class="bi bi-x-circle-fill text-danger display-4"></i>
                    <p class="mt-3 mb-0 text-danger" id="syncErrorText"></p>
                </div>
            </div>
            <div class="modal-footer border-secondary d-none" id="syncModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
