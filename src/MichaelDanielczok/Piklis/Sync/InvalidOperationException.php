<?php

namespace MichaelDanielczok\Piklis\Sync;

use InvalidArgumentException;

/**
 * Operation mit falscher Form (fehlendes Feld, ungültige ID, Menge, Schlüssel).
 * Die Antwort an die App ist `rejected` mit `error: invalid_operation`.
 */
class InvalidOperationException extends InvalidArgumentException
{
}
