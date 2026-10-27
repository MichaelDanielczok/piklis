<?php

namespace MichaelDanielczok\Piklis\Rest;

use MichaelDanielczok\Piklis\Users\Profile;
use QUI;
use RuntimeException;

use function is_string;

/**
 * Angemeldeter Benutzer für Routen hinter `SessionAuthentication`: UUID und
 * Produktpflege-Recht.
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

    /**
     * Recht `piklis.products.manage`: alle Produkte bearbeiten und ausblenden.
     */
    public static function canManageProducts(): bool
    {
        return (new Profile(QUI::getUserBySession()))->canManageProducts();
    }
}
