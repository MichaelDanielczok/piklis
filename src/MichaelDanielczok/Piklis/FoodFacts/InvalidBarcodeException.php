<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use Exception;

/**
 * Ungültiger Barcode, API-Fehler `400 invalid_barcode` mit `reason`:
 * `required` (leer), `invalid` (keine 8, 12, 13 oder 14 Ziffern),
 * `checksum` (Prüfziffer falsch).
 */
class InvalidBarcodeException extends Exception
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct('Invalid barcode: ' . $reason . '.');
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
