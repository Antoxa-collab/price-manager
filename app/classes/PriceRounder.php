<?php
/**
 * Класс округления цен до "красивых" значений
 * Использует психологическое ценообразование
 */
class PriceRounder
{
    /**
     * Правила округления для разных диапазонов цен
     * Формат: [min, max, targets, step]
     */
    private array $rules = [
        // До 100 руб: округлять до 9 (89, 99)
        ['min' => 0, 'max' => 100, 'targets' => [9], 'step' => 10],

        // 100-500 руб: округлять до 49, 99 (149, 199, 249, 299, 349, 399, 449, 499)
        ['min' => 100, 'max' => 500, 'targets' => [49, 99], 'step' => 50],

        // 500-1000 руб: округлять до 99 (599, 699, 799, 899, 999)
        ['min' => 500, 'max' => 1000, 'targets' => [99], 'step' => 100],

        // 1000-5000 руб: округлять до 99, 499, 999 (1499, 1999, 2499, 2999)
        ['min' => 1000, 'max' => 5000, 'targets' => [99, 499, 999], 'step' => 500],

        // 5000-10000 руб: округлять до 999 (5999, 6999, 7999)
        ['min' => 5000, 'max' => 10000, 'targets' => [999], 'step' => 1000],

        // Более 10000 руб: округлять до 999 (10999, 11999, 12999)
        ['min' => 10000, 'max' => PHP_FLOAT_MAX, 'targets' => [999], 'step' => 1000],
    ];

    /**
     * Округление цены до "красивого" значения
     * @param float $price Исходная цена
     * @return float Округлённая цена
     */
    public function round(float $price): float
    {
        if ($price <= 0) {
            return 0;
        }

        // Находим подходящее правило
        $rule = $this->findRule($price);

        if (!$rule) {
            return $price;
        }

        return $this->applyRule($price, $rule);
    }

    /**
     * Поиск правила для данной цены
     * @param float $price Цена
     * @return array|null Правило или null
     */
    private function findRule(float $price): ?array
    {
        foreach ($this->rules as $rule) {
            if ($price >= $rule['min'] && $price < $rule['max']) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Применение правила округления
     * @param float $price Исходная цена
     * @param array $rule Правило
     * @return float Округлённая цена
     */
    private function applyRule(float $price, array $rule): float
    {
        $targets = $rule['targets'];
        $step = $rule['step'];

        // Находим базу (тысячи, сотни и т.д.)
        $base = floor($price / $step) * $step;

        // Ищем ближайшую целевую цену
        $bestPrice = $price;
        $minDiff = PHP_FLOAT_MAX;

        foreach ($targets as $target) {
            // Пробуем текущую базу
            $candidate = $base + $target;
            $diff = abs($candidate - $price);

            if ($diff < $minDiff && $candidate >= $price * 0.95) { // Не уменьшаем более чем на 5%
                $minDiff = $diff;
                $bestPrice = $candidate;
            }

            // Пробуем следующую базу
            $candidate = $base + $step + $target;
            $diff = abs($candidate - $price);

            if ($diff < $minDiff) {
                $minDiff = $diff;
                $bestPrice = $candidate;
            }
        }

        return $bestPrice;
    }

    /**
     * Округление с выбором направления
     * @param float $price Исходная цена
     * @param string $direction Направление: 'up' - вверх, 'down' - вниз, 'nearest' - ближайшее
     * @return float Округлённая цена
     */
    public function roundWithDirection(float $price, string $direction = 'nearest'): float
    {
        if ($price <= 0) {
            return 0;
        }

        $rule = $this->findRule($price);

        if (!$rule) {
            return $price;
        }

        $targets = $rule['targets'];
        $step = $rule['step'];
        $base = floor($price / $step) * $step;

        $candidates = [];

        foreach ($targets as $target) {
            $candidates[] = $base + $target;
            $candidates[] = $base + $step + $target;
            if ($base >= $step) {
                $candidates[] = $base - $step + $target;
            }
        }

        // Фильтруем по направлению
        switch ($direction) {
            case 'up':
                $candidates = array_filter($candidates, fn($c) => $c >= $price);
                break;
            case 'down':
                $candidates = array_filter($candidates, fn($c) => $c <= $price);
                break;
        }

        if (empty($candidates)) {
            return $price;
        }

        // Находим ближайшую
        usort($candidates, fn($a, $b) => abs($a - $price) <=> abs($b - $price));

        return $candidates[0];
    }

    /**
     * Получение всех возможных "красивых" цен в диапазоне
     * @param float $minPrice Минимальная цена
     * @param float $maxPrice Максимальная цена
     * @return array Массив красивых цен
     */
    public function getPrettyPricesInRange(float $minPrice, float $maxPrice): array
    {
        $prices = [];

        foreach ($this->rules as $rule) {
            $start = max($rule['min'], $minPrice);
            $end = min($rule['max'], $maxPrice);

            if ($start >= $end) {
                continue;
            }

            $base = floor($start / $rule['step']) * $rule['step'];

            while ($base < $end) {
                foreach ($rule['targets'] as $target) {
                    $price = $base + $target;
                    if ($price >= $start && $price <= $end) {
                        $prices[] = $price;
                    }
                }
                $base += $rule['step'];
            }
        }

        sort($prices);
        return array_unique($prices);
    }

    /**
     * Проверка, является ли цена "красивой"
     * @param float $price Цена для проверки
     * @return bool
     */
    public function isPrettyPrice(float $price): bool
    {
        $rule = $this->findRule($price);

        if (!$rule) {
            return false;
        }

        $base = floor($price / $rule['step']) * $rule['step'];
        $remainder = $price - $base;

        return in_array($remainder, $rule['targets']);
    }

    /**
     * Получение информации об округлении
     * @param float $price Исходная цена
     * @return array Информация об округлении
     */
    public function getRoundingInfo(float $price): array
    {
        $rounded = $this->round($price);
        $difference = $rounded - $price;
        $percentChange = $price > 0 ? ($difference / $price) * 100 : 0;

        return [
            'original' => $price,
            'rounded' => $rounded,
            'difference' => $difference,
            'percent_change' => round($percentChange, 2),
            'is_increase' => $difference > 0,
            'is_pretty' => $this->isPrettyPrice($rounded)
        ];
    }
}
