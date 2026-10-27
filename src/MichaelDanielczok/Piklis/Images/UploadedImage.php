<?php

namespace MichaelDanielczok\Piklis\Images;

use function getimagesizefromstring;
use function is_array;
use function max;
use function strlen;

use const IMAGETYPE_JPEG;

/**
 * Prüft die Bilder, die die App hochlädt. Beide hat der Browser neu kodiert
 * (JPEG, ohne EXIF und Ortsdaten):
 *
 * - Zuschnitt: quadratisch mit `ImageLimits::CROP_SIZE` Pixeln
 * - Original: längste Seite höchstens `ImageLimits::$maxPixels`
 *
 * Beide höchstens `ImageLimits::$maxBytes`.
 */
final class UploadedImage
{
    public const FIELD_CROP = 'image';
    public const FIELD_ORIGINAL = 'original';

    /**
     * @throws InvalidImageException
     */
    public static function checkCrop(string $jpeg, ImageLimits $Limits): void
    {
        [$width, $height] = self::jpegSize($jpeg, self::FIELD_CROP, $Limits);

        if ($width !== ImageLimits::CROP_SIZE || $height !== ImageLimits::CROP_SIZE) {
            throw new InvalidImageException(self::FIELD_CROP, 'wrong_size');
        }
    }

    /**
     * @throws InvalidImageException
     */
    public static function checkOriginal(string $jpeg, ImageLimits $Limits): void
    {
        [$width, $height] = self::jpegSize($jpeg, self::FIELD_ORIGINAL, $Limits);

        if (max($width, $height) > $Limits->maxPixels) {
            throw new InvalidImageException(self::FIELD_ORIGINAL, 'too_many_pixels');
        }
    }

    /**
     * @return array{0: int, 1: int} Breite, Höhe
     * @throws InvalidImageException
     */
    private static function jpegSize(string $jpeg, string $field, ImageLimits $Limits): array
    {
        if ($jpeg === '') {
            throw new InvalidImageException($field, 'required');
        }

        if (strlen($jpeg) > $Limits->maxBytes) {
            throw new InvalidImageException($field, 'too_large');
        }

        $info = getimagesizefromstring($jpeg);

        if (!is_array($info) || $info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidImageException($field, 'not_jpeg');
        }

        return [$info[0], $info[1]];
    }
}
