<?php

namespace MichaelDanielczok\PwaStarter\Rest;

use MichaelDanielczok\PwaStarter\Users\InvalidSettingException;
use MichaelDanielczok\PwaStarter\Users\Profile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function is_array;

/**
 * `GET /me` und `PATCH /me/settings`.
 */
class MeEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $Profile = new Profile(QUI::getUserBySession());

        return Json::respond($Response, $Profile->toMeArray());
    }

    /**
     * Erwartet JSON, z. B. `{ "theme": "dark", "language": "auto" }`.
     * Antwortet wie `GET /me`.
     *
     * @param array<string, string> $args
     */
    public function patchSettings(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();

        if (!is_array($input)) {
            return Json::error($Response, 'invalid_request', 'Expected a JSON object.', 400);
        }

        $Profile = new Profile(QUI::getUserBySession());

        try {
            $Profile->updateSettings($input);
        } catch (InvalidSettingException $Exception) {
            return Json::error($Response, 'invalid_setting', $Exception->getMessage(), 400);
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'Settings could not be saved.', 500);
        }

        return Json::respond($Response, $Profile->toMeArray());
    }
}
