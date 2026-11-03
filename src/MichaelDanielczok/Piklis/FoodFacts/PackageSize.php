<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use function fmod;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function number_format;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Packungsgröße aus Open Food Facts in der Schreibweise der App („1 l“,
 * „1,5 l“, „500 g“, „330 ml“). Bevorzugt die erkannte Menge
 * (`product_quantity` + `product_quantity_unit`), sonst der Text aus
 * `quantity` (ohne das ℮-Zeichen „e“, „pcs“ als „Stück“). Ohne Angabe: null.
 */
final class PackageSize
{
    public const MAX_LENGTH = 50;

    /**
     * @param array<string, mixed> $product
     */
    public static function fromApi(array $product): ?string
    {
        $fromAmount = self::fromAmount($product['product_quantity'] ?? null, $product['product_quantity_unit'] ?? null);

        if ($fromAmount !== null) {
            return $fromAmount;
        }

        $text = is_string($product['quantity'] ?? null) ? $product['quantity'] : '';

        return self::fromText($text);
    }

    private static function fromAmount(mixed $amount, mixed $unit): ?string
    {
        if (!is_numeric($amount) || !is_string($unit)) {
            return null;
        }

        $value = (float)$amount;
        $unit = strtolower(trim($unit));

        if ($value <= 0) {
            return null;
        }

        if ($unit === 'ml' || $unit === 'g') {
            if ($value >= 1000) {
                return self::number($value / 1000) . ' ' . ($unit === 'ml' ? 'l' : 'kg');
            }

            return self::number($value) . ' ' . $unit;
        }

        if ($unit === 'l' || $unit === 'kg') {
            return self::number($value) . ' ' . $unit;
        }

        return null;
    }

    private static function fromText(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // ℮ (Nennfüllmenge) steht bei Open Food Facts oft als „e“ am Ende
        $text = trim(preg_replace('/\s+(e|℮)$/u', '', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*pcs?$/i', $text, $matches) === 1) {
            return $matches[1] . ' Stück';
        }

        // „1l“ → „1 l“, „500g“ → „500 g“
        $text = preg_replace('/^(\d+(?:[.,]\d+)?)(kg|g|ml|cl|l)$/i', '$1 $2', $text) ?? $text;

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1)) . '…';
        }

        return $text;
    }

    /**
     * Deutsche Zahl ohne überflüssige Nachkommastellen: 1,5 · 0,25 · 330.
     */
    private static function number(float $value): string
    {
        if (fmod($value, 1.0) === 0.0) {
            return number_format($value, 0, ',', '');
        }

        $text = rtrim(number_format($value, 3, ',', ''), '0');

        return str_contains($text, ',') ? rtrim($text, ',') : $text;
    }
}
