<?php

namespace MichaelDanielczok\Piklis\Lists;

use function abs;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_numeric;
use function is_string;
use function number_format;
use function preg_match;
use function round;
use function rtrim;
use function str_replace;
use function strlen;
use function trim;

/**
 * Menge eines Listeneintrags: Zahl plus optionale Einheit aus fester Liste.
 *
 * - Einheiten: `piece`, `pack`, `bottle`, `cup`, `can`, `jar`, `bag`, `net`,
 *   `bunch`, `roll`, `bar`, `g`, `kg`, `ml`, `l`; null = ohne Einheit („2 ד).
 * - Dezimalwerte nur bei `kg` und `l`, höchstens 3 Nachkommastellen; sonst
 *   ganze Zahlen ab 1. Höchstens 9999.
 * - Intern in Tausendsteln gespeichert, damit Vergleiche exakt sind.
 *
 * Eingabe mit Komma (`parse()`), Anzeige der Zahl deutsch (`formatAmount()`).
 * Die Texte mit Einheit („2 Packungen“) bildet nur das Frontend
 * (`frontend/utils/quantity.js`); gemeinsame Testfälle in
 * `tests/fixtures/quantities.json`.
 */
final class Quantity
{
    public const UNITS = [
        'piece',
        'pack',
        'bottle',
        'cup',
        'can',
        'jar',
        'bag',
        'net',
        'bunch',
        'roll',
        'bar',
        'g',
        'kg',
        'ml',
        'l'
    ];

    public const DECIMAL_UNITS = ['kg', 'l'];

    public const MAX_AMOUNT = 9999;

    public const MAX_DECIMALS = 3;

    public const ERROR_EMPTY = 'empty';
    public const ERROR_INVALID_NUMBER = 'invalid_number';
    public const ERROR_UNKNOWN_UNIT = 'unknown_unit';
    public const ERROR_NOT_POSITIVE = 'not_positive';
    public const ERROR_NOT_INTEGER = 'not_integer';
    public const ERROR_TOO_MANY_DECIMALS = 'too_many_decimals';
    public const ERROR_TOO_LARGE = 'too_large';

    private const SCALE = 1000;

    private const MAX_INPUT_LENGTH = 20;

    private function __construct(
        private readonly int $thousandths,
        private readonly ?string $unit
    ) {
    }

    /**
     * @throws InvalidQuantityException
     */
    public static function create(int|float $amount, ?string $unit): self
    {
        $error = self::validate($amount, $unit);

        if ($error !== null) {
            throw new InvalidQuantityException($error, 'Invalid quantity: ' . $error . '.');
        }

        return new self((int)round($amount * self::SCALE), $unit);
    }

    public static function one(): self
    {
        return new self(self::SCALE, null);
    }

    /**
     * Eingabe aus einem Textfeld, z. B. „1,5“ bei kg.
     *
     * @throws InvalidQuantityException
     */
    public static function parse(string $input, ?string $unit): self
    {
        return self::create(self::parseAmount($input), $unit);
    }

    /**
     * Zahl aus einer Eingabe: Komma ist das Dezimalzeichen. Ein Punkt trennt
     * Tausender, wenn danach genau drei Ziffern folgen („1.500“ = 1500, auch
     * „1.234,5“); sonst gilt ein einzelner Punkt als Dezimalzeichen („1.5“).
     *
     * @throws InvalidQuantityException
     */
    public static function parseAmount(string $input): float
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidQuantityException(self::ERROR_EMPTY, 'Amount is empty.');
        }

        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new InvalidQuantityException(self::ERROR_INVALID_NUMBER, 'Amount is too long.');
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $input) === 1) {
            $normalized = str_replace(['.', ','], ['', '.'], $input);

            return (float)$normalized;
        }

        if (preg_match('/^(\d+[,.]?\d*|[,.]\d+)$/', $input) === 1) {
            $normalized = str_replace(',', '.', $input);

            return (float)$normalized;
        }

        throw new InvalidQuantityException(self::ERROR_INVALID_NUMBER, 'Amount is not a number.');
    }

    /**
     * Fehlercode oder null, wenn die Menge gültig ist.
     */
    public static function validate(int|float $amount, ?string $unit): ?string
    {
        if ($unit !== null && !in_array($unit, self::UNITS, true)) {
            return self::ERROR_UNKNOWN_UNIT;
        }

        if (is_float($amount) && (is_nan($amount) || is_infinite($amount))) {
            return self::ERROR_INVALID_NUMBER;
        }

        if ($amount <= 0) {
            return self::ERROR_NOT_POSITIVE;
        }

        $thousandths = $amount * self::SCALE;

        if (!self::allowsDecimals($unit)) {
            if (round($amount) != $amount) {
                return self::ERROR_NOT_INTEGER;
            }
        } elseif (abs($thousandths - round($thousandths)) > 1e-6) {
            return self::ERROR_TOO_MANY_DECIMALS;
        }

        if ($amount > self::MAX_AMOUNT) {
            return self::ERROR_TOO_LARGE;
        }

        return null;
    }

    public static function allowsDecimals(?string $unit): bool
    {
        return $unit !== null && in_array($unit, self::DECIMAL_UNITS, true);
    }

    /**
     * Zahl deutsch: Komma, Tausenderpunkt, ohne Nullen am Ende („1,5“, „1.000“).
     */
    public static function formatAmount(int|float $amount): string
    {
        $text = number_format(round($amount, self::MAX_DECIMALS), self::MAX_DECIMALS, ',', '.');
        $text = rtrim($text, '0');

        return rtrim($text, ',');
    }

    /**
     * Aus JSON einer Operation: `{ "amount": 2, "unit": "pack" }`, `unit` darf
     * fehlen oder null sein.
     *
     * @throws InvalidQuantityException
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || !isset($data['amount'])) {
            throw new InvalidQuantityException(self::ERROR_INVALID_NUMBER, 'Quantity needs an amount.');
        }

        $amount = $data['amount'];
        $unit = $data['unit'] ?? null;

        if (!is_int($amount) && !is_float($amount)) {
            throw new InvalidQuantityException(self::ERROR_INVALID_NUMBER, 'Amount must be a number.');
        }

        if ($unit !== null && !is_string($unit)) {
            throw new InvalidQuantityException(self::ERROR_UNKNOWN_UNIT, 'Unit must be a string or null.');
        }

        return self::create($amount, $unit);
    }

    /**
     * Aus der Datenbank (`decimal(10,3)` als Zeichenkette) ohne erneute Prüfung.
     */
    public static function fromDatabase(mixed $amount, mixed $unit): self
    {
        $value = is_numeric($amount) ? (float)$amount : 1.0;
        $unit = is_string($unit) && $unit !== '' ? $unit : null;

        return new self((int)round($value * self::SCALE), $unit);
    }

    public function amount(): int|float
    {
        if ($this->thousandths % self::SCALE === 0) {
            return intdiv($this->thousandths, self::SCALE);
        }

        return $this->thousandths / self::SCALE;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    /**
     * Wert für `decimal(10,3)`.
     */
    public function toDatabase(): string
    {
        return number_format($this->thousandths / self::SCALE, self::MAX_DECIMALS, '.', '');
    }

    public function equals(self $other): bool
    {
        return $this->thousandths === $other->thousandths && $this->unit === $other->unit;
    }

    public function sameUnit(self $other): bool
    {
        return $this->unit === $other->unit;
    }

    /**
     * Summe zweier Mengen mit gleicher Einheit, höchstens `MAX_AMOUNT`
     * (Übernehmen in eine andere Liste). Bei anderer Einheit null.
     */
    public function plus(self $other): ?self
    {
        if (!$this->sameUnit($other)) {
            return null;
        }

        $sum = $this->thousandths + $other->thousandths;
        $max = self::MAX_AMOUNT * self::SCALE;

        return new self($sum > $max ? $max : $sum, $this->unit);
    }

    /**
     * Braucht diese Menge mehr als `$seen`? Bei anderer Einheit lässt sich das
     * nicht vergleichen und gilt als mehr (Regel „alter Haken“).
     */
    public function exceeds(self $seen): bool
    {
        if ($this->unit !== $seen->unit) {
            return true;
        }

        return $this->thousandths > $seen->thousandths;
    }

    /**
     * @return array{amount: int|float, unit: string|null}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount(),
            'unit' => $this->unit
        ];
    }
}
