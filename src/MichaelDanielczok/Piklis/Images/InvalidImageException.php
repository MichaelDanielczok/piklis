<?php

namespace MichaelDanielczok\Piklis\Images;

use Exception;

/**
 * Hochgeladenes Bild abgelehnt. `field` ist der Teil der Anfrage (`image`
 * für den Zuschnitt, `original`), `reason` der Grund:
 *
 * - `required`: fehlt
 * - `not_jpeg`: kein lesbares JPEG
 * - `wrong_size`: Zuschnitt nicht quadratisch mit `ImageLimits::CROP_SIZE`
 * - `too_many_pixels`: Original länger als `ImageLimits::$maxPixels`
 * - `too_large`: mehr Byte als `ImageLimits::$maxBytes` (HTTP 413)
 */
class InvalidImageException extends Exception
{
    public function __construct(
        private readonly string $field,
        private readonly string $reason
    ) {
        parent::__construct('Invalid ' . $field . ': ' . $reason . '.');
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getHttpStatus(): int
    {
        return $this->reason === 'too_large' ? 413 : 400;
    }
}
