<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use Exception;

/**
 * Abruf bei Open Food Facts gescheitert, mit Fehlercode der API und
 * HTTP-Status: `off_not_found` (404), `off_no_image` (404), `off_busy` (429,
 * eigene Bremse vor dem Abruflimit), `off_unavailable` (503: kein Netz,
 * Zeitüberschreitung, Fehler oder Überlastung bei Open Food Facts).
 */
class FoodFactsException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('off_not_found', 'Open Food Facts does not know this barcode.', 404);
    }

    public static function noImage(): self
    {
        return new self('off_no_image', 'Open Food Facts has no front image for this product.', 404);
    }

    public static function busy(): self
    {
        return new self('off_busy', 'Too many requests to Open Food Facts, please wait a moment.', 429);
    }

    public static function unavailable(string $reason): self
    {
        return new self('off_unavailable', 'Open Food Facts is not reachable: ' . $reason, 503);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
