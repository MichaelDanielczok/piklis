<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function round;
use function strtolower;
use function trim;

/**
 * Nährwerte aus `nutriments` von Open Food Facts (API v3.4, Schema bis
 * 1002), in der Reihenfolge der EU-Nährwerttabelle. Nur angegebene Werte:
 * Fehlendes bleibt fehlend, nichts wird umgerechnet oder geschätzt
 * (`nutriments_estimated` wird nicht gelesen).
 *
 * Ergebnis:
 * ```
 * {
 *   per: 'g' | 'ml',             // Bezug der Spalte „je 100 g“ bzw. „je 100 ml“
 *   servingSize: string | null,  // „1 portion (330 ml)“, nur mit Portionswerten
 *   rows: [{ key, unit, per100, perServing }]
 * }
 * ```
 * Ohne einen einzigen Wert: null.
 */
final class Nutrition
{
    /**
     * Schlüssel bei Open Food Facts → Einheit. `energy-kj` fällt auf `energy`
     * zurück (bei Open Food Facts immer kJ).
     */
    public const ROWS = [
        'energy-kj' => 'kJ',
        'energy-kcal' => 'kcal',
        'fat' => 'g',
        'saturated-fat' => 'g',
        'carbohydrates' => 'g',
        'sugars' => 'g',
        'fiber' => 'g',
        'proteins' => 'g',
        'salt' => 'g'
    ];

    private const PRECISION = 3;

    /**
     * @param array<string, mixed> $product Produkt aus der API-Antwort
     * @return array{per: string, servingSize: string|null, rows: list<array{key: string, unit: string, per100: float|null, perServing: float|null}>}|null
     */
    public static function fromApi(array $product): ?array
    {
        if (($product['no_nutrition_data'] ?? null) === 'on') {
            return null;
        }

        $nutriments = $product['nutriments'] ?? null;

        if (!is_array($nutriments)) {
            return null;
        }

        $rows = [];
        $hasServing = false;

        foreach (self::ROWS as $key => $unit) {
            $per100 = self::value($nutriments, $key, '_100g');
            $perServing = self::value($nutriments, $key, '_serving');

            if ($per100 === null && $perServing === null) {
                continue;
            }

            $hasServing = $hasServing || $perServing !== null;

            $rows[] = [
                'key' => $key,
                'unit' => $unit,
                'per100' => $per100,
                'perServing' => $perServing
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'per' => self::per($product),
            'servingSize' => $hasServing ? self::servingSize($product) : null,
            'rows' => $rows
        ];
    }

    /**
     * „ml“, wenn Open Food Facts die Werte je 100 ml führt oder das Produkt in
     * ml abgefüllt ist (Getränke stehen dort teils als „100g“); sonst „g“.
     *
     * @param array<string, mixed> $product
     */
    public static function per(array $product): string
    {
        $per = is_string($product['nutrition_data_per'] ?? null) ? strtolower($product['nutrition_data_per']) : '';
        $unit = is_string($product['product_quantity_unit'] ?? null) ? strtolower($product['product_quantity_unit']) : '';

        if ($per === '100ml' || in_array($unit, ['ml', 'cl', 'l'], true)) {
            return 'ml';
        }

        return 'g';
    }

    /**
     * @param array<array-key, mixed> $nutriments
     */
    private static function value(array $nutriments, string $key, string $suffix): ?float
    {
        $value = $nutriments[$key . $suffix] ?? null;

        if ($value === null && $key === 'energy-kj') {
            $value = $nutriments['energy' . $suffix] ?? null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float)$value;

        if ($number < 0) {
            return null;
        }

        return round($number, self::PRECISION);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function servingSize(array $product): ?string
    {
        $size = is_string($product['serving_size'] ?? null) ? trim($product['serving_size']) : '';

        return $size === '' ? null : $size;
    }
}
