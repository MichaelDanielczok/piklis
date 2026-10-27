<?php

namespace MichaelDanielczok\Piklis\Rest;

use QUI;
use RuntimeException;

use function is_string;

/**
 * UUID des angemeldeten Benutzers für Routen hinter `SessionAuthentication`.
 */
final class SessionUser
{
    /**
     * @throws RuntimeException ohne angemeldeten Benutzer (dann hätte
     *         `SessionAuthentication` die Anfrage schon abgewiesen)
     */
    public static function id(): string
    {
        $uuid = QUI::getUserBySession()->getUUID();

        if (!is_string($uuid) || $uuid === '') {
            throw new RuntimeException('No user in this session.');
        }

        return $uuid;
    }
}
