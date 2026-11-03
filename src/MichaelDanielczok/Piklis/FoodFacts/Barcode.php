<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function preg_replace;
use function strlen;

/**
 * Barcode (GTIN) für Open Food Facts: EAN-8, UPC-A (12), EAN-13 oder
 * GTIN-14, nur Ziffern. Leerzeichen und Bindestriche aus der Eingabe werden
 * entfernt. Die Prüfziffer muss stimmen; so fallen Tippfehler auf, bevor
 * Open Food Facts gefragt wird (gescannte Codes stimmen immer).
 */
final class Barcode
{
    private const LENGTHS = [8, 12, 13, 14];

    /**
     * @throws InvalidBarcodeException
     */
    public static function normalize(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string)$value;
        }

        if (!is_string($value)) {
            throw new InvalidBarcodeException('required');
        }

        $barcode = preg_replace('/[\s\-]+/u', '', $value) ?? '';

        if ($barcode === '') {
            throw new InvalidBarcodeException('required');
        }

        if (preg_match('/^\d+$/', $barcode) !== 1 || !in_array(strlen($barcode), self::LENGTHS, true)) {
            throw new InvalidBarcodeException('invalid');
        }

        if (!self::hasValidCheckDigit($barcode)) {
            throw new InvalidBarcodeException('checksum');
        }

        return $barcode;
    }

    /**
     * GTIN-Prüfziffer: von rechts ohne die Prüfziffer abwechselnd mal 3 und
     * mal 1 gewichtet, auf den nächsten Zehner ergänzt.
     */
    public static function hasValidCheckDigit(string $barcode): bool
    {
        $length = strlen($barcode);
        $sum = 0;

        for ($index = $length - 2, $position = 0; $index >= 0; $index--, $position++) {
            $digit = (int)$barcode[$index];
            $sum += $position % 2 === 0 ? $digit * 3 : $digit;
        }

        $check = (10 - $sum % 10) % 10;

        return $check === (int)$barcode[$length - 1];
    }
}
