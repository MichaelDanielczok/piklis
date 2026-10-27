<?php

namespace MichaelDanielczok\Piklis\Products;

/**
 * Pflegerechte am Katalog (Obsidian „Piklis Konzept“ › „Pflegerechte“):
 *
 * - Alle angemeldeten Benutzer legen Produkte an und verwenden sie.
 * - Wer ein Produkt angelegt hat, darf es bearbeiten, solange es nicht
 *   ausgeblendet ist.
 * - Produktpfleger (`piklis.products.manage`) bearbeiten alle Produkte und
 *   blenden sie aus oder wieder ein.
 * - Eine Listenfreigabe gibt keine Produktrechte.
 *
 * Das Frontend wendet dieselbe Regel an (`composables/useProducts.js`), um
 * Bearbeiten nur anzubieten, wo es erlaubt ist; entscheidend ist der Server.
 */
final class ProductPermissions
{
    public static function canEdit(Product $Product, string $userId, bool $isManager): bool
    {
        if ($isManager) {
            return true;
        }

        return !$Product->hidden && $Product->createdBy === $userId;
    }

    public static function canHide(bool $isManager): bool
    {
        return $isManager;
    }
}
