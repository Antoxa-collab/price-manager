<?php
/**
 * Калькулятор цен Ozon
 * Расчёт и загрузка цен на маркетплейс
 */
$pageTitle = 'Калькулятор цен Ozon';
$pageScript = 'ozon-calculator';
include VIEWS_PATH . '/layout/header.php';
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="bi bi-calculator me-2 text-info"></i>
                Калькулятор цен Ozon
            </h4>
            <a href="/ozon/mapping" class="btn btn-outline-secondary">
                <i class="bi bi-link-45deg me-1"></i> Сопоставления
            </a>
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
                    Выберите товар с привязанными артикулами Ozon
                </div>
            </div>

            <!-- Наценка для минимальной цены -->
            <div class="col-md-2">
                <label for="markupMin" class="form-label">
                    Наценка мин. (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Наценка для минимальной цены, ниже которой товар не продаётся"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="markupMin" min="0" max="1000" step="0.1" value="20">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Дополнительная наценка для вашей цены -->
            <div class="col-md-2">
                <label for="markupYour" class="form-label">
                    Доп. наценка (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Дополнительная наценка поверх минимальной для 'Вашей цены'"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="markupYour" min="0" max="1000" step="0.1" value="5">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Кнопки управления -->
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-primary" id="recalculateBtn" disabled title="Пересчитать цены">
                        <i class="bi bi-calculator me-1"></i> Пересчитать
                    </button>
                    <button type="button" class="btn btn-outline-info" id="autoFillBtn" disabled title="Автоматически определить кусочков из листа и количество из названий артикулов Ozon">
                        <i class="bi bi-magic me-1"></i> Автозаполнить
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="saveMarkupsBtn" disabled title="Сохранить наценки">
                        <i class="bi bi-save me-1"></i> Сохранить
                    </button>
                </div>
            </div>
        </div>

        <!-- Блок с расчётными ценами - современный дизайн -->
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
                    <div class="metric-label">Минимальная цена</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card metric-your">
                    <div class="metric-value" id="calcYourPrice">-</div>
                    <div class="metric-label">Ваша цена</div>
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
                <label class="form-label">Остатки для выбранных</label>
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
                    <i class="bi bi-x-circle me-1"></i>Обнулить все
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Таблица привязанных артикулов Ozon -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-list-ul me-2"></i>
            Привязанные артикулы Ozon
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
                        <th>Артикул Ozon</th>
                        <th>Название на Ozon</th>
                        <th class="text-center" style="width: 100px;">Из листа/Упак.</th>
                        <th class="text-end" style="width: 130px;">
                            <span class="text-warning">Мин. цена</span>
                        </th>
                        <th class="text-end" style="width: 130px;">
                            <span class="text-info">Ваша цена</span>
                        </th>
                        <th class="text-end" style="width: 130px;">На Ozon</th>
                        <th class="text-center" style="width: 100px;">Остатки</th>
                        <th class="text-center" style="width: 80px;">Статус</th>
                        <th class="text-center" style="width: 60px;">Действия</th>
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
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-success" id="uploadSelectedBtn" disabled>
                    <i class="bi bi-cloud-upload me-1"></i>
                    Загрузить выбранные на Ozon
                </button>
                <button type="button" class="btn btn-outline-success" id="uploadAllBtn" disabled>
                    <i class="bi bi-cloud-upload-fill me-1"></i>
                    Загрузить все артикулы товара
                </button>
                <button type="button" class="btn btn-outline-info" id="uploadStocksOnlyBtn" disabled>
                    <i class="bi bi-box-seam me-1"></i>
                    Загрузить только остатки
                </button>
            </div>
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Цены загружаются с проверкой min_price &lt; price
            </div>
        </div>
    </div>
</div>

<!-- Результаты последней загрузки -->
<div class="card bg-dark border-secondary d-none" id="uploadResultsCard">
    <div class="card-header border-secondary">
        <i class="bi bi-journal-check me-2"></i>
        Результаты загрузки
    </div>
    <div class="card-body">
        <div class="row g-3" id="uploadResultsContent">
            <!-- Динамически заполняется -->
        </div>
    </div>
</div>

<!-- Модальное окно редактирования параметров упаковки -->
<div class="modal fade" id="editPackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Параметры упаковки</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPackMappingId">

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
                            <div class="form-text">Сколько штук в карточке на Ozon</div>
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

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
