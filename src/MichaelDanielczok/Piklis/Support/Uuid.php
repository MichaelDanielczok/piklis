<?php

namespace MichaelDanielczok\Piklis\Support;

use function bin2hex;
use function chr;
use function is_string;
use function ord;
use function preg_match;
use function random_bytes;
use function str_split;
use function vsprintf;

/**
 * UUIDs (Version 4, klein geschrieben) für IDs, die der Server selbst
 * vergibt: Haushalte, kopierte Einträge, Protokollzeilen von REST-Aktionen.
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * UUID in Kleinbuchstaben, wie der Browser sie erzeugt.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
