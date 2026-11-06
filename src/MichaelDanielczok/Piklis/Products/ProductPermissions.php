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
 * - Das Produktbild (setzen, ersetzen, neu zuschneiden, entfernen) gehört zu
 *   den Produktdaten: dieselbe Regel wie Bearbeiten.
 * - Eine Listenfreigabe gibt keine Rechte am Katalog.
 * - Listenartikel (Schritt 18): Alle mit Zugriff auf eine Liste oder Vorlage,
 *   die ihn nutzt, dürfen ihn bearbeiten, auch Gäste. Wer keinen Zugriff hat,
 *   kommt gar nicht bis hierher: `ProductService::loadVisible` kennt den
 *   Artikel dann nicht, auch Produktpfleger nicht. Ausblenden gibt es für
 *   Listenartikel nicht, sie stehen ja nicht im Katalog.
 *
 * Das Frontend wendet dieselbe Regel an (`composables/useProducts.js`), um
 * Bearbeiten nur anzubieten, wo es erlaubt ist; entscheidend ist der Server.
 */
final class ProductPermissions
{
    /**
     * `$Product` kommt aus `ProductService::loadVisible`.
     */
    public static function canEdit(Product $Product, string $userId, bool $isManager): bool
    {
        if ($isManager || $Product->listItem) {
            return true;
        }

        return !$Product->hidden && $Product->createdBy === $userId;
    }

    public static function canChangeImage(Product $Product, string $userId, bool $isManager): bool
    {
        return self::canEdit($Product, $userId, $isManager);
    }

    public static function canHide(Product $Product, bool $isManager): bool
    {
        return $isManager && !$Product->listItem;
    }
}
