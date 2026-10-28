<?php

namespace MichaelDanielczok\Piklis\Lists;

use Exception;

/**
 * Abgelehnte Listenaktion außerhalb der Operationen (Verschieben, Kopieren,
 * Teilen) mit Fehlercode der API und HTTP-Status: `list_not_found` (404),
 * `list_deleted` (410), `no_access` (403), `forbidden` (403),
 * `no_household` (409), `list_exists` (409), `invalid_request` (400),
 * `invite_invalid` (404/410, mit `reason`), `share_not_found` und
 * `invite_not_found` (404).
 */
class ListException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
        private readonly ?string $reason = null
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

    /**
     * Genauerer Grund, z. B. bei `invite_invalid`: `unknown`, `used`,
     * `expired`, `list_deleted`, `revoked`.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
