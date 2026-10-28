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
                $Group->get('/config', [ConfigEndpoint::class, 'get']);

                $Group->post('/ops', [OperationsEndpoint::class, 'post']);
                $Group->get('/lists', [ListsEndpoint::class, 'index']);
                $Group->get('/lists/{id}', [ListsEndpoint::class, 'get']);
                $Group->post('/lists/{id}/move', [ListsEndpoint::class, 'move']);
                $Group->post('/lists/{id}/copy', [ListsEndpoint::class, 'copy']);
                $Group->post('/lists/{id}/invites', [SharesEndpoint::class, 'createInvite']);
                $Group->delete('/lists/{id}/invites/{inviteId}', [SharesEndpoint::class, 'withdrawInvite']);
                $Group->get('/lists/{id}/shares', [SharesEndpoint::class, 'participants']);
                $Group->delete('/lists/{id}/shares/{userId}', [SharesEndpoint::class, 'removeShare']);
                $Group->get('/invites/{token}', [SharesEndpoint::class, 'inspect']);
                $Group->post('/invites/{token}/accept', [SharesEndpoint::class, 'accept']);

                $Group->get('/household', [HouseholdEndpoint::class, 'get']);
                $Group->post('/household', [HouseholdEndpoint::class, 'create']);
                $Group->delete('/household', [HouseholdEndpoint::class, 'dissolve']);
                $Group->post('/household/join', [HouseholdEndpoint::class, 'join']);
                $Group->post('/household/invite', [HouseholdEndpoint::class, 'renewInvite']);
                $Group->post('/household/leave', [HouseholdEndpoint::class, 'leave']);
                $Group->patch('/household/members/{userId}', [HouseholdEndpoint::class, 'setRole']);
                $Group->delete('/household/members/{userId}', [HouseholdEndpoint::class, 'removeMember']);

                $Group->get('/products', [ProductsEndpoint::class, 'index']);
                $Group->post('/products', [ProductsEndpoint::class, 'create']);
                $Group->get('/products/search', [ProductsEndpoint::class, 'search']);
                $Group->get('/products/suggestions', [ProductsEndpoint::class, 'suggestions']);
                $Group->get('/products/{id}', [ProductsEndpoint::class, 'get']);
                $Group->patch('/products/{id}', [ProductsEndpoint::class, 'update']);
                $Group->post('/products/{id}/hide', [ProductsEndpoint::class, 'hide']);
                $Group->post('/products/{id}/unhide', [ProductsEndpoint::class, 'unhide']);
                $Group->post('/products/{id}/image', [ProductsEndpoint::class, 'setImage']);
                $Group->delete('/products/{id}/image', [ProductsEndpoint::class, 'removeImage']);
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
