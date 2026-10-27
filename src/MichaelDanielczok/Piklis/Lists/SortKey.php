<?php

namespace MichaelDanielczok\Piklis\Lists;

use InvalidArgumentException;

use function array_reverse;
use function intdiv;
use function preg_match;
use function strcmp;
use function strlen;
use function strpos;
use function substr;

/**
 * Sortierschlüssel zwischen zwei Nachbarn („fractional index“).
 *
 * Ein Schlüssel ist eine Ziffernfolge zur Basis 36 (`0-9a-z`) und steht für
 * einen Bruch zwischen 0 und 1 („i“ = 0,5). Verglichen wird als Zeichenkette
 * (`strcmp`), bei gleichem Schlüssel entscheidet die ID des Eintrags.
 * Schlüssel enden nie auf „0“, damit unterhalb immer Platz bleibt.
 *
 * Gleiche Logik und gleiche Testfälle in `frontend/utils/sortKey.js`
 * (`tests/fixtures/sort-keys.json`).
 */
final class SortKey
{
    public const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    public const MAX_LENGTH = 255;

    private const BASE = 36;

    /**
     * Schlüssel zwischen `$before` und `$after`; null heißt Anfang bzw. Ende
     * der Liste. Beide null: erster Schlüssel einer Liste.
     *
     * @throws InvalidArgumentException bei ungültigen Schlüsseln oder `$before` >= `$after`
     */
    public static function between(?string $before, ?string $after): string
    {
        if ($before !== null) {
            self::assertValid($before);
        }

        if ($after !== null) {
            self::assertValid($after);
        }

        if ($before !== null && $after !== null && strcmp($before, $after) >= 0) {
            throw new InvalidArgumentException('Sort key "' . $before . '" must be lower than "' . $after . '".');
        }

        if ($before === null && $after === null) {
            return self::midpoint('', null);
        }

        if ($after === null) {
            return self::increment((string)$before);
        }

        return self::midpoint($before ?? '', $after);
    }

    /**
     * `$count` aufsteigende Schlüssel zwischen `$before` und `$after`, z. B. für
     * eine Liste aus einer Vorlage. Zwischen zwei Nachbarn gleichmäßig verteilt.
     *
     * @return list<string>
     */
    public static function manyBetween(?string $before, ?string $after, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count === 1) {
            return [self::between($before, $after)];
        }

        if ($after === null) {
            $keys = [];
            $key = $before;

            for ($i = 0; $i < $count; $i++) {
                $key = self::between($key, null);
                $keys[] = $key;
            }

            return $keys;
        }

        if ($before === null) {
            $keys = [];
            $key = $after;

            for ($i = 0; $i < $count; $i++) {
                $key = self::between(null, $key);
                $keys[] = $key;
            }

            return array_reverse($keys);
        }

        $half = intdiv($count, 2);
        $middle = self::between($before, $after);

        return [
            ...self::manyBetween($before, $middle, $half),
            $middle,
            ...self::manyBetween($middle, $after, $count - $half - 1)
        ];
    }

    public static function isValid(string $key): bool
    {
        if (strlen($key) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match('/^[0-9a-z]*[1-9a-z]$/', $key) === 1;
    }

    /**
     * Reihenfolge zweier Einträge: Schlüssel, bei Gleichstand die ID.
     */
    public static function compare(string $keyA, string $idA, string $keyB, string $idB): int
    {
        $result = strcmp($keyA, $keyB);

        if ($result !== 0) {
            return $result <=> 0;
        }

        return strcmp($idA, $idB) <=> 0;
    }

    private static function assertValid(string $key): void
    {
        if (!self::isValid($key)) {
            throw new InvalidArgumentException('Invalid sort key "' . $key . '".');
        }
    }

    /**
     * Mitte zwischen `$low` ('' = 0) und `$high` (null = 1), `$low` < `$high`.
     */
    private static function midpoint(string $low, ?string $high): string
    {
        if ($high !== null) {
            $prefixLength = 0;

            while (
                $prefixLength < strlen($high)
                && self::digitAt($low, $prefixLength) === $high[$prefixLength]
            ) {
                $prefixLength++;
            }

            if ($prefixLength > 0) {
                return substr($high, 0, $prefixLength) . self::midpoint(
                    substr($low, $prefixLength),
                    substr($high, $prefixLength)
                );
            }
        }

        $lowDigit = $low === '' ? 0 : self::digitValue($low[0]);
        $highDigit = $high === null ? self::BASE : self::digitValue($high[0]);

        if ($highDigit - $lowDigit > 1) {
            return self::DIGITS[intdiv($lowDigit + $highDigit, 2)];
        }

        if ($high !== null && strlen($high) > 1) {
            return $high[0];
        }

        return self::DIGITS[$lowDigit] . self::midpoint(substr($low, 1), null);
    }

    /**
     * Kürzester einfacher Schlüssel hinter `$key`: erste Stelle, die nicht „z“
     * ist, um eins erhöhen. Beim Anhängen wächst der Schlüssel nur etwa alle
     * 18 Einträge um eine Stelle (i … z, zi … zz, zzi …).
     */
    private static function increment(string $key): string
    {
        $length = strlen($key);

        for ($i = 0; $i < $length; $i++) {
            if ($key[$i] !== 'z') {
                return substr($key, 0, $i) . self::DIGITS[self::digitValue($key[$i]) + 1];
            }
        }

        return $key . self::midpoint('', null);
    }

    private static function digitAt(string $key, int $position): string
    {
        return $position < strlen($key) ? $key[$position] : '0';
    }

    private static function digitValue(string $digit): int
    {
        $value = strpos(self::DIGITS, $digit);

        if ($value === false) {
            throw new InvalidArgumentException('Invalid sort key digit "' . $digit . '".');
        }

        return $value;
    }
}
