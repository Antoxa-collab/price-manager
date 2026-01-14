/**
 * Справочник раскроя для различных типов листов
 * Содержит предопределённые выходы деталей из стандартных листов
 */

/**
 * Справочник раскроя для листа "Другой 1400×1030"
 * Данные: выход деталей с 2 листов (1 большой лист 2800×2070 разрезан пополам)
 * 
 * Примечание: Все значения — это количество деталей с ДВУХ листов 1400×1030
 */
const CUTTING_REFERENCE_1400x1030 = {
    sheetName: 'Другой 1400×1030',
    sheetWidth: 1400,
    sheetHeight: 1030,
    description: 'МДФ 2800×2070 (разрезано на 2 части)',
    // Ключ: "ширинахвысота" (в мм)
    // Значение: количество деталей из 2 листов
    layouts: {
        // Размер 500×400 (и обратный вариант 400×500)
        '500x400': 12,
        '400x500': 12,
        
        // Размер 1350×700
        '1350x700': 2,
        '700x1350': 2,
        
        // Размер 600×450
        '600x450': 8,
        '450x600': 8,
        
        // Размер 500×500
        '500x500': 8,
        
        // Размер 750×550
        '750x550': 4,
        '550x750': 4,
        
        // Размер 600×400
        '600x400': 8,
        '400x600': 8,
        
        // Размер А4 (297×210)
        '297x210': 38,
        '210x297': 38,
        'A4': 38,
        'a4': 38,
        
        // Размер 600×600
        '600x600': 4,
        
        // Размер 300×300
        '300x300': 24,
        
        // Размер 600×500
        '600x500': 8,
        '500x600': 8,
        
        // Размер 400×300
        '400x300': 18,
        '300x400': 18,
        
        // Размер 350×250
        '350x250': 32,
        '250x350': 32,
        
        // Размер А3 (420×297)
        '420x297': 18,
        '297x420': 18,
        'A3': 18,
        'a3': 18,
        
        // Размер 800×600
        '800x600': 4,
        '600x800': 4,
        
        // Размер 700×500
        '700x500': 6,
        '500x700': 6,
        
        // Размер 750×600
        '750x600': 4,
        '600x750': 4
    }
};

/**
 * Справочники раскроя — массив всех известных справочников
 */
const CUTTING_REFERENCES = [
    CUTTING_REFERENCE_1400x1030
    // Сюда можно добавить другие справочники при необходимости
];

/**
 * Получить количество деталей из справочника раскроя
 * 
 * @param {string} sheetType - тип/название листа (например "Другой 1400×1030" или "1400x1030")
 * @param {number} width - ширина детали (мм)
 * @param {number} height - высота детали (мм)
 * @returns {number|null} - количество деталей или null если не найдено в справочнике
 * 
 * @example
 * // Получить количество деталей 500×400 из листа 1400×1030
 * getPiecesFromReference('Другой 1400×1030', 500, 400); // вернёт 12
 * 
 * @example
 * // Также работает с обратным вариантом (400×500)
 * getPiecesFromReference('1400x1030', 400, 500); // вернёт 12
 */
function getPiecesFromReference(sheetType, width, height) {
    if (!sheetType || !width || !height) {
        return null;
    }
    
    // Нормализовать строку для поиска
    const normalizedSheetType = String(sheetType).toLowerCase().trim();
    
    // Найти подходящий справочник
    for (const reference of CUTTING_REFERENCES) {
        // Проверить различные варианты названия листа
        const sheetMatches = 
            normalizedSheetType.includes('1400') && normalizedSheetType.includes('1030') ||
            normalizedSheetType.includes('другой') && normalizedSheetType.includes('1400') ||
            normalizedSheetType === 'другой 1400×1030' ||
            normalizedSheetType === '1400x1030' ||
            normalizedSheetType === '1400×1030';
        
        if (!sheetMatches) {
            continue;
        }
        
        // Попробовать найти размер в справочнике (оба варианта: ширина×высота и высота×ширина)
        const key1 = `${width}x${height}`;
        const key2 = `${height}x${width}`;
        const keyA4 = width === 297 && height === 210 ? 'A4' : null;
        const keyA3 = width === 420 && height === 297 ? 'A3' : null;
        
        if (reference.layouts[key1]) {
            console.log(`[CuttingReference] Найдено в справочнике "${reference.sheetName}": ${key1} = ${reference.layouts[key1]}`);
            return reference.layouts[key1];
        }
        
        if (reference.layouts[key2]) {
            console.log(`[CuttingReference] Найдено в справочнике "${reference.sheetName}": ${key2} = ${reference.layouts[key2]}`);
            return reference.layouts[key2];
        }
        
        if (keyA4 && reference.layouts[keyA4]) {
            console.log(`[CuttingReference] Найдено в справочнике "${reference.sheetName}": A4 = ${reference.layouts[keyA4]}`);
            return reference.layouts[keyA4];
        }
        
        if (keyA3 && reference.layouts[keyA3]) {
            console.log(`[CuttingReference] Найдено в справочнике "${reference.sheetName}": A3 = ${reference.layouts[keyA3]}`);
            return reference.layouts[keyA3];
        }
    }
    
    return null;
}

/**
 * Парсинг размеров детали из названия артикула
 * 
 * @param {string} articleName - название артикула
 * @returns {object|null} - объект {width, height, thickness?} или null если размер не найден
 * 
 * @example
 * // Парсинг форматов
 * parseArticleDimensions("МДФ 8x750x550, фанера"); // {width: 750, height: 550, thickness: 8}
 * parseArticleDimensions("Листы 600x400, шлифованная"); // {width: 600, height: 400}
 * parseArticleDimensions("Бумага А4 Монди"); // {width: 297, height: 210}
 */
function parseArticleDimensions(articleName) {
    if (!articleName) {
        return null;
    }
    
    const name = String(articleName);
    
    // Паттерны для поиска размеров (в порядке приоритета)
    const patterns = [
        // Формат: ЧИСЛОxЧИСЛОxЧИСЛО (толщина×ширина×высота)
        {
            regex: /(\d+)[xх×](\d+)[xх×](\d+)/i,
            parse: (match) => ({
                thickness: parseInt(match[1]),
                width: parseInt(match[2]),
                height: parseInt(match[3])
            })
        },
        // Формат: ЧИСЛОxЧИСЛО (ширина×высота)
        {
            regex: /(\d+)[xх×](\d+)/i,
            parse: (match) => ({
                width: parseInt(match[1]),
                height: parseInt(match[2])
            })
        }
    ];
    
    // Попробовать каждый паттерн
    for (const { regex, parse } of patterns) {
        const match = name.match(regex);
        if (match) {
            return parse(match);
        }
    }
    
    // Проверить стандартные форматы бумаги
    if (/\bА4\b|A4\b/i.test(name)) {
        return { width: 297, height: 210 };
    }
    if (/\bА3\b|A3\b/i.test(name)) {
        return { width: 420, height: 297 };
    }
    if (/\bА2\b|A2\b/i.test(name)) {
        return { width: 594, height: 420 };
    }
    if (/\bА1\b|A1\b/i.test(name)) {
        return { width: 841, height: 594 };
    }
    if (/\bА0\b|A0\b/i.test(name)) {
        return { width: 1189, height: 841 };
    }
    
    return null;
}

/**
 * Автозаполнение pieces_per_sheet из справочника
 * Используется в методе autoFillPieces() калькуляторов
 * 
 * @param {string} sheetType - тип листа
 * @param {string} articleName - название артикула
 * @returns {number|null} - количество деталей или null
 * 
 * @example
 * // В методе калькулятора:
 * const pieces = autoFillPiecesFromReference(selectedSheet, article.name);
 * if (pieces) {
 *     article.pieces_per_sheet = pieces;
 * }
 */
function autoFillPiecesFromReference(sheetType, articleName) {
    // Парсить размер из названия артикула
    const dimensions = parseArticleDimensions(articleName);
    if (!dimensions) {
        console.log('[autoFillPiecesFromReference] Размер не найден в артикуле:', articleName);
        return null;
    }
    
    const { width, height } = dimensions;
    
    // Получить количество из справочника
    const pieces = getPiecesFromReference(sheetType, width, height);
    
    if (pieces) {
        console.log(`[autoFillPiecesFromReference] ${articleName} (${width}×${height}) → ${pieces} шт из листа "${sheetType}"`);
    }
    
    return pieces;
}

/**
 * Получить список всех доступных размеров в справочнике
 * Полезно для отладки и документации
 * 
 * @param {number} referenceIndex - индекс справочника (0 = первый)
 * @returns {array} - массив размеров
 */
function getCuttingReferenceSizes(referenceIndex = 0) {
    if (referenceIndex >= CUTTING_REFERENCES.length) {
        return [];
    }
    
    const reference = CUTTING_REFERENCES[referenceIndex];
    return Object.keys(reference.layouts)
        .filter(key => !['A3', 'A4', 'a3', 'a4'].includes(key)) // исключить дубли
        .map(key => ({
            size: key,
            count: reference.layouts[key]
        }))
        .sort((a, b) => b.count - a.count);
}

/**
 * Вывести справочник в консоль (для отладки)
 * 
 * @example
 * // В браузере (консоль F12):
 * logCuttingReference();
 */
function logCuttingReference(referenceIndex = 0) {
    if (referenceIndex >= CUTTING_REFERENCES.length) {
        console.error('Справочник не найден');
        return;
    }
    
    const reference = CUTTING_REFERENCES[referenceIndex];
    console.group(`📋 Справочник раскроя: ${reference.sheetName}`);
    console.log(`Размер листа: ${reference.sheetWidth}×${reference.sheetHeight} мм`);
    console.log(`Описание: ${reference.description}`);
    console.log('Доступные размеры:');
    
    const sizes = getCuttingReferenceSizes(referenceIndex);
    sizes.forEach(({ size, count }) => {
        console.log(`  ${size}: ${count} шт`);
    });
    
    console.groupEnd();
}

// Экспорт для использования в других скриптах (если нужно)
// window.CuttingReference = {
//     getPiecesFromReference,
//     parseArticleDimensions,
//     autoFillPiecesFromReference,
//     getCuttingReferenceSizes,
//     logCuttingReference,
//     CUTTING_REFERENCE_1400x1030,
//     CUTTING_REFERENCES
// };
