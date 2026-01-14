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
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-calculator me-2 text-info"></i>
                <span class="hide-mobile">Калькулятор цен Ozon</span>
                <span class="show-mobile d-none">Калькулятор Ozon</span>
            </h4>
            <a href="/ozon/mapping" class="btn btn-outline-secondary">
                <i class="bi bi-link-45deg"></i><span class="hide-mobile ms-1">Сопоставления</span>
            </a>
        </div>
    </div>
</div>

<!-- Блок выбора товара -->
<div class="card bg-dark border-secondary mb-4">
    <div class="card-header border-secondary">
        <i class="bi bi-box-seam me-2"></i>
        Выбор товара
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
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
            <div class="col-md-6">
                <div class="d-flex gap-2 align-items-center">
                    <span class="text-muted" id="sheetSizeInfo"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Навигация по вкладкам -->
<ul class="nav nav-tabs mb-4" id="ozonTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="calculator-tab" data-bs-toggle="tab" data-bs-target="#calculator-pane" type="button" role="tab">
            <i class="bi bi-calculator me-1"></i>
            <span class="hide-mobile">Калькулятор</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="cutting-tab" data-bs-toggle="tab" data-bs-target="#cutting-pane" type="button" role="tab">
            <i class="bi bi-scissors me-1"></i>
            <span class="hide-mobile">Раскрой листов</span>
        </button>
    </li>
</ul>

<!-- Содержимое вкладок -->
<div class="tab-content" id="ozonTabsContent">

    <!-- ==================== ВКЛАДКА: Калькулятор ==================== -->
    <div class="tab-pane fade show active" id="calculator-pane" role="tabpanel">

        <!-- Настройки наценок -->
        <div class="card bg-dark border-secondary mb-4">
            <div class="card-header border-secondary">
                <i class="bi bi-percent me-2"></i>
                Настройки наценок
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
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

                    <!-- Наценка для цены до скидки -->
                    <div class="col-md-2">
                        <label for="markupOldPrice" class="form-label">
                            Скидка (%)
                            <i class="bi bi-question-circle text-muted" data-bs-toggle="tooltip" title="Наценка для зачёркнутой цены. Покупатель увидит скидку на Ozon"></i>
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="markupOldPrice" min="0" max="100" step="1" value="15">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <!-- Кнопки управления -->
                    <div class="col-md-6">
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <button type="button" class="btn btn-primary" id="recalculateBtn" disabled title="Пересчитать цены">
                                <i class="bi bi-calculator me-1"></i> Пересчитать
                            </button>
                            <div class="input-group" style="width: auto;">
                                <select class="form-select form-select-sm" id="sheetSelect" style="min-width: 180px;">
                                    <option value="1520x1520">Фанера ФК 1520×1520</option>
                                </select>
                                <button type="button" class="btn btn-outline-info btn-sm" id="autoFillBtn" disabled title="Автоматически определить кусочков из листа и количество из названий артикулов Ozon. Использует данные из справочника раскроя.">
                                    <i class="bi bi-magic me-1"></i> Автозаполнить
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary" id="saveMarkupsBtn" disabled title="Сохранить наценки">
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
                    <div class="col-md-3">
                        <div class="metric-card metric-old">
                            <div class="metric-value" id="calcOldPrice">-</div>
                            <div class="metric-label">Цена до скидки</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Управление остатками и ценами -->
        <div class="card bg-dark border-secondary mb-4 d-none" id="stockManagementCard">
            <div class="card-header border-secondary">
                <i class="bi bi-box-seam me-2"></i>Управление остатками и ценами
            </div>
            <div class="card-body">
                <div class="row align-items-end g-3">
                    <!-- Остатки -->
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

                    <!-- Разделитель -->
                    <div class="col-12 d-md-none"><hr class="border-secondary my-2"></div>
                    <div class="vr d-none d-md-block mx-2" style="height: 38px; align-self: end;"></div>

                    <!-- Корректировка мин. цены -->
                    <div class="col-auto">
                        <label class="form-label">Корректировка мин. цены</label>
                        <div class="input-group" style="width: 180px;">
                            <input type="number" class="form-control" id="minPriceCorrection"
                                   placeholder="500" min="0" step="1">
                            <span class="input-group-text">₽</span>
                            <button type="button" class="btn btn-warning" id="applyMinPriceCorrectionBtn"
                                    title="Поднять мин. цены ниже указанного значения">
                                <i class="bi bi-arrow-up-circle"></i>
                            </button>
                        </div>
                        <div class="form-text">Поднять цены ниже порога</div>
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
                    <table class="table table-dark table-striped table-hover mb-0" id="ozonArticlesTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="selectAllCheckbox" title="Выбрать все">
                                </th>
                                <th class="sortable" data-sort="article" onclick="OzonCalculator.handleSortClick('article')">Артикул Ozon</th>
                                <th class="sortable" data-sort="name" onclick="OzonCalculator.handleSortClick('name')">Название на Ozon</th>
                                <th class="text-center" style="width: 100px;">Из листа/Упак.</th>
                                <th class="text-end" style="width: 120px;">
                                    <span class="text-warning">Мин. цена</span>
                                </th>
                                <th class="text-end" style="width: 120px;">
                                    <span class="text-info">Ваша цена</span>
                                </th>
                                <th class="text-end" style="width: 120px;">
                                    <span class="text-old-price">До скидки</span>
                                </th>
                                <th class="text-end" style="width: 120px;">На Ozon</th>
                                <th class="text-center" style="width: 90px;">Остатки</th>
                                <th class="text-center" style="width: 70px;">Статус</th>
                                <th class="text-center" style="width: 50px;"></th>
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
                        <button type="button" class="btn btn-success flex-grow-1 flex-md-grow-0" id="uploadSelectedBtn" disabled>
                            <i class="bi bi-cloud-upload me-1"></i>
                            <span class="hide-mobile">Загрузить выбранные на Ozon</span>
                            <span class="show-mobile d-none">Выбранные</span>
                        </button>
                        <button type="button" class="btn btn-outline-success flex-grow-1 flex-md-grow-0" id="uploadAllBtn" disabled>
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

    </div>

    <!-- ==================== ВКЛАДКА: Раскрой листов (СПРАВОЧНИК) ==================== -->
    <div class="tab-pane fade" id="cutting-pane" role="tabpanel">

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
                            <select class="form-select" id="newSheetType">
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
                                <input type="number" class="form-control" id="newSheetWidth" value="1520" min="100" max="5000">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Высота (мм)</label>
                                <input type="number" class="form-control" id="newSheetHeight" value="1520" min="100" max="5000">
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary w-100" id="btnAddSheet">
                            <i class="bi bi-plus-circle me-1"></i>Добавить лист
                        </button>

                        <hr class="border-secondary">

                        <!-- Список листов -->
                        <div class="list-group list-group-flush" id="sheetsList">
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
                            Раскрой: <span id="selectedSheetName" class="text-info">выберите лист</span>
                        </span>
                        <div>
                            <button type="button" class="btn btn-outline-info btn-sm me-2" id="btnLoadFromArticles" disabled>
                                <i class="bi bi-download me-1"></i>Из артикулов
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddPiece" disabled>
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
                                <tbody id="piecesTableBody">
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
                        <button type="button" class="btn btn-success" id="btnSavePieces" disabled>
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

</div>

<!-- ==================== ВИЗУАЛЬНЫЙ КАЛЬКУЛЯТОР РАСКРОЯ ==================== -->
<div class="row mt-4 mb-4" id="ozonVisualCuttingSection">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-grid-3x3 me-2"></i>
                <span class="text-success"><strong>Визуальный калькулятор раскроя</strong></span>
            </div>
            <div class="card-body">
                <!-- Параметры расчёта -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Исходный лист</label>
                        <select id="vcSheetSelect" class="form-select">
                            <option value="">Выберите лист...</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ширина (мм)</label>
                        <input type="number" id="vcPieceWidth" class="form-control" placeholder="600" min="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Высота (мм)</label>
                        <input type="number" id="vcPieceHeight" class="form-control" placeholder="900" min="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Пропил (мм)</label>
                        <input type="number" id="vcKerfWidth" class="form-control" value="3" min="0" max="10">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="button" id="vcCalculateBtn" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-calculator me-1"></i>Рассчитать
                        </button>
                    </div>
                </div>

                <!-- Управление видом -->
                <div class="btn-group mb-3" role="group">
                    <button type="button" id="vcZoomIn" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-zoom-in"></i> Приблизить
                    </button>
                    <button type="button" id="vcZoomOut" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-zoom-out"></i> Отдалить
                    </button>
                    <button type="button" id="vcResetView" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrows-fullscreen"></i> Сброс вида
                    </button>
                    <button type="button" id="vcToggleDimensions" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-rulers"></i> Размеры вкл/выкл
                    </button>
                </div>
                
                <!-- Подсказка -->
                <div class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i> 
                    Перетащите деталь мышкой для перемещения. 
                    Двойной клик — поворот на 90°.
                </div>

                <!-- SVG визуализация и результаты -->
                <div class="row">
                    <!-- SVG область -->
                    <div class="col-lg-8">
                        <div id="vcSvgContainer" class="border rounded p-3 bg-dark" style="min-height: 450px; display: flex; justify-content: center; align-items: center; overflow: auto;">
                            <svg id="vcSvgSheet" width="100%" height="450" viewBox="0 0 2500 2500"></svg>
                        </div>
                        
                        <!-- Информационная панель размеров -->
                        <div id="vcDimensionsPanel" class="card bg-dark text-white mt-3 border-secondary d-none">
                            <div class="card-header">
                                <i class="bi bi-rulers me-2"></i> <strong>Параметры раскроя</strong>
                            </div>
                            <div class="card-body p-2">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Лист:</small>
                                        <div id="vcDimSheet" class="fs-6" style="color: #4CAF50;">2800 × 2070 мм</div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Деталь:</small>
                                        <div id="vcDimPiece" class="fs-6" style="color: #2196F3;">800 × 600 мм</div>
                                    </div>
                                </div>
                                <hr class="my-2">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Пропил:</small>
                                        <div id="vcDimKerf" class="fs-6" style="color: #FF9800;">3 мм</div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Эффективный размер:</small>
                                        <div id="vcDimEffective" class="fs-6" style="color: #FF9800;">803 × 603 мм</div>
                                    </div>
                                </div>
                                <hr class="my-2">
                                <div>
                                    <small class="text-muted">Неиспользованные области:</small>
                                    <ul id="vcDimWaste" class="mb-0 mt-1" style="color: #ff6b6b; font-size: 0.9rem;">
                                        <li>Справа: 400 × 2070 мм</li>
                                        <li>Снизу: 2400 × 270 мм</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center text-muted mt-2 small">
                            <i class="bi bi-info-circle me-1"></i> Клик по детали — поворот на 90°
                        </div>
                    </div>

                    <!-- Результаты -->
                    <div class="col-lg-4">
                        <!-- Плейсхолдер -->
                        <div id="vcPlaceholder" class="text-center text-muted py-5">
                            <i class="bi bi-grid-3x3" style="font-size: 3rem; opacity: 0.5;"></i>
                            <p class="mt-3 small">Выберите лист и укажите размер детали</p>
                        </div>

                        <!-- Результаты расчёта -->
                        <div id="vcResults" class="d-none">
                            <div class="card bg-secondary border-secondary mb-3">
                                <div class="card-header bg-success">
                                    <strong><i class="bi bi-bar-chart me-1"></i>Результат расчёта</strong>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-dark mb-0">
                                        <tbody>
                                            <tr>
                                                <td class="small">Деталей на листе:</td>
                                                <td class="text-end"><strong id="vcResultCount" class="text-info">0</strong> шт</td>
                                            </tr>
                                            <tr>
                                                <td class="small">Размещение:</td>
                                                <td class="text-end small" id="vcResultLayout">-</td>
                                            </tr>
                                            <tr>
                                                <td class="small">Использовано:</td>
                                                <td class="text-end"><strong id="vcResultUsage" class="text-success">0%</strong></td>
                                            </tr>
                                            <tr>
                                                <td class="small">Остаток:</td>
                                                <td class="text-end small" id="vcResultWaste">-</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Варианты раскроя -->
                            <div class="card bg-secondary border-secondary mb-3">
                                <div class="card-header bg-info">
                                    <strong><i class="bi bi-diagram-3 me-1"></i>Варианты раскроя</strong>
                                </div>
                                <div class="card-body p-2">
                                    <div id="vcVariants" style="max-height: 180px; overflow-y: auto;">
                                        <!-- Кнопки вариантов вставляются сюда -->
                                    </div>
                                </div>
                            </div>

                            <!-- Действия -->
                            <div class="d-grid gap-2">
                                <button type="button" id="vcApplyBtn" class="btn btn-success btn-sm">
                                    <i class="bi bi-check-circle me-1"></i>Применить в справочник
                                </button>
                                <button type="button" id="vcDownloadBtn" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-download me-1"></i>Скачать PNG
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- ==================== КОНЕЦ ВИЗУАЛЬНОГО КАЛЬКУЛЯТОРА ==================== -->

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

<!-- Модальное окно добавления размера кусочка -->
<div class="modal fade" id="addPieceModal" tabindex="-1">
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
                    <input type="text" class="form-control" id="addPieceName" placeholder="Например: A4, 500×500">
                    <div class="form-text">Если пусто — будет сгенерировано автоматически</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Быстрый выбор формата</label>
                    <select class="form-select" id="addPiecePreset">
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
                        <input type="number" class="form-control" id="addPieceWidth" min="10" max="5000" placeholder="210">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Высота (мм)</label>
                        <input type="number" class="form-control" id="addPieceHeight" min="10" max="5000" placeholder="297">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Фактическое количество из листа</label>
                    <input type="number" class="form-control" id="addPieceQty" min="1" placeholder="авто">
                    <div class="form-text">Оставьте пустым для авто-расчёта: <span id="addPieceCalc" class="text-info">-</span> шт</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="btnSaveNewPiece">
                    <i class="bi bi-plus-lg me-1"></i>Добавить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования размера кусочка -->
<div class="modal fade" id="editPieceModal" tabindex="-1">
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
                <input type="hidden" id="editPieceId">

                <div class="mb-3">
                    <label class="form-label">Название (опционально)</label>
                    <input type="text" class="form-control" id="editPieceName" placeholder="Например: A4, 500×500">
                    <div class="form-text">Если пусто — будет сгенерировано из размеров</div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Ширина (мм)</label>
                        <input type="number" class="form-control" id="editPieceWidth" min="10" max="5000">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Высота (мм)</label>
                        <input type="number" class="form-control" id="editPieceHeight" min="10" max="5000">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Фактическое количество из листа</label>
                    <input type="number" class="form-control" id="editPieceQty" min="1">
                    <div class="form-text">Оставьте пустым для авто-расчёта</div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="CuttingReference.updatePiece()">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/js/visual-cutting-calculator.js"></script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
