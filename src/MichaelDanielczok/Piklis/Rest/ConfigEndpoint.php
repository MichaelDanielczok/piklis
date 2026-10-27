<?php

namespace MichaelDanielczok\Piklis\Rest;

use MichaelDanielczok\Piklis\Images\ImageLimits;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

/**
 * `GET /config`: Einstellungen, die die App vom Server braucht.
 *
 * `images`: Grenzen der Produktbilder aus den Projekteinstellungen
 * (`maxPixels`, `maxBytes`) und die Seitenlänge des Zuschnitts
 * (`cropSize`), siehe `Images\ImageLimits`.
 */
class ConfigEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        try {
            $Limits = ImageLimits::fromProject();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Settings could not be loaded.', 500);
        }

        return Json::respond($Response, [
            'images' => $Limits->toArray()
        ]);
    }
}
