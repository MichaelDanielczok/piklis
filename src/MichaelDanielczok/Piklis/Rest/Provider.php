<?php

namespace MichaelDanielczok\Piklis\Rest;

use QUI;
use QUI\REST\ProviderInterface;
use QUI\REST\Server;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * REST-API der App unter `<basePath>/piklis/v1` (Standard: `/api/piklis/v1`).
 *
 * Anmeldung über die QUIQQER-Session; jede Route der Gruppe verlangt einen
 * angemeldeten Benutzer. Öffentliche Routen außerhalb der Gruppe registrieren.
 */
class Provider implements ProviderInterface
{
    public const PREFIX = '/piklis/v1';

    public function register(Server $Server): void
    {
        $Slim = $Server->getSlim();

        $Slim->group(
            self::PREFIX,
            function (RouteCollectorProxyInterface $Group): void {
                $Group->get('/me', [MeEndpoint::class, 'get']);
                $Group->patch('/me/settings', [MeEndpoint::class, 'patchSettings']);

                $Group->post('/ops', [OperationsEndpoint::class, 'post']);
                $Group->get('/lists', [ListsEndpoint::class, 'index']);
                $Group->get('/lists/{id}', [ListsEndpoint::class, 'get']);
            }
        )->add(new SessionAuthentication());
    }

    public function getOpenApiDefinitionFile(): bool|string
    {
        return false;
    }

    public function getTitle(?QUI\Locale $Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('michaeldanielczok/piklis', 'package.title');
    }

    public function getName(): string
    {
        return 'piklis';
    }
}
