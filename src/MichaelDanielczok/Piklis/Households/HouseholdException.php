<?php

namespace MichaelDanielczok\Piklis\Households;

use Exception;

/**
 * Abgelehnte Haushaltsaktion mit Fehlercode der API und HTTP-Status, z. B.
 * `already_member` (409), `invalid_code` (404), `forbidden` (403),
 * `last_admin` (409), `confirm_dissolve` (409).
 */
class HouseholdException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus
    ) {
        parent::__construct($message);
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
