<?php

namespace MichaelDanielczok\Piklis\Products;

use Exception;

/**
 * Abgelehnte Produktänderung mit Fehlercode der API und HTTP-Status:
 * `product_not_found` (404), `forbidden` (403), `product_exists` (409).
 */
class ProductException extends Exception
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
        return new self('product_not_found', 'The product does not exist.', 404);
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function exists(): self
    {
        return new self('product_exists', 'Another user\'s product already has this id.', 409);
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
