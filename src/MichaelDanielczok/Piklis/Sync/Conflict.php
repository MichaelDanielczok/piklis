<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\Quantity;

use const DATE_ATOM;

/**
 * Konflikte in der Antwort auf eine Operation. Die App fragt dann nach:
 *
 * - `quantity` / `note`: gleichzeitig verschieden geändert, Auswahl
 *   „deine / andere“ (`yours` / `theirs`).
 * - `checkedLess`: alter Haken, inzwischen wird mehr gebraucht
 *   („2 abgehakt, inzwischen 3 benötigt“: erledigt · offen lassen).
 * - `removed`: der Eintrag wurde inzwischen entfernt („wiederherstellen?“).
 */
final class Conflict
{
    public const QUANTITY = 'quantity';
    public const NOTE = 'note';
    public const CHECKED_LESS = 'checkedLess';
    public const REMOVED = 'removed';

    /**
     * @return array<string, mixed>
     */
    public static function quantity(Quantity $yours, Quantity $theirs): array
    {
        return [
            'type' => self::QUANTITY,
            'yours' => $yours->toArray(),
            'theirs' => $theirs->toArray()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(string $yours, string $theirs): array
    {
        return [
            'type' => self::NOTE,
            'yours' => $yours,
            'theirs' => $theirs
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkedLess(Quantity $checked, Quantity $current): array
    {
        return [
            'type' => self::CHECKED_LESS,
            'checked' => $checked->toArray(),
            'current' => $current->toArray()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function removed(?DateTimeImmutable $removedAt, ?string $removedBy): array
    {
        return [
            'type' => self::REMOVED,
            'removedAt' => $removedAt?->format(DATE_ATOM),
            'removedBy' => $removedBy
        ];
    }
}
