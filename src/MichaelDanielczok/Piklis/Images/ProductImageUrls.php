<?php

namespace MichaelDanielczok\Piklis\Images;

use MichaelDanielczok\Piklis\Products\Product;

use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function pathinfo;
use function rawurlencode;

use const PATHINFO_DIRNAME;
use const PATHINFO_FILENAME;

/**
 * Bildadressen der Produkte für die API, aus dem Media-Cache von QUIQQER.
 *
 * Der Zuschnitt erscheint in drei Größen als WebP:
 * `<cache>/<ordner>/<datei>__<B>x<H>.webp`. Fehlt eine Datei, erzeugt
 * `quiqqer/cache` sie beim ersten Abruf (`onRequestImageNotFound`); danach
 * liefert der Webserver sie direkt aus. Weil der Zuschnitt immer genau
 * `ImageLimits::CROP_SIZE` Pixel hat, sind die Namen fest (dieselben, die der
 * Core mit `Image::getSizeCachePath()` bildet; geprüft im Integrationstest).
 *
 * Das Original kommt als JPEG in voller Größe (zum neuen Zuschneiden).
 */
final class ProductImageUrls
{
    /** Liste und Kachel · Produktdetail · Großansicht mit Zoom */
    public const SIZES = [
        'small' => 128,
        'medium' => 640,
        'large' => ImageLimits::CROP_SIZE
    ];

    /**
     * @param array<int, string> $files Medien-ID => Dateipfad im Medienbereich
     */
    public function __construct(
        private readonly string $cacheUrl,
        private readonly array $files
    ) {
    }

    /**
     * Liest die Dateipfade aller Bilder der Produkte mit einer Abfrage.
     *
     * @param list<Product> $products
     */
    public static function load(ImageMedia $Media, array $products): self
    {
        $ids = [];

        foreach ($products as $Product) {
            if ($Product->imageId !== null) {
                $ids[] = $Product->imageId;
            }

            if ($Product->imageOriginalId !== null) {
                $ids[] = $Product->imageOriginalId;
            }
        }

        $files = $ids === [] ? [] : $Media->files(array_values(array_unique($ids)));

        return new self($Media->cacheUrl(), $files);
    }

    /**
     * Ohne Bild oder ohne Datei des Zuschnitts null (die App zeigt dann das
     * Ersatzicon).
     *
     * @return array{small: string, medium: string, large: string, original: string|null}|null
     */
    public function of(Product $Product): ?array
    {
        $file = $Product->imageId === null ? null : ($this->files[$Product->imageId] ?? null);

        if ($file === null) {
            return null;
        }

        $original = $Product->imageOriginalId === null ? null : ($this->files[$Product->imageOriginalId] ?? null);

        return [
            'small' => self::sized($this->cacheUrl, $file, self::SIZES['small']),
            'medium' => self::sized($this->cacheUrl, $file, self::SIZES['medium']),
            'large' => self::sized($this->cacheUrl, $file, self::SIZES['large']),
            'original' => $original === null ? null : $this->cacheUrl . self::encodePath($original)
        ];
    }

    /**
     * WebP-Adresse einer quadratischen Größe im Media-Cache.
     */
    public static function sized(string $cacheUrl, string $file, int $size): string
    {
        $directory = pathinfo($file, PATHINFO_DIRNAME);
        $name = pathinfo($file, PATHINFO_FILENAME) . '__' . $size . 'x' . $size . '.webp';
        $path = $directory === '.' || $directory === '' ? $name : $directory . '/' . $name;

        return $cacheUrl . self::encodePath($path);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
