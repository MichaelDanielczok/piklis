<?php

namespace MichaelDanielczok\Piklis\Lists;

use InvalidArgumentException;

/**
 * Ungültige Menge oder Einheit. `getErrorCode()` ist einer der Codes aus
 * `Quantity` (z. B. `not_integer`), gleich wie in `frontend/utils/quantity.js`.
 */
class InvalidQuantityException extends InvalidArgumentException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
