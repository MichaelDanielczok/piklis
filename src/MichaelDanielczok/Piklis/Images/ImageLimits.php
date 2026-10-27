<?php

namespace MichaelDanielczok\Piklis\Images;

use QUI;

use function is_numeric;

/**
 * Grenzen für Produktbilder aus den Projekteinstellungen (Medien):
 *
 * - `media_maxUploadSize`: längste Seite in Pixeln. Die App verkleinert das
 *   Original im Browser darauf; größere Bilder verkleinert QUIQQER beim Upload.
 * - `media_maxUploadFileSize`: Dateigröße in Byte. Die App prüft die gewählte
 *   Datei vor dem Zuschnitt, der Server jede hochgeladene Datei.
 *
 * Der Zuschnitt hat immer `CROP_SIZE` Pixel; `media_maxUploadSize` muss
 * deshalb mindestens so groß sein, sonst verkleinert QUIQQER ihn beim Upload.
 */
final class ImageLimits
{
    /** Seitenlänge des quadratischen Zuschnitts, zugleich die größte Anzeigegröße */
    public const CROP_SIZE = 1600;

    /** Wenn das Projekt nichts einstellt (wie der Core) */
    public const DEFAULT_MAX_PIXELS = 4000;

    /** Wenn das Projekt nichts einstellt: 15 MB */
    public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        public readonly int $maxPixels,
        public readonly int $maxBytes
    ) {
    }

    /**
     * Werte aus den Einstellungen des Projekts der App (Standardprojekt).
     *
     * @throws QUI\Exception
     */
    public static function fromProject(?QUI\Projects\Project $Project = null): self
    {
        $Project ??= QUI::getProjectManager()->getStandard();

        return self::fromConfig(
            $Project->getConfig('media_maxUploadSize'),
            $Project->getConfig('media_maxUploadFileSize')
        );
    }

    /**
     * Leere oder ungültige Werte ergeben die Standardwerte.
     */
    public static function fromConfig(mixed $maxPixels, mixed $maxBytes): self
    {
        return new self(
            self::positive($maxPixels) ?? self::DEFAULT_MAX_PIXELS,
            self::positive($maxBytes) ?? self::DEFAULT_MAX_BYTES
        );
    }

    /**
     * Form für `GET /config`.
     *
     * @return array{maxPixels: int, maxBytes: int, cropSize: int}
     */
    public function toArray(): array
    {
        return [
            'maxPixels' => $this->maxPixels,
            'maxBytes' => $this->maxBytes,
            'cropSize' => self::CROP_SIZE
        ];
    }

    private static function positive(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
