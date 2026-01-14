/**
 * Визуальный калькулятор раскроя листов
 * SVG-визуализация оптимизации раскроя материала
 */

const VisualCuttingCalculator = {
    // Настройки
    svgPadding: 30,
    pieceColors: ['#4CAF50', '#2196F3', '#FF9800', '#E91E63', '#9C27B0', '#00BCD4', '#8BC34A', '#FFC107'],
    wasteColor: '#424242',
    
    // Масштабирование и позиционирование
    zoomLevel: 1,
    panX: 0,
    panY: 0,
    showLabels: true,
    showDimensions: true,
    
    // Текущее состояние
    currentSheet: null,
    currentPiece: null,
    layouts: [],
    selectedLayoutIndex: 0,
    selectedPieceIndex: -1,
    currentLayout: null,
    
    // Состояние перетаскивания
    dragState: {
        isDragging: false,
        pieceIndex: null,
        startX: 0,
        startY: 0,
        offsetX: 0,
        offsetY: 0
    },
    
    /**
     * Инициализация
     */
    async init() {
        console.log('VisualCuttingCalculator.init() started');
        this.bindEvents();
        await this.loadSheets();  // async загрузка из БД
        console.log('VisualCuttingCalculator.init() completed');
    },
    
    /**
     * Привязка обработчиков
     */
    bindEvents() {
        document.getElementById('vcCalculateBtn')?.addEventListener('click', () => this.calculate());
        document.getElementById('vcApplyBtn')?.addEventListener('click', () => this.applyToReference());
        document.getElementById('vcDownloadBtn')?.addEventListener('click', () => this.downloadPNG());
        
        // Кнопки управления видом
        document.getElementById('vcZoomIn')?.addEventListener('click', () => this.zoomIn());
        document.getElementById('vcZoomOut')?.addEventListener('click', () => this.zoomOut());
        document.getElementById('vcResetView')?.addEventListener('click', () => this.resetView());
        document.getElementById('vcToggleDimensions')?.addEventListener('click', () => this.toggleDimensions());
        
        // Enter в полях ввода
        document.getElementById('vcPieceWidth')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.calculate();
        });
        document.getElementById('vcPieceHeight')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.calculate();
        });
    },
    
    /**
     * Загрузить список листов из БД (API) или использовать дефолтные
     */
    async loadSheets() {
        const select = document.getElementById('vcSheetSelect');
        if (!select) return;
        
        // Показать индикатор загрузки
        select.innerHTML = '<option value="">Загрузка листов из БД...</option>';
        
        try {
            // Запрос к API
            const response = await this.fetchAPI('/api/cutting/sheets', 'GET');
            
            if (response.success && response.sheets && response.sheets.length > 0) {
                // Заполнить dropdown листами из БД
                select.innerHTML = '<option value="">Выберите исходный лист...</option>';
                
                response.sheets.forEach(sheet => {
                    const option = document.createElement('option');
                    // Сохранить данные в формате который ожидает калькулятор
                    option.value = JSON.stringify({
                        id: sheet.id,
                        name: sheet.material_name,
                        width: parseInt(sheet.sheet_width),
                        height: parseInt(sheet.sheet_height)
                    });
                    option.textContent = `${sheet.material_name} (${sheet.sheet_width}×${sheet.sheet_height} мм)`;
                    select.appendChild(option);
                });
                
                console.log(`[VisualCutting] Загружено ${response.sheets.length} листов из БД`);
            } else {
                // Если в БД нет листов — показать дефолтные
                console.warn('[VisualCutting] Листы в БД не найдены, используются дефолтные');
                this.loadDefaultSheets();
            }
            
        } catch (error) {
            console.error('[VisualCutting] Ошибка загрузки листов из API:', error);
            // Fallback на дефолтные листы
            this.loadDefaultSheets();
        }
    },
    
    /**
     * Загрузить дефолтные листы (fallback если нет БД или она пуста)
     */
    loadDefaultSheets() {
        const select = document.getElementById('vcSheetSelect');
        if (!select) return;
        
        select.innerHTML = '<option value="">Выберите исходный лист...</option>';
        
        const defaultSheets = [
            { name: 'OSB (ОСБ)', width: 2500, height: 1250 },
            { name: 'ДВП', width: 2745, height: 1700 },
            { name: 'ЛМДФ', width: 2800, height: 2070 },
            { name: 'МДФ', width: 2800, height: 2070 },
            { name: 'Фанера сетчатая', width: 2500, height: 1250 },
            { name: 'Фанера ФК', width: 1520, height: 1520 },
            { name: 'Фанера ФСФ', width: 2440, height: 1220 },
            { name: 'Фанера ФСФ ламинированная', width: 2500, height: 1250 }
        ];
        
        defaultSheets.forEach(sheet => {
            const option = document.createElement('option');
            option.value = JSON.stringify(sheet);
            option.textContent = `${sheet.name} (${sheet.width}×${sheet.height} мм)`;
            select.appendChild(option);
        });
        
        console.log('[VisualCutting] Загружены дефолтные листы (fallback)');
    },
    
    /**
     * Вспомогательный метод для AJAX запросов
     */
    async fetchAPI(url, method = 'GET', data = null) {
        try {
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };
            
            if (method === 'POST' && data) {
                options.body = JSON.stringify(data);
            }
            
            const response = await fetch(url, options);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return await response.json();
        } catch (error) {
            console.error('[VisualCutting] Fetch error:', error);
            throw error;
        }
    },
    
    /**
     * Основной расчёт
     */
    calculate() {
        const sheetSelect = document.getElementById('vcSheetSelect');
        const pieceWidth = parseInt(document.getElementById('vcPieceWidth')?.value) || 0;
        const pieceHeight = parseInt(document.getElementById('vcPieceHeight')?.value) || 0;
        const kerfWidth = parseInt(document.getElementById('vcKerfWidth')?.value) || 3;
        
        if (!sheetSelect?.value) {
            App.showToast('Выберите лист', 'warning');
            return;
        }
        
        if (!pieceWidth || !pieceHeight) {
            App.showToast('Укажите размер детали', 'warning');
            return;
        }
        
        try {
            this.currentSheet = JSON.parse(sheetSelect.value);
        } catch (e) {
            App.showToast('Ошибка данных листа', 'danger');
            return;
        }
        
        this.currentPiece = { width: pieceWidth, height: pieceHeight };
        this.currentKerf = kerfWidth;
        this.selectedLayoutIndex = 0;
        this.selectedPieceIndex = -1;
        
        // Рассчитать все варианты раскроя с учётом пропила
        this.layouts = this.calculateAllLayouts(
            this.currentSheet.width,
            this.currentSheet.height,
            pieceWidth,
            pieceHeight,
            kerfWidth
        );
        
        // Показать лучший вариант
        if (this.layouts.length > 0) {
            this.renderSVG(this.layouts[0]);
            this.renderResults(this.layouts[0]);
            this.renderVariants(this.layouts);
            this.updateDimensionsPanel(this.layouts[0]);
            this.resetView();
            
            document.getElementById('vcResults')?.classList.remove('d-none');
            document.getElementById('vcPlaceholder')?.classList.add('d-none');
            document.getElementById('vcDimensionsPanel')?.classList.remove('d-none');
        } else {
            App.showToast('Деталь не помещается на лист', 'warning');
            document.getElementById('vcResults')?.classList.add('d-none');
            document.getElementById('vcPlaceholder')?.classList.remove('d-none');
            document.getElementById('vcDimensionsPanel')?.classList.add('d-none');
        }
    },
    
    /**
     * Рассчитать все варианты раскроя
     */
    calculateAllLayouts(sheetW, sheetH, pieceW, pieceH, kerf = 0) {
        const layouts = [];
        
        // Вариант A: Все горизонтально
        const layoutA = this.calculateSimpleLayout(sheetW, sheetH, pieceW, pieceH, kerf, 'horizontal');
        if (layoutA.count > 0) layouts.push(layoutA);
        
        // Вариант B: Все вертикально (повёрнуто на 90°)
        if (pieceW !== pieceH) {
            const layoutB = this.calculateSimpleLayout(sheetW, sheetH, pieceH, pieceW, kerf, 'vertical');
            if (layoutB.count > 0) layouts.push(layoutB);
        }
        
        // Вариант C: Комбинированный (оптимальный)
        const layoutC = this.calculateCombinedLayout(sheetW, sheetH, pieceW, pieceH, kerf);
        if (layoutC.count > 0) layouts.push(layoutC);
        
        // Сортировать по количеству деталей (лучший первый)
        layouts.sort((a, b) => b.count - a.count);
        
        // Убрать дубликаты
        const unique = [];
        const seen = new Set();
        layouts.forEach(layout => {
            const key = `${layout.count}-${layout.type}`;
            if (!seen.has(key)) {
                seen.add(key);
                unique.push(layout);
            }
        });
        
        return unique;
    },
    
    /**
     * Простой раскрой (все детали в одном направлении) с учётом пропила
     */
    calculateSimpleLayout(sheetW, sheetH, pieceW, pieceH, kerf = 0, type) {
        // Эффективный размер детали = размер + пропил
        const effectiveW = pieceW + kerf;
        const effectiveH = pieceH + kerf;
        
        // Количество деталей
        const cols = Math.floor((sheetW + kerf) / effectiveW);
        const rows = Math.floor((sheetH + kerf) / effectiveH);
        const count = cols * rows;
        
        const pieces = [];
        for (let row = 0; row < rows; row++) {
            for (let col = 0; col < cols; col++) {
                pieces.push({
                    x: col * effectiveW,
                    y: row * effectiveH,
                    width: pieceW,
                    height: pieceH,
                    rotated: type === 'vertical'
                });
            }
        }
        
        const usedArea = count * pieceW * pieceH;
        const totalArea = sheetW * sheetH;
        
        return {
            type,
            count,
            pieces,
            cols,
            rows,
            usedArea,
            totalArea,
            usagePercent: ((usedArea / totalArea) * 100).toFixed(1),
            description: `${cols}×${rows} (${type === 'horizontal' ? 'горизонтально' : 'вертикально'})`
        };
    },
    
    /**
     * Комбинированный раскрой (смешанные ориентации)
     */
    calculateCombinedLayout(sheetW, sheetH, pieceW, pieceH, kerf = 0) {
        let bestLayout = { count: 0, pieces: [], type: 'combined' };
        
        // Если деталь квадратная — комбинированный не нужен
        if (pieceW === pieceH) {
            return bestLayout;
        }
        
        // Попробуем несколько комбинаций
        // Основная сетка горизонтально, остаток — вертикально
        const mainCols = Math.floor(sheetW / pieceW);
        const mainRows = Math.floor(sheetH / pieceH);
        
        if (mainCols <= 0 || mainRows <= 0) {
            return bestLayout;
        }
        
        const pieces = [];
        
        // Основная сетка (горизонтально)
        for (let row = 0; row < mainRows; row++) {
            for (let col = 0; col < mainCols; col++) {
                pieces.push({
                    x: col * pieceW,
                    y: row * pieceH,
                    width: pieceW,
                    height: pieceH,
                    rotated: false
                });
            }
        }
        
        // Остаток справа (вертикальные детали)
        const rightWaste = sheetW - (mainCols * pieceW);
        if (rightWaste >= pieceH) {
            const rightCols = Math.floor(rightWaste / pieceH);
            const rightRows = Math.floor(sheetH / pieceW);
            
            if (rightCols > 0 && rightRows > 0) {
                for (let row = 0; row < rightRows; row++) {
                    for (let col = 0; col < rightCols; col++) {
                        pieces.push({
                            x: mainCols * pieceW + col * pieceH,
                            y: row * pieceW,
                            width: pieceH,
                            height: pieceW,
                            rotated: true
                        });
                    }
                }
            }
        }
        
        // Остаток снизу (горизонтальные)
        const bottomWaste = sheetH - (mainRows * pieceH);
        if (bottomWaste >= pieceH) {
            const bottomCols = Math.floor(sheetW / pieceW);
            const bottomRows = Math.floor(bottomWaste / pieceH);
            
            if (bottomCols > 0 && bottomRows > 0) {
                for (let row = 0; row < bottomRows; row++) {
                    for (let col = 0; col < bottomCols; col++) {
                        pieces.push({
                            x: col * pieceW,
                            y: mainRows * pieceH + row * pieceH,
                            width: pieceW,
                            height: pieceH,
                            rotated: false
                        });
                    }
                }
            }
        } else if (bottomWaste >= pieceW) {
            const bottomCols = Math.floor(sheetW / pieceH);
            const bottomRows = Math.floor(bottomWaste / pieceW);
            
            if (bottomCols > 0 && bottomRows > 0) {
                for (let row = 0; row < bottomRows; row++) {
                    for (let col = 0; col < bottomCols; col++) {
                        pieces.push({
                            x: col * pieceH,
                            y: mainRows * pieceH + row * pieceW,
                            width: pieceH,
                            height: pieceW,
                            rotated: true
                        });
                    }
                }
            }
        }
        
        const count = pieces.length;
        if (count > 0) {
            const usedArea = count * pieceW * pieceH;
            const totalArea = sheetW * sheetH;
            
            bestLayout = {
                type: 'combined',
                count,
                pieces,
                cols: mainCols,
                rows: mainRows,
                usedArea,
                totalArea,
                usagePercent: ((usedArea / totalArea) * 100).toFixed(1),
                description: `Комбинированный (${count} шт)`
            };
        }
        
        return bestLayout;
    },
    
    /**
     * Отрисовка SVG
     */
    renderSVG(layout, activePieceIndex = null, hasCollision = false) {
        const svg = document.getElementById('vcSvgSheet');
        if (!svg) return;
        
        // Сохранить текущий layout
        this.currentLayout = layout;
        
        const container = document.getElementById('vcSvgContainer');
        if (!container) return;
        
        const containerWidth = container.clientWidth - this.svgPadding * 2;
        const containerHeight = 400 - this.svgPadding * 2;
        
        // Масштаб для отображения
        const scaleX = containerWidth / this.currentSheet.width;
        const scaleY = containerHeight / this.currentSheet.height;
        const scale = Math.min(scaleX, scaleY);
        
        // Очистить SVG
        svg.innerHTML = '';
        svg.setAttribute('viewBox', `0 0 ${this.currentSheet.width} ${this.currentSheet.height}`);
        
        // Создать группу для трансформации (масштаб/сдвиг)
        const mainGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        mainGroup.setAttribute('class', 'main-group');
        
        // Фон листа
        const bgRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        bgRect.setAttribute('x', 0);
        bgRect.setAttribute('y', 0);
        bgRect.setAttribute('width', this.currentSheet.width);
        bgRect.setAttribute('height', this.currentSheet.height);
        bgRect.setAttribute('fill', this.wasteColor);
        bgRect.setAttribute('stroke', '#666');
        bgRect.setAttribute('stroke-width', 2);
        mainGroup.appendChild(bgRect);
        
        // Визуализировать остатки
        this.drawWasteAreas(mainGroup, layout);
        
        // Детали
        layout.pieces.forEach((piece, index) => {
            const color = this.pieceColors[index % this.pieceColors.length];
            const isActive = index === activePieceIndex;
            const isColliding = isActive && hasCollision;
            
            // Группа для детали (для drag-and-drop)
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.setAttribute('class', 'piece-group');
            g.setAttribute('data-piece-index', index);
            g.style.cursor = 'grab';
            
            // Прямоугольник детали
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', piece.x);
            rect.setAttribute('y', piece.y);
            rect.setAttribute('width', piece.width);
            rect.setAttribute('height', piece.height);
            rect.setAttribute('fill', isColliding ? '#ff0000' : color);
            rect.setAttribute('stroke', isActive ? '#ffff00' : '#fff');
            rect.setAttribute('stroke-width', isActive ? 4 : 2);
            rect.setAttribute('opacity', isActive ? 1 : 0.85);
            rect.setAttribute('class', `piece-item piece-${index}`);
            rect.setAttribute('data-piece-index', index);
            g.appendChild(rect);
            
            // Номер детали — КРУПНЫЙ
            const fontSize = Math.max(Math.min(piece.width, piece.height) / 2.5, 60);
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', piece.x + piece.width / 2);
            text.setAttribute('y', piece.y + piece.height / 2 - fontSize / 3);
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'middle');
            text.setAttribute('fill', '#fff');
            text.setAttribute('font-size', fontSize);
            text.setAttribute('font-weight', 'bold');
            text.setAttribute('pointer-events', 'none');
            text.textContent = index + 1;
            g.appendChild(text);
            
            // Размер детали — СРЕДНИЙ (увеличен с /6 до /4)
            if (this.showLabels) {
                const sizeFont = Math.max(Math.min(piece.width, piece.height) / 4, 40);
                const sizeText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                sizeText.setAttribute('x', piece.x + piece.width / 2);
                sizeText.setAttribute('y', piece.y + piece.height / 2 + fontSize / 2);
                sizeText.setAttribute('text-anchor', 'middle');
                sizeText.setAttribute('fill', '#fff');
                sizeText.setAttribute('font-size', sizeFont);
                sizeText.setAttribute('font-weight', 'bold');
                sizeText.setAttribute('pointer-events', 'none');
                sizeText.setAttribute('class', 'dimension-label');
                sizeText.textContent = `${piece.width}×${piece.height}`;
                g.appendChild(sizeText);
            }
            
            // Обработчики событий
            g.addEventListener('mousedown', (e) => {
                // Одиночный клик — начать перетаскивание
                this.startDrag(e, index);
            });
            
            g.addEventListener('dblclick', (e) => {
                // Двойной клик — поворот
                e.preventDefault();
                this.rotatePiece(layout, index);
            });
            
            // Hover эффект
            g.addEventListener('mouseenter', () => {
                if (!this.dragState.isDragging) {
                    rect.setAttribute('opacity', 1);
                    g.style.cursor = 'grab';
                }
            });
            
            g.addEventListener('mouseleave', () => {
                if (!this.dragState.isDragging) {
                    rect.setAttribute('opacity', 0.85);
                }
            });
            
            mainGroup.appendChild(g);
        });
        
        // Визуализировать пропилы
        this.drawKerfLines(mainGroup, layout);
        
        // Добавить размерные линии
        this.addDimensionLines(mainGroup, layout);
        
        // Размеры листа (подписи)
        this.addDimensionLabels(mainGroup);
        
        // Добавить группу в SVG
        svg.appendChild(mainGroup);
        
        // Применить трансформацию
        this.updateSvgTransform();
    },
    
    /**
     * Добавить размерные линии как на чертежах
     */
    addDimensionLines(svg, layout) {
        const w = this.currentSheet.width;
        const h = this.currentSheet.height;
        const margin = 60;
        const offset = 15;
        
        // Размерная линия для ширины листа (сверху)
        this.drawDimensionLine(svg, {
            x1: 0,
            y1: -margin,
            x2: w,
            y2: -margin,
            label: w + ' мм',
            position: 'top',
            color: '#4CAF50',
            extendTo: [0, 0, w, 0]
        });
        
        // Размерная линия для высоты листа (слева)
        this.drawDimensionLine(svg, {
            x1: -margin,
            y1: 0,
            x2: -margin,
            y2: h,
            label: h + ' мм',
            position: 'left',
            color: '#4CAF50',
            extendTo: [0, 0, 0, h]
        });
        
        // Размерные линии для каждой детали
        layout.pieces.forEach((piece, index) => {
            // Ширина детали
            this.drawDimensionLine(svg, {
                x1: piece.x,
                y1: piece.y - offset,
                x2: piece.x + piece.width,
                y2: piece.y - offset,
                label: piece.width + ' мм',
                position: 'top',
                color: '#2196F3',
                extendTo: [piece.x, piece.y, piece.x + piece.width, piece.y],
                fontSize: 12
            });
            
            // Высота детали
            this.drawDimensionLine(svg, {
                x1: piece.x - offset,
                y1: piece.y,
                x2: piece.x - offset,
                y2: piece.y + piece.height,
                label: piece.height + ' мм',
                position: 'left',
                color: '#2196F3',
                extendTo: [piece.x, piece.y, piece.x, piece.y + piece.height],
                fontSize: 12
            });
        });
        
        // Размеры остатков (неиспользуемые области) - красным цветом
        const totalArea = w * h;
        let usedArea = 0;
        layout.pieces.forEach(piece => {
            usedArea += piece.width * piece.height;
        });
        const wasteArea = totalArea - usedArea;
        const wastePercent = Math.round((wasteArea / totalArea) * 100);
        
        if (wastePercent > 5) {
            // Добавить подпись об отходах
            const wasteText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            wasteText.setAttribute('x', w - 20);
            wasteText.setAttribute('y', h - 20);
            wasteText.setAttribute('text-anchor', 'end');
            wasteText.setAttribute('dominant-baseline', 'middle');
            wasteText.setAttribute('fill', '#ff6b6b');
            wasteText.setAttribute('font-size', '14');
            wasteText.setAttribute('font-weight', 'bold');
            wasteText.textContent = `Отходы: ${wastePercent}%`;
            svg.appendChild(wasteText);
        }
    },
    
    /**
     * Нарисовать размерную линию со стрелками
     */
    drawDimensionLine(svg, opts) {
        const {
            x1, y1, x2, y2,
            label,
            position = 'top',
            color = '#2196F3',
            extendTo = null,
            fontSize = 14
        } = opts;
        
        // Создать группу для размерной линии
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('class', 'dimension-line');
        
        // Выносные линии
        if (extendTo) {
            const [x3, y3, x4, y4] = extendTo;
            
            const line1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line1.setAttribute('x1', x3);
            line1.setAttribute('y1', y3);
            line1.setAttribute('x2', x1);
            line1.setAttribute('y2', y1);
            line1.setAttribute('stroke', color);
            line1.setAttribute('stroke-width', 0.5);
            line1.setAttribute('stroke-dasharray', '3,3');
            group.appendChild(line1);
            
            const line2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line2.setAttribute('x1', x4);
            line2.setAttribute('y1', y4);
            line2.setAttribute('x2', x2);
            line2.setAttribute('y2', y2);
            line2.setAttribute('stroke', color);
            line2.setAttribute('stroke-width', 0.5);
            line2.setAttribute('stroke-dasharray', '3,3');
            group.appendChild(line2);
        }
        
        // Основная размерная линия
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', x1);
        line.setAttribute('y1', y1);
        line.setAttribute('x2', x2);
        line.setAttribute('y2', y2);
        line.setAttribute('stroke', color);
        line.setAttribute('stroke-width', 1.5);
        group.appendChild(line);
        
        // Стрелки
        const arrowSize = 6;
        const arrow1 = this.createArrow(x1, y1, x2, y2, arrowSize, color);
        group.appendChild(arrow1);
        
        const arrow2 = this.createArrow(x2, y2, x1, y1, arrowSize, color);
        group.appendChild(arrow2);
        
        // Текст размера
        const midX = (x1 + x2) / 2;
        const midY = (y1 + y2) / 2;
        
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', midX);
        text.setAttribute('y', midY);
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'middle');
        text.setAttribute('fill', color);
        text.setAttribute('font-size', fontSize);
        text.setAttribute('font-weight', 'bold');
        text.setAttribute('pointer-events', 'none');
        
        // Фон для текста
        const isVertical = Math.abs(x1 - x2) < Math.abs(y1 - y2);
        
        const bgRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        bgRect.setAttribute('x', midX - (label.length * 3.5));
        bgRect.setAttribute('y', midY - 8);
        bgRect.setAttribute('width', label.length * 7);
        bgRect.setAttribute('height', 16);
        bgRect.setAttribute('fill', '#1a1a1a');
        bgRect.setAttribute('opacity', 0.9);
        bgRect.setAttribute('rx', 2);
        group.appendChild(bgRect);
        
        text.textContent = label;
        group.appendChild(text);
        
        svg.appendChild(group);
    },
    
    /**
     * Создать стрелку для размерной линии
     */
    createArrow(x, y, toX, toY, size, color) {
        // Вычислить угол между точками
        const angle = Math.atan2(toY - y, toX - x);
        
        // Вершины треугольника стрелки
        const points = [
            [x, y],
            [x - size * Math.cos(angle - Math.PI / 6), y - size * Math.sin(angle - Math.PI / 6)],
            [x - size * Math.cos(angle + Math.PI / 6), y - size * Math.sin(angle + Math.PI / 6)]
        ];
        
        const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        polygon.setAttribute('points', points.map(p => p.join(',')).join(' '));
        polygon.setAttribute('fill', color);
        polygon.setAttribute('stroke', 'none');
        
        return polygon;
    },
    
    /**
     * Повернуть деталь на 90 градусов
     */
    rotatePiece(piece, index, layout) {
        // Поменять ширину и высоту
        const temp = piece.width;
        piece.width = piece.height;
        piece.height = temp;
        
        // Отметить, что деталь повёрнута
        if (!piece.rotated) {
            piece.rotated = true;
        } else {
            piece.rotated = false;
        }
        
        // Пересчитать размещение
        this.calculate();
        
        // Показать уведомление
        const message = `Деталь #${index + 1} повёрнута на 90°`;
        this.showNotification(message);
    },
    
    /**
     * Показать уведомление пользователю
     */
    showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show';
        notification.setAttribute('role', 'alert');
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        const container = document.querySelector('#vcCalculator');
        if (container) {
            container.insertBefore(notification, container.firstChild);
            
            // Автоматически скрыть через 3 секунды
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    },

    /**
     * Добавить подписи размеров листа
     */
    addDimensionLabels(svg) {
        const w = this.currentSheet.width;
        const h = this.currentSheet.height;
        const fontSize = 40;
        
        // Ширина сверху
        const widthText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        widthText.setAttribute('x', w / 2);
        widthText.setAttribute('y', -8);
        widthText.setAttribute('text-anchor', 'middle');
        widthText.setAttribute('fill', '#999');
        widthText.setAttribute('font-size', fontSize);
        widthText.textContent = w + ' мм';
        svg.appendChild(widthText);
        
        // Высота слева
        const heightText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        heightText.setAttribute('x', -8);
        heightText.setAttribute('y', h / 2);
        heightText.setAttribute('text-anchor', 'middle');
        heightText.setAttribute('fill', '#999');
        heightText.setAttribute('font-size', fontSize);
        heightText.setAttribute('transform', `rotate(-90, -8, ${h / 2})`);
        heightText.textContent = h + ' мм';
        svg.appendChild(heightText);
    },
    
    /**
     * Отобразить результаты
     */
    renderResults(layout) {
        document.getElementById('vcResultCount').textContent = layout.count;
        document.getElementById('vcResultLayout').textContent = layout.description;
        document.getElementById('vcResultUsage').textContent = layout.usagePercent + '%';
        
        const totalArea = layout.totalArea || (this.currentSheet.width * this.currentSheet.height);
        const wasteArea = totalArea - layout.usedArea;
        const wastePercent = ((wasteArea / totalArea) * 100).toFixed(1);
        
        document.getElementById('vcResultWaste').textContent = wastePercent + '% остатка';
    },
    
    /**
     * Переключить видимость размеров
     */
    toggleDimensions() {
        this.showDimensions = !this.showDimensions;
        
        // Найти все элементы размеров
        const dimensionElements = document.querySelectorAll('.dimension-label, .dimension-line text');
        dimensionElements.forEach(el => {
            el.style.display = this.showDimensions ? 'block' : 'none';
        });
        
        if (typeof App !== 'undefined' && App.showToast) {
            App.showToast(this.showDimensions ? 'Размеры включены' : 'Размеры выключены', 'info');
        }
    },
    
    /**
     * Подсветить деталь (при наведении или перетаскивании)
     */
    highlightPiece(index, isActive) {
        const pieces = document.querySelectorAll(`[data-piece-index="${index}"]`);
        pieces.forEach(piece => {
            if (isActive) {
                piece.setAttribute('stroke', '#ffff00');
                piece.setAttribute('stroke-width', '4');
                piece.style.filter = 'drop-shadow(0 0 10px rgba(255,255,0,0.5))';
                piece.style.cursor = 'grabbing';
            } else {
                piece.setAttribute('stroke', '#fff');
                piece.setAttribute('stroke-width', '2');
                piece.style.filter = 'none';
                piece.style.cursor = 'grab';
            }
        });
    },
    
    /**
     * Проверка пересечения прямоугольников
     */
    rectsIntersect(r1, r2) {
        return !(r1.x + r1.width <= r2.x || 
                 r2.x + r2.width <= r1.x || 
                 r1.y + r1.height <= r2.y || 
                 r2.y + r2.height <= r1.y);
    },
    
    /**
     * Проверка пересечений с другими деталями
     */
    checkCollisions(pieceIndex) {
        if (!this.currentLayout) return false;
        
        const piece = this.currentLayout.pieces[pieceIndex];
        const kerf = parseInt(document.getElementById('vcKerfWidth')?.value) || 3;
        
        return this.currentLayout.pieces.some((other, i) => {
            if (i === pieceIndex) return false;
            return this.rectsIntersect(
                { x: piece.x, y: piece.y, width: piece.width + kerf, height: piece.height + kerf },
                { x: other.x, y: other.y, width: other.width + kerf, height: other.height + kerf }
            );
        });
    },
    
    /**
     * Начало перетаскивания
     */
    startDrag(event, pieceIndex) {
        if (!this.currentLayout) return;
        
        event.preventDefault();
        
        const svg = document.getElementById('vcSvgSheet');
        if (!svg) return;
        
        const pt = svg.createSVGPoint();
        pt.x = event.clientX;
        pt.y = event.clientY;
        const svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
        
        const piece = this.currentLayout.pieces[pieceIndex];
        
        this.dragState = {
            isDragging: true,
            pieceIndex: pieceIndex,
            startX: piece.x,
            startY: piece.y,
            offsetX: svgP.x - piece.x,
            offsetY: svgP.y - piece.y
        };
        
        // Подсветить перетаскиваемую деталь
        this.highlightPiece(pieceIndex, true);
        
        // Добавить обработчики на document
        const moveFn = this.onDrag.bind(this);
        const upFn = this.endDrag.bind(this);
        
        document.addEventListener('mousemove', moveFn, false);
        document.addEventListener('mouseup', upFn, false);
        
        // Сохранить для удаления позже
        this._dragMoveFn = moveFn;
        this._dragUpFn = upFn;
    },
    
    /**
     * Процесс перетаскивания
     */
    onDrag(event) {
        if (!this.dragState.isDragging || !this.currentLayout) return;
        
        const svg = document.getElementById('vcSvgSheet');
        if (!svg) return;
        
        const pt = svg.createSVGPoint();
        pt.x = event.clientX;
        pt.y = event.clientY;
        const svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
        
        const piece = this.currentLayout.pieces[this.dragState.pieceIndex];
        
        // Новые координаты
        let newX = svgP.x - this.dragState.offsetX;
        let newY = svgP.y - this.dragState.offsetY;
        
        // Привязка к сетке (шаг 10 мм)
        const gridStep = 10;
        newX = Math.round(newX / gridStep) * gridStep;
        newY = Math.round(newY / gridStep) * gridStep;
        
        // Ограничить границами листа
        newX = Math.max(0, Math.min(newX, this.currentSheet.width - piece.width));
        newY = Math.max(0, Math.min(newY, this.currentSheet.height - piece.height));
        
        // Обновить позицию (временно, для предпросмотра)
        piece.x = newX;
        piece.y = newY;
        
        // Проверить пересечения
        const hasCollision = this.checkCollisions(this.dragState.pieceIndex);
        
        // Перерисовать с подсветкой коллизий
        this.renderSVG(this.currentLayout, this.dragState.pieceIndex, hasCollision);
    },
    
    /**
     * Конец перетаскивания
     */
    endDrag(event) {
        if (!this.dragState.isDragging || !this.currentLayout) return;
        
        const pieceIndex = this.dragState.pieceIndex;
        const hasCollision = this.checkCollisions(pieceIndex);
        
        if (hasCollision) {
            // Вернуть на исходную позицию
            const piece = this.currentLayout.pieces[pieceIndex];
            piece.x = this.dragState.startX;
            piece.y = this.dragState.startY;
            if (typeof App !== 'undefined' && App.showToast) {
                App.showToast('Деталь пересекается с другой!', 'warning');
            }
        } else {
            if (typeof App !== 'undefined' && App.showToast) {
                App.showToast(`Деталь ${pieceIndex + 1} перемещена`, 'info');
            }
        }
        
        // Сбросить состояние
        this.dragState = {
            isDragging: false,
            pieceIndex: null,
            startX: 0,
            startY: 0,
            offsetX: 0,
            offsetY: 0
        };
        
        // Убрать обработчики
        if (this._dragMoveFn) {
            document.removeEventListener('mousemove', this._dragMoveFn);
        }
        if (this._dragUpFn) {
            document.removeEventListener('mouseup', this._dragUpFn);
        }
        
        // Перерисовать финальный результат
        this.renderSVG(this.currentLayout);
        this.updateResults();
    },
    
    /**
     * Отобразить варианты раскроя
     */
    renderVariants(layouts) {
        const container = document.getElementById('vcVariants');
        if (!container) return;
        
        container.innerHTML = '';
        
        layouts.forEach((layout, index) => {
            const btn = document.createElement('button');
            btn.className = `btn btn-sm ${index === 0 ? 'btn-primary' : 'btn-outline-secondary'} me-1 mb-2`;
            btn.innerHTML = `<small>${layout.description}</small><br><strong>${layout.count} шт (${layout.usagePercent}%)</strong>`;
            btn.style.minWidth = '140px';
            btn.onclick = () => {
                this.selectedLayoutIndex = index;
                this.selectedPieceIndex = -1;  // Сбросить выбор детали
                this.renderSVG(layout);
                this.renderResults(layout);
                this.updateDimensionsPanel(layout);
                
                // Подсветить выбранный
                container.querySelectorAll('button').forEach(b => {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-secondary');
                });
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-primary');
            };
            container.appendChild(btn);
        });
    },
    
    /**
     * Повернуть деталь на 90 градусов и пересчитать
     */
    rotatePattern() {
        if (!this.currentPiece) {
            App.showToast('Сначала выполните расчёт', 'warning');
            return;
        }
        
        // Поменяем местами ширину и высоту
        [this.currentPiece.width, this.currentPiece.height] = [this.currentPiece.height, this.currentPiece.width];
        
        // Обновим поля ввода
        document.getElementById('vcPieceWidth').value = this.currentPiece.width;
        document.getElementById('vcPieceHeight').value = this.currentPiece.height;
        
        // Пересчитаем
        this.calculate();
    },
    
    /**
     * Применить в справочник раскроя
     */
    applyToReference() {
        if (!this.layouts.length || !this.currentSheet || !this.currentPiece) {
            App.showToast('Выполните расчёт раскроя', 'warning');
            return;
        }
        
        const layout = this.layouts[this.selectedLayoutIndex];
        const pieceKey = `${this.currentPiece.width}x${this.currentPiece.height}`;
        
        console.log('Применить в справочник:', {
            sheet: this.currentSheet,
            piece: this.currentPiece,
            count: layout.count,
            usage: layout.usagePercent
        });
        
        App.showToast(`Добавлено: ${this.currentPiece.width}×${this.currentPiece.height} мм = ${layout.count} шт`, 'success');
        
        // TODO: API-интеграция со справочником раскроя
    },
    
    /**
     * Скачать PNG
     */
    downloadPNG() {
        const svg = document.getElementById('vcSvgSheet');
        if (!svg) return;
        
        const svgData = new XMLSerializer().serializeToString(svg);
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();
        
        const scale = 2;
        canvas.width = (this.currentSheet.width * scale) / 10;  // Масштабируем для качественного PNG
        canvas.height = (this.currentSheet.height * scale) / 10;
        
        img.onload = () => {
            ctx.fillStyle = '#1a1a2e';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            
            const link = document.createElement('a');
            link.download = `razkroy_${this.currentSheet.name.replace(/\s+/g, '_')}_${this.currentPiece.width}x${this.currentPiece.height}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        };
        
        img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgData)));
        
        App.showToast('PNG загружается...', 'info');
    },
    
    /**
     * Приблизить SVG
     */
    zoomIn() {
        this.zoomLevel = Math.min(this.zoomLevel * 1.2, 3);
        this.updateSvgTransform();
    },
    
    /**
     * Отдалить SVG
     */
    zoomOut() {
        this.zoomLevel = Math.max(this.zoomLevel / 1.2, 0.5);
        this.updateSvgTransform();
    },
    
    /**
     * Сброс масштаба и позиции
     */
    resetView() {
        this.zoomLevel = 1;
        this.panX = 0;
        this.panY = 0;
        this.updateSvgTransform();
    },
    
    /**
     * Применить трансформацию масштаба
     */
    updateSvgTransform() {
        const g = document.querySelector('#vcSvgSheet g.main-group');
        if (g) {
            g.setAttribute('transform', 
                `translate(${this.panX}, ${this.panY}) scale(${this.zoomLevel})`
            );
        }
    },
    
    /**
     * Переключить видимость размеров
     */
    toggleLabels() {
        this.showLabels = !this.showLabels;
        if (this.layouts.length > 0) {
            this.renderSVG(this.layouts[this.selectedLayoutIndex]);
        }
    },
    
    /**
     * Повернуть деталь по клику
     */
    rotatePiece(layout, pieceIndex) {
        const piece = layout.pieces[pieceIndex];
        if (!piece) return;
        
        const kerf = this.currentKerf || 0;
        
        // Новые размеры после поворота
        const newWidth = piece.height;
        const newHeight = piece.width;
        
        // Проверить границы листа
        if (piece.x + newWidth > this.currentSheet.width || 
            piece.y + newHeight > this.currentSheet.height) {
            App.showToast('Повёрнутая деталь не помещается на лист!', 'warning');
            return;
        }
        
        // Проверить пересечение с другими деталями
        const wouldIntersect = layout.pieces.some((other, i) => {
            if (i === pieceIndex) return false;
            return this.rectsIntersect(
                { x: piece.x, y: piece.y, width: newWidth + kerf, height: newHeight + kerf },
                { x: other.x, y: other.y, width: other.width + kerf, height: other.height + kerf }
            );
        });
        
        if (wouldIntersect) {
            App.showToast('Повёрнутая деталь пересекается с другой!', 'warning');
            return;
        }
        
        // Повернуть
        piece.width = newWidth;
        piece.height = newHeight;
        piece.rotated = !piece.rotated;
        this.selectedPieceIndex = pieceIndex;
        
        // Перерисовать
        this.renderSVG(layout);
        this.updateDimensionsPanel(layout);
        
        App.showToast(`Деталь ${pieceIndex + 1} повёрнута на 90°`, 'info');
    },
    
    /**
     * Проверить пересечение прямоугольников
     */
    rectsIntersect(r1, r2) {
        return !(r1.x + r1.width <= r2.x || 
                 r2.x + r2.width <= r1.x || 
                 r1.y + r1.height <= r2.y || 
                 r2.y + r2.height <= r1.y);
    },
    
    /**
     * Рассчитать остатки (неиспользуемые области)
     */
    calculateWaste(layout) {
        const sheet = this.currentSheet;
        const kerf = this.currentKerf || 0;
        
        // Найти границы занятой области
        let maxX = 0, maxY = 0;
        layout.pieces.forEach(p => {
            maxX = Math.max(maxX, p.x + p.width);
            maxY = Math.max(maxY, p.y + p.height);
        });
        
        const wastes = [];
        
        // Остаток справа
        if (sheet.width - maxX > kerf) {
            wastes.push({
                x: maxX + kerf,
                y: 0,
                width: sheet.width - maxX - kerf,
                height: sheet.height,
                label: `${sheet.width - maxX - kerf}×${sheet.height} мм`
            });
        }
        
        // Остаток снизу
        if (sheet.height - maxY > kerf) {
            wastes.push({
                x: 0,
                y: maxY + kerf,
                width: maxX,
                height: sheet.height - maxY - kerf,
                label: `${maxX}×${sheet.height - maxY - kerf} мм`
            });
        }
        
        return wastes;
    },
    
    /**
     * Обновить информационную панель размеров
     */
    updateDimensionsPanel(layout) {
        const panel = document.getElementById('vcDimensionsPanel');
        if (!panel) return;
        
        const kerf = this.currentKerf || 0;
        const sheet = this.currentSheet;
        const piece = this.currentPiece;
        
        // Обновить размеры листа
        document.getElementById('vcDimSheet').textContent = `${sheet.width} × ${sheet.height} мм`;
        
        // Обновить размер детали
        document.getElementById('vcDimPiece').textContent = `${piece.width} × ${piece.height} мм`;
        
        // Обновить пропил
        document.getElementById('vcDimKerf').textContent = `${kerf} мм`;
        
        // Обновить эффективный размер
        const effectiveW = piece.width + kerf;
        const effectiveH = piece.height + kerf;
        document.getElementById('vcDimEffective').textContent = `${effectiveW} × ${effectiveH} мм`;
        
        // Обновить остатки
        const wastes = this.calculateWaste(layout);
        const wasteList = document.getElementById('vcDimWaste');
        wasteList.innerHTML = '';
        
        if (wastes.length === 0) {
            wasteList.innerHTML = '<li><em>Нет значительных остатков</em></li>';
        } else {
            wastes.forEach(w => {
                const li = document.createElement('li');
                li.textContent = w.label;
                wasteList.appendChild(li);
            });
        }
    },
    
    /**
     * Визуализировать пропилы в SVG
     */
    drawKerfLines(svg, layout) {
        if (!this.currentKerf || this.currentKerf === 0) return;
        
        const kerf = this.currentKerf;
        const scale = this.svgPadding / 30; // Примерный масштаб
        
        layout.pieces.forEach((piece, index) => {
            // Вертикальная линия пропила справа
            if (index % layout.cols < layout.cols - 1) {  // Не на последней колонке
                const kerfLine1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                kerfLine1.setAttribute('x1', piece.x + piece.width);
                kerfLine1.setAttribute('y1', piece.y);
                kerfLine1.setAttribute('x2', piece.x + piece.width);
                kerfLine1.setAttribute('y2', piece.y + piece.height);
                kerfLine1.setAttribute('stroke', '#ff4444');
                kerfLine1.setAttribute('stroke-width', Math.max(0.5, kerf * scale / 10));
                kerfLine1.setAttribute('opacity', 0.6);
                kerfLine1.setAttribute('class', 'kerf-line');
                svg.appendChild(kerfLine1);
            }
            
            // Горизонтальная линия пропила снизу
            if (Math.floor(index / layout.cols) < layout.rows - 1) {  // Не в последней строке
                const kerfLine2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                kerfLine2.setAttribute('x1', piece.x);
                kerfLine2.setAttribute('y1', piece.y + piece.height);
                kerfLine2.setAttribute('x2', piece.x + piece.width);
                kerfLine2.setAttribute('y2', piece.y + piece.height);
                kerfLine2.setAttribute('stroke', '#ff4444');
                kerfLine2.setAttribute('stroke-width', Math.max(0.5, kerf * scale / 10));
                kerfLine2.setAttribute('opacity', 0.6);
                kerfLine2.setAttribute('class', 'kerf-line');
                svg.appendChild(kerfLine2);
            }
        });
    },
    
    /**
     * Визуализировать остатки в SVG
     */
    drawWasteAreas(svg, layout) {
        const wastes = this.calculateWaste(layout);
        
        wastes.forEach(waste => {
            // Прямоугольник остатка
            const wasteRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            wasteRect.setAttribute('x', waste.x);
            wasteRect.setAttribute('y', waste.y);
            wasteRect.setAttribute('width', waste.width);
            wasteRect.setAttribute('height', waste.height);
            wasteRect.setAttribute('fill', '#424242');
            wasteRect.setAttribute('opacity', 0.3);
            wasteRect.setAttribute('stroke', '#888');
            wasteRect.setAttribute('stroke-width', 1);
            wasteRect.setAttribute('stroke-dasharray', '5,5');
            wasteRect.setAttribute('class', 'waste-area');
            svg.appendChild(wasteRect);
            
            // Текст размера остатка
            if (this.showLabels) {
                const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.setAttribute('x', waste.x + waste.width / 2);
                label.setAttribute('y', waste.y + waste.height / 2);
                label.setAttribute('text-anchor', 'middle');
                label.setAttribute('dominant-baseline', 'middle');
                label.setAttribute('fill', '#ff6b6b');
                label.setAttribute('font-size', '14');
                label.setAttribute('font-weight', 'bold');
                label.setAttribute('opacity', 0.7);
                label.setAttribute('pointer-events', 'none');
                label.textContent = waste.label;
                svg.appendChild(label);
            }
        });
    }
};

// Инициализация при загрузке документа
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('vcCalculateBtn')) {
        VisualCuttingCalculator.init();
    }
});
