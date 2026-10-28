<?php

namespace MichaelDanielczok\Piklis\Lists;

use function base64_encode;
use function hash;
use function is_string;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Token eines Einladungslinks zu einer Liste: 32 Zufallsbytes, URL-sicher
 * (Base64url ohne `=`, 43 Zeichen). Der Server speichert nur den
 * SHA-256-Hash; wer die Datenbank liest, kann damit keine Einladung annehmen.
 */
final class InviteToken
{
    public const VALID_DAYS = 7;

    private const BYTES = 32;

    private const PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match(self::PATTERN, $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
