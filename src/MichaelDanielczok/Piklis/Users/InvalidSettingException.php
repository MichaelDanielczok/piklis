<?php

namespace MichaelDanielczok\Piklis\Users;

use QUI;

/**
 * Unbekannter Wert für eine App-Einstellung.
 */
class InvalidSettingException extends QUI\Exception
{
    public function __construct(private readonly string $setting)
    {
        parent::__construct('Invalid value for setting "' . $setting . '".', 400);
    }

    public function getSetting(): string
    {
        return $this->setting;
    }
}
