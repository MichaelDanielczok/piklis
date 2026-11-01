<?php

namespace MichaelDanielczok\Piklis\Templates;

use Exception;

/**
 * Abgelehnte Aktion an einer Vorlage mit Fehlercode der API und HTTP-Status:
 * `template_not_found` (404), `template_deleted` (410), `no_access` (403),
 * `forbidden` (403), `no_household` (409), `template_exists` (409),
 * `template_changed` (409, mit dem aktuellen Stand), `precondition_required`
 * (428), `invalid_request` und `invalid_template` (400, mit `field`),
 * `unknown_product` (400, mit `field` = Produkt-ID).
 */
class TemplateException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
        private readonly ?string $field = null,
        private readonly ?TemplateState $current = null
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
     * Betroffenes Feld (`name`, `entries`) oder Produkt-ID.
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * Aktueller Stand bei `template_changed`, damit die App ihn zeigen kann.
     */
    public function getCurrent(): ?TemplateState
    {
        return $this->current;
    }
}
