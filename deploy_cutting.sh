#!/bin/bash
# Деплой справочника раскроя на сервер
# Запускать на сервере: bash deploy_cutting.sh

set -e

PROJECT_DIR="/home/anton/price-manager"
MYSQL_CONTAINER="price-manager-mysql"
MYSQL_USER="price_user"
MYSQL_PASS="price_password"
MYSQL_DB="price_manager"

echo "=== Деплой справочника раскроя листов ==="

# Проверяем что таблицы ещё не созданы
echo ""
echo "1. Проверяем существование таблиц..."
TABLES=$(docker exec $MYSQL_CONTAINER mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -N -e "SHOW TABLES LIKE 'cutting%';" 2>/dev/null || echo "")

if [ -n "$TABLES" ]; then
    echo "   Таблицы уже существуют:"
    echo "$TABLES"
else
    echo "   Таблицы не найдены, создаём..."

    # Создаём таблицы
    docker exec -i $MYSQL_CONTAINER mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB << 'EOF'
-- Таблица исходных листов
CREATE TABLE IF NOT EXISTS cutting_sheets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    material_type VARCHAR(50) NOT NULL COMMENT 'Тип материала: fanera_fk, fanera_fsf, mdf, osb...',
    material_name VARCHAR(100) NOT NULL COMMENT 'Название для отображения',
    sheet_width INT UNSIGNED NOT NULL COMMENT 'Ширина листа (мм)',
    sheet_height INT UNSIGNED NOT NULL COMMENT 'Высота листа (мм)',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_material (material_type),
    UNIQUE KEY uk_user_sheet (user_id, material_type, sheet_width, sheet_height)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Исходные листы для раскроя';

-- Таблица раскроя (соотношение лист → кусочек → количество)
CREATE TABLE IF NOT EXISTS cutting_pieces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sheet_id INT UNSIGNED NOT NULL COMMENT 'FK на cutting_sheets',
    piece_name VARCHAR(50) DEFAULT NULL COMMENT 'Название размера: A4, A3, 500x500...',
    piece_width INT UNSIGNED NOT NULL COMMENT 'Ширина кусочка (мм)',
    piece_height INT UNSIGNED NOT NULL COMMENT 'Высота кусочка (мм)',
    calculated_qty INT UNSIGNED DEFAULT NULL COMMENT 'Авто-расчёт количества',
    actual_qty INT UNSIGNED NOT NULL COMMENT 'Фактическое количество (можно изменить)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sheet (sheet_id),
    INDEX idx_piece_size (piece_width, piece_height),
    UNIQUE KEY uk_sheet_piece (sheet_id, piece_width, piece_height),
    FOREIGN KEY (sheet_id) REFERENCES cutting_sheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Раскрой: сколько кусочков из листа';

-- Заполняем типовые листы для user_id=1
INSERT INTO cutting_sheets (user_id, material_type, material_name, sheet_width, sheet_height) VALUES
(1, 'fanera_fk', 'Фанера ФК', 1520, 1520),
(1, 'fanera_fsf', 'Фанера ФСФ', 2440, 1220),
(1, 'fanera_fsf_lam', 'Фанера ФСФ ламинированная', 2500, 1250),
(1, 'fanera_setch', 'Фанера сетчатая', 2500, 1250),
(1, 'osb', 'OSB (ОСБ)', 2500, 1250),
(1, 'mdf', 'МДФ', 2800, 2070),
(1, 'lmdf', 'ЛМДФ', 2800, 2070),
(1, 'dvp', 'ДВП', 2745, 1700)
ON DUPLICATE KEY UPDATE material_name = VALUES(material_name);
EOF

    echo "   Таблицы созданы!"
fi

# Проверяем результат
echo ""
echo "2. Проверяем результат..."
docker exec $MYSQL_CONTAINER mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -e "SELECT COUNT(*) as sheets FROM cutting_sheets; SELECT COUNT(*) as pieces FROM cutting_pieces;"

echo ""
echo "=== Готово! ==="
echo "Перезагрузите страницу калькулятора Ozon"
