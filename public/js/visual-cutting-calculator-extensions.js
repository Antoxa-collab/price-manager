/**
 * Примеры интеграции визуального калькулятора раскроя с бэкендом
 * Это файл для разработчиков, содержит примеры API и расширений
 */

// ==================== РАСШИРЕНИЕ 1: API Интеграция ====================

/**
 * Добавить метод для сохранения расчётов в справочник
 * Вставить в объект VisualCuttingCalculator
 */
class VisualCuttingCalculatorAPI extends VisualCuttingCalculator {
    /**
     * Сохранить расчёт в справочник раскроя
     */
    async applyToReferenceAPI() {
        if (!this.layouts.length || !this.currentSheet || !this.currentPiece) {
            App.showToast('Выполните расчёт раскроя', 'warning');
            return;
        }
        
        const layout = this.layouts[this.selectedLayoutIndex];
        
        try {
            const response = await fetch('/api/cutting-reference/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    sheet_type: this.currentSheet.name,
                    sheet_width: this.currentSheet.width,
                    sheet_height: this.currentSheet.height,
                    piece_width: this.currentPiece.width,
                    piece_height: this.currentPiece.height,
                    pieces_per_sheet: layout.count,
                    usage_percent: layout.usagePercent,
                    layout_type: layout.type
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                App.showToast(`✅ Добавлено в справочник: ${layout.count} шт`, 'success');
                return data;
            } else {
                App.showToast(`⚠️ ${data.message}`, 'warning');
            }
        } catch (error) {
            console.error('API Error:', error);
            App.showToast('❌ Ошибка сохранения', 'danger');
        }
    }
}

// ==================== РАСШИРЕНИЕ 2: Сохранение истории ====================

/**
 * Класс для управления историей расчётов
 */
class CuttingCalculationHistory {
    constructor() {
        this.storageKey = 'vc_calculation_history';
        this.maxRecords = 50;
        this.load();
    }
    
    /**
     * Добавить расчёт в историю
     */
    add(sheet, piece, layout) {
        const record = {
            id: Date.now(),
            timestamp: new Date().toISOString(),
            sheet: sheet,
            piece: piece,
            layout: layout,
            count: layout.count,
            usage: layout.usagePercent
        };
        
        this.history.unshift(record);
        
        // Ограничить размер истории
        if (this.history.length > this.maxRecords) {
            this.history = this.history.slice(0, this.maxRecords);
        }
        
        this.save();
        return record;
    }
    
    /**
     * Получить всю историю
     */
    getAll() {
        return this.history;
    }
    
    /**
     * Получить расчёт по ID
     */
    getById(id) {
        return this.history.find(r => r.id === id);
    }
    
    /**
     * Поиск похожих расчётов
     */
    findSimilar(sheetName, pieceW, pieceH) {
        return this.history.filter(r => 
            r.sheet.name === sheetName &&
            r.piece.width === pieceW &&
            r.piece.height === pieceH
        );
    }
    
    /**
     * Удалить расчёт
     */
    remove(id) {
        this.history = this.history.filter(r => r.id !== id);
        this.save();
    }
    
    /**
     * Очистить всю историю
     */
    clear() {
        this.history = [];
        this.save();
    }
    
    /**
     * Сохранить в localStorage
     */
    save() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(this.history));
        } catch (e) {
            console.warn('LocalStorage full, clearing old records...');
            this.history = this.history.slice(0, 10);
            localStorage.setItem(this.storageKey, JSON.stringify(this.history));
        }
    }
    
    /**
     * Загрузить из localStorage
     */
    load() {
        try {
            const data = localStorage.getItem(this.storageKey);
            this.history = data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('Error loading history:', e);
            this.history = [];
        }
    }
}

// ==================== РАСШИРЕНИЕ 3: Параметры по умолчанию ====================

/**
 * Загрузить предыдущие параметры пользователя
 */
VisualCuttingCalculator.loadUserPreferences = function() {
    try {
        const prefs = localStorage.getItem('vc_preferences');
        if (prefs) {
            const data = JSON.parse(prefs);
            
            if (data.lastSheet) {
                document.getElementById('vcSheetSelect').value = data.lastSheet;
            }
            if (data.lastWidth) {
                document.getElementById('vcPieceWidth').value = data.lastWidth;
            }
            if (data.lastHeight) {
                document.getElementById('vcPieceHeight').value = data.lastHeight;
            }
            
            console.log('User preferences loaded:', data);
        }
    } catch (e) {
        console.warn('Error loading preferences:', e);
    }
};

/**
 * Сохранить параметры пользователя
 */
VisualCuttingCalculator.saveUserPreferences = function() {
    try {
        const prefs = {
            lastSheet: document.getElementById('vcSheetSelect').value,
            lastWidth: document.getElementById('vcPieceWidth').value,
            lastHeight: document.getElementById('vcPieceHeight').value,
            timestamp: new Date().toISOString()
        };
        
        localStorage.setItem('vc_preferences', JSON.stringify(prefs));
        console.log('User preferences saved');
    } catch (e) {
        console.warn('Error saving preferences:', e);
    }
};

// ==================== РАСШИРЕНИЕ 4: Статистика ====================

/**
 * Класс для сбора статистики использования
 */
class CuttingCalculatorStats {
    constructor() {
        this.storageKey = 'vc_stats';
        this.load();
    }
    
    /**
     * Зарегистрировать расчёт
     */
    recordCalculation(sheet, piece, layout) {
        this.stats.totalCalculations++;
        this.stats.totalSheets++;
        this.stats.averageUsage = (
            (this.stats.averageUsage * (this.stats.totalCalculations - 1)) +
            parseFloat(layout.usagePercent)
        ) / this.stats.totalCalculations;
        
        // Статистика по листам
        const sheetKey = `${sheet.name}_${sheet.width}x${sheet.height}`;
        if (!this.stats.bySheet[sheetKey]) {
            this.stats.bySheet[sheetKey] = {
                count: 0,
                avgUsage: 0,
                bestUsage: 0
            };
        }
        
        const sheetStats = this.stats.bySheet[sheetKey];
        sheetStats.count++;
        sheetStats.avgUsage = (sheetStats.avgUsage * (sheetStats.count - 1) + parseFloat(layout.usagePercent)) / sheetStats.count;
        sheetStats.bestUsage = Math.max(sheetStats.bestUsage, parseFloat(layout.usagePercent));
        
        this.stats.lastCalculation = new Date().toISOString();
        this.save();
    }
    
    /**
     * Получить статистику
     */
    getStats() {
        return this.stats;
    }
    
    /**
     * Получить лучшую схему раскроя
     */
    getBestSheet() {
        let best = null;
        let bestUsage = 0;
        
        for (const [key, data] of Object.entries(this.stats.bySheet)) {
            if (data.bestUsage > bestUsage) {
                bestUsage = data.bestUsage;
                best = key;
            }
        }
        
        return best;
    }
    
    /**
     * Сохранить статистику
     */
    save() {
        localStorage.setItem(this.storageKey, JSON.stringify(this.stats));
    }
    
    /**
     * Загрузить статистику
     */
    load() {
        try {
            const data = localStorage.getItem(this.storageKey);
            this.stats = data ? JSON.parse(data) : {
                totalCalculations: 0,
                totalSheets: 0,
                averageUsage: 0,
                bySheet: {},
                lastCalculation: null
            };
        } catch (e) {
            console.error('Error loading stats:', e);
            this.stats = {
                totalCalculations: 0,
                totalSheets: 0,
                averageUsage: 0,
                bySheet: {},
                lastCalculation: null
            };
        }
    }
    
    /**
     * Очистить статистику
     */
    clear() {
        this.stats = {
            totalCalculations: 0,
            totalSheets: 0,
            averageUsage: 0,
            bySheet: {},
            lastCalculation: null
        };
        this.save();
    }
}

// ==================== РАСШИРЕНИЕ 5: Рекомендации ====================

/**
 * Класс для генерации рекомендаций по оптимизации
 */
class CuttingRecommender {
    /**
     * Анализировать расчёт и дать рекомендации
     */
    static analyzeLayout(sheet, piece, layout) {
        const recommendations = [];
        const usagePercent = parseFloat(layout.usagePercent);
        
        // Рекомендация 1: Низкое использование
        if (usagePercent < 70) {
            recommendations.push({
                severity: 'high',
                type: 'low_usage',
                message: `⚠️ Использование только ${usagePercent}%. Попробуйте другой лист или другой размер детали.`,
                action: 'try_different_sheet'
            });
        }
        
        // Рекомендация 2: Хорошее использование
        if (usagePercent >= 90) {
            recommendations.push({
                severity: 'info',
                type: 'optimal',
                message: `✅ Отличное использование (${usagePercent}%)! Эту схему можно добавить в справочник.`,
                action: 'save_to_reference'
            });
        }
        
        // Рекомендация 3: Остаток материала
        const wastePercent = 100 - usagePercent;
        if (wastePercent > 15) {
            recommendations.push({
                severity: 'medium',
                type: 'waste',
                message: `ℹ️ Остаток ${wastePercent}%. Можно использовать для других деталей.`,
                action: 'analyze_waste'
            });
        }
        
        // Рекомендация 4: Поворот детали
        if (piece.width !== piece.height) {
            recommendations.push({
                severity: 'info',
                type: 'rotation_hint',
                message: `💡 Попробуйте повернуть деталь на 90°`,
                action: 'rotate_pattern'
            });
        }
        
        return recommendations;
    }
    
    /**
     * Найти лучший лист для детали
     */
    static findBestSheet(allSheets, piece) {
        let bestSheet = null;
        let bestUsage = 0;
        
        for (const sheet of allSheets) {
            // Расчитать использование для этого листа
            const cols = Math.floor(sheet.width / piece.width);
            const rows = Math.floor(sheet.height / piece.height);
            const count = cols * rows;
            
            if (count > 0) {
                const usedArea = count * piece.width * piece.height;
                const totalArea = sheet.width * sheet.height;
                const usage = (usedArea / totalArea) * 100;
                
                if (usage > bestUsage) {
                    bestUsage = usage;
                    bestSheet = {
                        sheet: sheet,
                        count: count,
                        usage: usage.toFixed(1)
                    };
                }
            }
        }
        
        return bestSheet;
    }
}

// ==================== РАСШИРЕНИЕ 6: Печать ====================

/**
 * Расширенная функция печати
 */
VisualCuttingCalculator.printLayout = function(layout) {
    const printWindow = window.open('', '', 'height=600,width=800');
    
    const svgString = new XMLSerializer().serializeToString(document.getElementById('vcSvgSheet'));
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Раскрой листа</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h2 { color: #333; }
                .info { margin: 20px 0; border-top: 1px solid #ccc; padding-top: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
                th { background-color: #f0f0f0; }
                svg { max-width: 100%; height: auto; border: 1px solid #ccc; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <h2>Расчёт раскроя листа</h2>
            
            <div class="info">
                <strong>Лист:</strong> ${this.currentSheet.name} (${this.currentSheet.width}×${this.currentSheet.height} мм)<br>
                <strong>Деталь:</strong> ${this.currentPiece.width}×${this.currentPiece.height} мм<br>
                <strong>На листе:</strong> ${layout.count} шт<br>
                <strong>Использование:</strong> ${layout.usagePercent}%
            </div>
            
            <h3>Схема раскроя:</h3>
            ${svgString}
            
            <div class="footer">
                <p>Дата: ${new Date().toLocaleString('ru-RU')}</p>
                <p>Пользователь: ${App.user?.name || 'Неизвестно'}</p>
                <p><em>Расчёты выполнены в Price Manager</em></p>
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.print();
};

// ==================== ИНИЦИАЛИЗАЦИЯ РАСШИРЕНИЙ ====================

/**
 * Инициализировать все расширения после загрузки калькулятора
 */
document.addEventListener('DOMContentLoaded', () => {
    // Инициализировать основной калькулятор
    VisualCuttingCalculator.init();
    
    // Загрузить сохранённые предпочтения пользователя
    VisualCuttingCalculator.loadUserPreferences();
    
    // Инициализировать историю и статистику
    window.cuttingHistory = new CuttingCalculationHistory();
    window.cuttingStats = new CuttingCalculatorStats();
    
    // Добавить хук сохранения параметров после каждого расчёта
    const originalCalculate = VisualCuttingCalculator.calculate;
    VisualCuttingCalculator.calculate = function() {
        originalCalculate.call(this);
        VisualCuttingCalculator.saveUserPreferences();
        
        // Записать в историю
        if (this.layouts.length > 0) {
            cuttingHistory.add(this.currentSheet, this.currentPiece, this.layouts[0]);
            cuttingStats.recordCalculation(this.currentSheet, this.currentPiece, this.layouts[0]);
        }
    };
    
    // Логирование в консоль
    console.log('VisualCuttingCalculator Extensions loaded');
    console.log('History:', cuttingHistory);
    console.log('Stats:', cuttingStats);
    console.log('Recommender:', CuttingRecommender);
});

// ==================== ПРИМЕРЫ ИСПОЛЬЗОВАНИЯ ====================

/**
 * Примеры кода для использования расширений:
 */

/*
// Получить историю расчётов
const history = cuttingHistory.getAll();
console.log('История:', history);

// Найти похожие расчёты
const similar = cuttingHistory.findSimilar('Фанера ФК', 600, 900);
console.log('Похожие расчёты:', similar);

// Получить статистику
const stats = cuttingStats.getStats();
console.log('Статистика:', stats);

// Найти лучший лист
const sheets = [
    { name: 'Фанера ФК', width: 1520, height: 1520 },
    { name: 'МДФ', width: 2800, height: 2070 }
];
const best = CuttingRecommender.findBestSheet(sheets, { width: 600, height: 900 });
console.log('Лучший лист:', best);

// Получить рекомендации
const recommendations = CuttingRecommender.analyzeLayout(
    VisualCuttingCalculator.currentSheet,
    VisualCuttingCalculator.currentPiece,
    VisualCuttingCalculator.layouts[0]
);
console.log('Рекомендации:', recommendations);

// Печать
VisualCuttingCalculator.printLayout(VisualCuttingCalculator.layouts[0]);
*/

// ==================== ЭКСПОРТ МОДУЛЕЙ ====================

// Экспортировать для использования в других модулях
window.CuttingCalculatorExtensions = {
    History: CuttingCalculationHistory,
    Stats: CuttingCalculatorStats,
    Recommender: CuttingRecommender,
    API: VisualCuttingCalculatorAPI
};

export { CuttingCalculationHistory, CuttingCalculatorStats, CuttingRecommender };
