<?php
/**
 * Калькулятор цен Яндекс.Маркет
 * Расчёт и загрузка цен на маркетплейс
 */
$pageTitle = 'Калькулятор цен Яндекс.Маркет';
$pageScript = 'ym-calculator';
include VIEWS_PATH . '/layout/header.php';
?>

<!-- Заголовок страницы -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-calculator me-2 text-warning"></i>
                <span class="hide-mobile">Калькулятор цен Яндекс.Маркет</span>
                <span class="show-mobile d-none">Калькулятор ЯМ</span>
            </h4>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" id="syncYmBtn">
                    <i class="bi bi-arrow-repeat"></i><span class="hide-mobile ms-1">Синхронизация</span>
                </button>
                <a href="/yandex/mapping" class="btn btn-outline-secondary">
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
                    Выберите товар с привязанными артикулами ЯМ
                </div>
            </div>

            <!-- Наценка для минимальной цены -->
            <div class="col-md-2">
                <label for="markupMin" class="form-label">
                    Наценка (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Наценка для расчёта цены"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="markupMin" min="0" max="1000" step="0.1" value="20">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Скидка (зачёркнутая цена) -->
            <div class="col-md-2">
                <label for="ymDiscount" class="form-label">
                    Скидка (%)
                    <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Скидка для зачёркнутой цены"></i>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control" id="ymDiscount" min="0" max="95" step="1" value="0">
                    <span class="input-group-text">%</span>
                </div>
            </div>

            <!-- Кнопки управления -->
            <div class="col-md-4">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-primary" id="recalculateBtn" disabled title="Пересчитать цены">
                        <i class="bi bi-calculator me-1"></i> Пересчитать
                    </button>
                    <div class="input-group" style="width: auto;">
                        <select class="form-select form-select-sm" id="ymSheetSelect" style="min-width: 180px;">
                            <option value="1520x1520">Фанера ФК 1520×1520</option>
                        </select>
                        <button type="button" class="btn btn-outline-info btn-sm" id="ymAutoFillBtn" disabled
                                title="Автозаполнение из справочника раскроя">
                            <i class="bi bi-magic me-1"></i><span class="hide-mobile">Авто</span>
                        </button>
                    </div>
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
                    <div class="metric-label">Наценка (1 шт)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card metric-your">
                    <div class="metric-value" id="calcFinalPrice">-</div>
                    <div class="metric-label">Цена для ЯМ</div>
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
                <button type="button" class="btn btn-outline-danger" id="zeroStocksBtn">
                    <i class="bi bi-trash me-1"></i>Обнулить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Таблица артикулов -->
<div class="card bg-dark border-secondary">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-table me-2"></i>
            Артикулы товара
            <span class="badge bg-warning text-dark ms-2" id="articlesCount">0</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <div class="form-check form-switch me-3">
                <input class="form-check-input" type="checkbox" id="selectAllArticles">
                <label class="form-check-label" for="selectAllArticles">Выбрать все</label>
            </div>
            <button type="button" class="btn btn-warning btn-sm" id="uploadPricesBtn" disabled>
                <i class="bi bi-cloud-upload me-1"></i>Загрузить цены
            </button>
            <button type="button" class="btn btn-outline-warning btn-sm" id="uploadStocksBtn" disabled>
                <i class="bi bi-box-seam me-1"></i>Загрузить остатки
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover mb-0" id="articlesTable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="checkAll">
                        </th>
                        <th>Артикул ЯМ</th>
                        <th class="text-end">Упаковка</th>
                        <th class="text-end">Из листа</th>
                        <th class="text-end">Скидка</th>
                        <th class="text-end">Себест.</th>
                        <th class="text-end">Цена ЯМ</th>
                        <th class="text-end">Зачёркн.</th>
                        <th class="text-end">Остаток</th>
                    </tr>
                </thead>
                <tbody id="articlesTableBody">
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            Выберите товар для просмотра артикулов
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Результаты загрузки -->
<div class="card bg-dark border-secondary mt-4 d-none" id="uploadResultsCard">
    <div class="card-header border-secondary">
        <i class="bi bi-check-circle me-2"></i>
        Результаты загрузки
    </div>
    <div class="card-body" id="uploadResultsBody">
        <!-- Результаты будут добавлены динамически -->
    </div>
</div>

<!-- Модальное окно редактирования артикула -->
<div class="modal fade" id="editArticleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Редактирование артикула
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editArticleForm">
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
                    <div class="mb-3">
                        <label for="editCustomDiscount" class="form-label">Индивидуальная скидка (%)</label>
                        <input type="number" class="form-control" id="editCustomDiscount" min="0" max="99" step="1" value="0">
                        <div class="form-text">Скидка для этого конкретного артикула</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="saveArticleBtn">
                    <i class="bi bi-check-lg me-1"></i>
                    Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
