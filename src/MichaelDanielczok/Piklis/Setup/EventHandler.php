<?php

namespace MichaelDanielczok\Piklis\Setup;

use MichaelDanielczok\Piklis\Products\ProductRepository;
use QUI;

/**
 * Paket-Setup (`./console setup`, Paketverwaltung): legt einmalig den
 * Grundkatalog an (`CatalogSeeder`). Läuft nach dem Import von
 * `database.xml`, die Tabellen existieren also.
 */
final class EventHandler
{
    public const PACKAGE = 'michaeldanielczok/piklis';

    public static function onPackageSetup(QUI\Package\Package $Package): void
    {
        if ($Package->getName() !== self::PACKAGE) {
            return;
        }

        $result = (new CatalogSeeder(new ProductRepository()))->seed(CatalogSeeder::catalog());

        if ($result['seeded']) {
            QUI\System\Log::addInfo(
                'Piklis: Grundkatalog angelegt',
                ['created' => $result['created'], 'skipped' => $result['skipped']]
            );
        }
    }
}
