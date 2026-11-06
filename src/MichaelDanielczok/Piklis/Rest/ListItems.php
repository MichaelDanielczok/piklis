<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Images\QuiqqerImageMedia;
use MichaelDanielczok\Piklis\Products\ProductPresenter;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use QUI;

use function array_unique;
use function array_values;

/**
 * Listenartikel zu Listen und Vorlagen ausliefern: `GET /products` kennt sie
 * nicht, deshalb kommen sie mit dem, was sie nutzt (Feld `products`). Die
 * Antwort enthält immer alle Listenartikel, auch bei „seit Revision N“, denn
 * eine Änderung am Artikel zählt keine Revision hoch.
 *
 * Aufrufer prüfen vorher den Zugriff auf Liste oder Vorlage. Scheitert das
 * Laden, fehlen die Artikel nur bis zur nächsten Abfrage.
 */
final class ListItems
{
    /**
     * @param list<string> $productIds Produkte der Liste oder Vorlage
     * @return list<array<string, mixed>>
     */
    public static function present(array $productIds): array
    {
        try {
            $items = (new ProductRepository())->listItems(array_values(array_unique($productIds)));

            return $items === [] ? [] : (new ProductPresenter(new QuiqqerImageMedia()))->many($items);
        } catch (DBALException | QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }
    }
}
