<?php

namespace MichaelDanielczok\Piklis\Pwa;

use QUI;

use function dirname;
use function filesize;
use function header;
use function is_file;
use function readfile;

/**
 * Service Worker und Manifest unter der Wurzel der Instanz.
 *
 * Beide Dateien entstehen beim Build in `bin/app/`. Ein Service Worker steuert
 * aber nur Adressen unterhalb seines eigenen Pfads; damit er die Startseite `/`
 * erreicht, muss er unter `/sw.js` liegen. Die Dateien existieren dort nicht,
 * die `.htaccess` schickt die Anfrage an QUIQQER, und hier wird die Datei aus
 * `bin/app/` ausgeliefert. Keine Webserver-Anpassung nötig.
 */
class EventHandler
{
    private const FILES = [
        'sw.js' => 'text/javascript; charset=utf-8',
        'manifest.webmanifest' => 'application/manifest+json; charset=utf-8'
    ];

    public static function onRequest(QUI\Rewrite $Rewrite, string $url): void
    {
        if (!isset(self::FILES[$url])) {
            return;
        }

        $file = dirname(__DIR__, 4) . '/bin/app/' . $url;

        if (!is_file($file)) {
            return;
        }

        header('Content-Type: ' . self::FILES[$url]);
        header('Content-Length: ' . filesize($file));
        // Browser fragt bei jedem Laden nach, sonst bleibt ein neuer Build unbemerkt
        header('Cache-Control: no-cache');
        header('X-Robots-Tag: noindex');

        readfile($file);
        exit;
    }
}
