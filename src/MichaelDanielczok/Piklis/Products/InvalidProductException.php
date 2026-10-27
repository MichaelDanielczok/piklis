<?php

namespace MichaelDanielczok\Piklis\Products;

use Exception;

/**
 * Ungültige Produktdaten: welches Feld (`name`, `category`, …) und warum
 * (`required`, `too_long`, `invalid`).
 */
class InvalidProductException extends Exception
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
}
