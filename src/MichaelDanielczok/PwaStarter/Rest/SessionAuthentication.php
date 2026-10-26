<?php

namespace MichaelDanielczok\PwaStarter\Rest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use QUI;
use QUI\REST\Response;

/**
 * Lässt nur Anfragen mit angemeldetem QUIQQER-Benutzer (Session) durch, sonst 401.
 *
 * `quiqqer/rest` 3.5 verlangt ein Auth-Paket; die Instanz nutzt
 * `quiqqer/rest-dummy-authentication`, die Prüfung passiert hier.
 * Später für native Apps: Bearer-Token über quiqqer/oauth-server.
 */
class SessionAuthentication
{
    public function __invoke(ServerRequestInterface $Request, RequestHandlerInterface $Handler): ResponseInterface
    {
        $User = QUI::getUserBySession();

        if (!QUI::getUsers()->isAuth($User) || !$User->isActive()) {
            return Json::error(
                new Response(),
                'unauthorized',
                'Not logged in.',
                401
            );
        }

        return $Handler->handle($Request);
    }
}
