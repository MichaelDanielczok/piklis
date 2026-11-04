<?php

namespace MichaelDanielczok\Piklis\Users;

use QUI;

use function is_int;

/**
 * Wie lange eine Anmeldung hält.
 *
 * Der Core erneuert das Session-Cookie nur nach einer langen Pause
 * (`Session::setup`: halbe Laufzeit ohne Anfrage). Der Live-Abgleich fragt aber
 * alle paar Sekunden, deshalb liefe das Cookie trotz Nutzung ab. `GET /me`
 * erneuert es darum beim App-Start, höchstens einmal am Tag.
 */
final class SessionLifetime
{
    public const SECONDS = 90 * 86400;
    public const COOKIE_RENEWAL_INTERVAL = 86400;

    private const RENEWED_AT = 'piklis-cookie-renewed-at';

    public static function isConfiguredTooShort(int $configuredSeconds): bool
    {
        return $configuredSeconds < self::SECONDS;
    }

    public static function isCookieRenewalDue(mixed $renewedAt, int $now): bool
    {
        if (!is_int($renewedAt)) {
            return true;
        }

        return $now - $renewedAt >= self::COOKIE_RENEWAL_INTERVAL;
    }

    public static function renewCookieIfDue(QUI\Session $Session, int $now): void
    {
        if (!self::isCookieRenewalDue($Session->get(self::RENEWED_AT), $now)) {
            return;
        }

        $Session->set(self::RENEWED_AT, $now);

        // alte ID bleibt gültig: gleichzeitige Anfragen behalten ihre Anmeldung
        $Session->refresh();
    }
}
