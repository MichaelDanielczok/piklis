<?php

namespace MichaelDanielczok\Piklis\Households;

use function preg_replace;
use function random_int;
use function strlen;
use function strtoupper;

/**
 * Einladungscode eines Haushalts: 8 Zeichen ohne leicht verwechselbare
 * (0/O, 1/I/L), angezeigt als `ABCD-EFGH`, gespeichert ohne Bindestrich.
 * Vorbild: SmartPicker. Anders als dort 7 Tage mehrfach verwendbar.
 */
final class InviteCode
{
    public const LENGTH = 8;

    public const VALID_DAYS = 7;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Eingabe vereinheitlichen: Großbuchstaben, ohne Leerzeichen und Bindestriche.
     */
    public static function normalize(string $input): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($input)) ?? '';
    }
}
