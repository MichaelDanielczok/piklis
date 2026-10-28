<?php

namespace MichaelDanielczok\Piklis\Lists;

use Exception;

/**
 * Abgelehnte Listenaktion außerhalb der Operationen (Verschieben, Kopieren)
 * mit Fehlercode der API und HTTP-Status: `list_not_found` (404),
 * `list_deleted` (410), `no_access` (403), `forbidden` (403),
 * `no_household` (409), `list_exists` (409), `invalid_request` (400).
 */
class ListException extends Exception
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
