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

<!-- Навигация по вкладкам -->
<ul class="nav nav-tabs mb-4" id="wbTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="wb-calculator-tab" data-bs-toggle="tab" data-bs-target="#wb-calculator-pane" type="button" role="tab">
            <i class="bi bi-calculator me-1"></i>
            <span class="hide-mobile">Калькулятор</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="wb-cutting-tab" data-bs-toggle="tab" data-bs-target="#wb-cutting-pane" type="button" role="tab">
            <i class="bi bi-scissors me-1"></i>
            <span class="hide-mobile">Раскрой листов</span>
        </button>
    </li>
</ul>

<!-- Содержимое вкладок -->
<div class="tab-content" id="wbTabsContent">

<!-- ==================== ВКЛАДКА: Калькулятор ==================== -->
<div class="tab-pane fade show active" id="wb-calculator-pane" role="tabpanel">

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
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-primary" id="recalculateBtn" disabled title="Пересчитать цены">
                        <i class="bi bi-calculator me-1"></i> Пересчитать
                    </button>
                    <div class="input-group" style="width: auto;">
                        <select class="form-select form-select-sm" id="wbSheetSelect" style="min-width: 180px;">
                            <option value="1520x1520">Фанера ФК 1520×1520</option>
                        </select>
                        <button type="button" class="btn btn-outline-info btn-sm" id="wbAutoFillBtn" disabled
                                title="Автозаполнение из справочника раскроя. Использует данные о размерах кусочков.">
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
                    <div class="metric-label">Цена для WB (при 90%)</div>
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
                <!-- Ручной ввод ID склада (показывается если API не вернул склады) -->
                <div class="mt-2" id="manualWarehouseBlock" style="display: none;">
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" id="manualWarehouseId"
                               placeholder="ID склада из ЛК WB" style="width: 150px;">
                        <button class="btn btn-outline-secondary" type="button" id="saveManualWarehouse">
                            <i class="bi bi-save"></i>
                        </button>
                    </div>
                    <div class="form-text small">ID склада → ЛК WB → Настройки → Склады</div>
                </div>
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

            <!-- Разделитель -->
            <div class="col-auto border-start border-secondary ps-3">
                <label class="form-label">Скидка для всех</label>
                <div class="input-group" style="width: 180px;">
                    <input type="number" class="form-control" id="wbBulkDiscount"
                           value="0" min="0" max="100" step="1">
                    <span class="input-group-text">%</span>
                    <button type="button" class="btn btn-outline-warning" id="wbApplyBulkDiscount"
                            title="Применить скидку ко всем артикулам">
                        <i class="bi bi-check-all"></i>
                    </button>
                </div>
            </div>

            <!-- Минимальная цена -->
            <div class="col-auto border-start border-secondary ps-3">
                <label class="form-label">Мин. цена</label>
                <div class="input-group" style="width: 180px;">
                    <input type="number" class="form-control" id="wbMinPriceThreshold"
                           value="500" min="0" step="1" placeholder="500">
                    <span class="input-group-text">₽</span>
                    <button type="button" class="btn btn-outline-info" id="wbApplyMinPrice"
                            title="Поднять все цены ниже указанной до минимума">
                        <i class="bi bi-arrow-up-circle"></i>
                    </button>
                </div>
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
                        <th class="text-end" style="width: 100px;">
                            <span class="text-warning">Наценка</span>
                        </th>
                        <th class="text-end" style="width: 70px;">
                            <span class="text-info">Скидка</span>
                        </th>
                        <th class="text-end" style="width: 120px;">
                            <span class="text-success">Цена для WB</span>
                        </th>
                        <th class="text-end" style="width: 100px;">На WB<br>сейчас</th>
                        <th class="text-center" style="width: 100px;">Остатки</th>
                        <th class="text-center" style="width: 80px;">Статус</th>
                        <th class="text-center" style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody id="articlesTableBody">
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
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

</div>
<!-- ==================== КОНЕЦ ВКЛАДКИ: Калькулятор ==================== -->

<!-- ==================== ВКЛАДКА: Раскрой листов (СПРАВОЧНИК) ==================== -->
<div class="tab-pane fade" id="wb-cutting-pane" role="tabpanel">

    <div class="row">
        <!-- Левая колонка: Список листов -->
        <div class="col-md-4">
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">
                    <i class="bi bi-layers me-2"></i>Исходные листы
                </div>
                <div class="card-body">
                    <!-- Добавление нового листа -->
                    <div class="mb-3">
                        <label class="form-label">Тип материала</label>
                        <select class="form-select" id="wbNewSheetType">
                            <option value="fanera_fk">Фанера ФК</option>
                            <option value="fanera_fsf">Фанера ФСФ</option>
                            <option value="fanera_fsf_lam">Фанера ФСФ ламинированная</option>
                            <option value="fanera_setch">Фанера сетчатая</option>
                            <option value="osb">OSB (ОСБ)</option>
                            <option value="mdf">МДФ</option>
                            <option value="lmdf">ЛМДФ</option>
                            <option value="dvp">ДВП</option>
                            <option value="other">Другой</option>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Ширина (мм)</label>
                            <input type="number" class="form-control" id="wbNewSheetWidth" value="1520" min="100" max="5000">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Высота (мм)</label>
                            <input type="number" class="form-control" id="wbNewSheetHeight" value="1520" min="100" max="5000">
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary w-100" id="wbBtnAddSheet">
                        <i class="bi bi-plus-circle me-1"></i>Добавить лист
                    </button>

                    <hr class="border-secondary">

                    <!-- Список листов -->
                    <div class="list-group list-group-flush" id="wbSheetsList">
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-hourglass-split"></i> Загрузка...
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Правая колонка: Раскрой для выбранного листа -->
        <div class="col-md-8">
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-grid-3x3 me-2"></i>
                        Раскрой: <span id="wbSelectedSheetName" class="text-info">выберите лист</span>
                    </span>
                    <div>
                        <button type="button" class="btn btn-outline-info btn-sm me-2" id="wbBtnLoadFromArticles" disabled>
                            <i class="bi bi-download me-1"></i>Из артикулов
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="wbBtnAddPiece" disabled>
                            <i class="bi bi-plus me-1"></i>Добавить размер
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Название</th>
                                    <th class="text-center">Размер (мм)</th>
                                    <th class="text-center">Авто-расчёт</th>
                                    <th class="text-center">Фактически</th>
                                    <th style="width: 100px;"></th>
                                </tr>
                            </thead>
                            <tbody id="wbPiecesTableBody">
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-arrow-left-circle display-6 d-block mb-2"></i>
                                        Выберите лист слева
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer border-secondary">
                    <button type="button" class="btn btn-success" id="wbBtnSavePieces" disabled>
                        <i class="bi bi-save me-1"></i>Сохранить изменения
                    </button>
                </div>
            </div>

            <!-- Подсказка -->
            <div class="alert alert-info">
                <i class="bi bi-lightbulb me-2"></i>
                <strong>Справочник раскроя</strong> определяет: сколько кусочков определённого размера
                получается из листа. Один раз настройте — данные будут использоваться в калькуляторе
                при нажатии "Автозаполнить".<br>
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i></span>
                — отмечает строки где фактическое количество отличается от авто-расчёта.
            </div>
        </div>
    </div>

</div>
<!-- ==================== КОНЕЦ ВКЛАДКИ: Раскрой листов ==================== -->

</div>
<!-- ==================== КОНЕЦ tab-content ==================== -->

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

<!-- Модальное окно добавления размера кусочка (для справочника раскроя) -->
<div class="modal fade" id="wbAddPieceModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Добавить размер кусочка
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Название (опционально)</label>
                    <input type="text" class="form-control" id="wbAddPieceName" placeholder="Например: A4, 500×500">
                    <div class="form-text">Если пусто — будет сгенерировано автоматически</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Быстрый выбор формата</label>
                    <select class="form-select" id="wbAddPiecePreset">
                        <option value="">— Свой размер —</option>
                        <optgroup label="Форматы бумаги">
                            <option value="105x148">A6 (105×148)</option>
                            <option value="148x210">A5 (148×210)</option>
                            <option value="210x297">A4 (210×297)</option>
                            <option value="297x420">A3 (297×420)</option>
                            <option value="420x594">A2 (420×594)</option>
                            <option value="594x841">A1 (594×841)</option>
                            <option value="841x1189">A0 (841×1189)</option>
                        </optgroup>
                        <optgroup label="Квадратные">
                            <option value="50x50">50×50</option>
                            <option value="100x100">100×100</option>
                            <option value="120x120">120×120</option>
                            <option value="150x150">150×150</option>
                            <option value="180x180">180×180</option>
                            <option value="200x200">200×200</option>
                            <option value="220x220">220×220</option>
                            <option value="250x250">250×250</option>
                            <option value="280x280">280×280</option>
                            <option value="300x300">300×300</option>
                            <option value="320x320">320×320</option>
                            <option value="350x350">350×350</option>
                            <option value="380x380">380×380</option>
                            <option value="400x400">400×400</option>
                            <option value="420x420">420×420</option>
                            <option value="450x450">450×450</option>
                            <option value="480x480">480×480</option>
                            <option value="500x500">500×500</option>
                            <option value="550x550">550×550</option>
                            <option value="600x600">600×600</option>
                            <option value="650x650">650×650</option>
                            <option value="700x700">700×700</option>
                            <option value="760x760">760×760</option>
                            <option value="800x800">800×800</option>
                            <option value="850x850">850×850</option>
                            <option value="900x900">900×900</option>
                            <option value="950x950">950×950</option>
                            <option value="1000x1000">1000×1000</option>
                            <option value="1100x1100">1100×1100</option>
                            <option value="1200x1200">1200×1200</option>
                            <option value="1500x1500">1500×1500</option>
                        </optgroup>
                        <optgroup label="Прямоугольные">
                            <option value="100x150">100×150</option>
                            <option value="100x200">100×200</option>
                            <option value="150x200">150×200</option>
                            <option value="150x300">150×300</option>
                            <option value="200x300">200×300</option>
                            <option value="200x400">200×400</option>
                            <option value="250x500">250×500</option>
                            <option value="300x400">300×400</option>
                            <option value="300x500">300×500</option>
                            <option value="300x600">300×600</option>
                            <option value="350x500">350×500</option>
                            <option value="400x500">400×500</option>
                            <option value="400x600">400×600</option>
                            <option value="400x800">400×800</option>
                            <option value="450x600">450×600</option>
                            <option value="500x600">500×600</option>
                            <option value="500x700">500×700</option>
                            <option value="500x750">500×750</option>
                            <option value="500x1000">500×1000</option>
                            <option value="600x800">600×800</option>
                            <option value="600x900">600×900</option>
                            <option value="600x1200">600×1200</option>
                            <option value="700x1000">700×1000</option>
                            <option value="760x380">760×380 (половина)</option>
                            <option value="760x506">760×506 (треть)</option>
                            <option value="800x1000">800×1000</option>
                            <option value="800x1200">800×1200</option>
                            <option value="1000x1500">1000×1500</option>
                            <option value="1000x2000">1000×2000</option>
                        </optgroup>
                    </select>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Ширина (мм)</label>
                        <input type="number" class="form-control" id="wbAddPieceWidth" min="10" max="5000" placeholder="210">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Высота (мм)</label>
                        <input type="number" class="form-control" id="wbAddPieceHeight" min="10" max="5000" placeholder="297">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Фактическое количество из листа</label>
                    <input type="number" class="form-control" id="wbAddPieceQty" min="1" placeholder="авто">
                    <div class="form-text">Оставьте пустым для авто-расчёта: <span id="wbAddPieceCalc" class="text-info">-</span> шт</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="wbBtnSaveNewPiece">
                    <i class="bi bi-plus-lg me-1"></i>Добавить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования размера кусочка (для справочника раскроя) -->
<div class="modal fade" id="wbEditPieceModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Редактировать размер
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="wbEditPieceId">

                <div class="mb-3">
                    <label class="form-label">Название (опционально)</label>
                    <input type="text" class="form-control" id="wbEditPieceName" placeholder="Например: A4, 500×500">
                    <div class="form-text">Если пусто — будет сгенерировано из размеров</div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Ширина (мм)</label>
                        <input type="number" class="form-control" id="wbEditPieceWidth" min="10" max="5000">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Высота (мм)</label>
                        <input type="number" class="form-control" id="wbEditPieceHeight" min="10" max="5000">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Фактическое количество из листа</label>
                    <input type="number" class="form-control" id="wbEditPieceQty" min="1">
                    <div class="form-text">Оставьте пустым для авто-расчёта</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="WBCuttingReference.updatePiece()">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
