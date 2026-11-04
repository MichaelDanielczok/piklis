<?php

namespace MichaelDanielczok\Piklis\Setup;

use MichaelDanielczok\Piklis\Products\ProductRepository;
use MichaelDanielczok\Piklis\Users\SessionLifetime;
use QUI;

/**
 * Paket-Setup (`./console setup`, Paketverwaltung): legt einmalig den
 * Grundkatalog an (`CatalogSeeder`) und stellt die Laufzeit der Anmeldung
 * ein (`SessionLifetime`). Läuft nach dem Import von `database.xml`, die
 * Tabellen existieren also.
 */
final class EventHandler
{
    public const PACKAGE = 'michaeldanielczok/piklis';

    public static function onPackageSetup(QUI\Package\Package $Package): void
    {
        if ($Package->getName() !== self::PACKAGE) {
            return;
        }

        self::ensureSessionLifetime();

        $result = (new CatalogSeeder(new ProductRepository()))->seed(CatalogSeeder::catalog());

        if ($result['seeded']) {
            QUI\System\Log::addInfo(
                'Piklis: Grundkatalog angelegt',
                ['created' => $result['created'], 'skipped' => $result['skipped']]
            );
        }
    }

    /**
     * Die Instanz gehört der App: Hier gilt die Laufzeit aus `SessionLifetime`
     * statt des Core-Standards von 1400 s. Längere Werte bleiben.
     */
    private static function ensureSessionLifetime(): void
    {
        $configured = (int)QUI::conf('session', 'max_life_time');

        if (!SessionLifetime::isConfiguredTooShort($configured)) {
            return;
        }

        $Config = QUI::getConfig('etc/conf.ini.php');
        $Config->set('session', 'max_life_time', (string)SessionLifetime::SECONDS);
        $Config->save();
    }
}
