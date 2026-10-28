<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Lists\ListException;
use MichaelDanielczok\Piklis\Lists\ListInvite;
use MichaelDanielczok\Piklis\Lists\ListRepository;
use MichaelDanielczok\Piklis\Lists\ListShare;
use MichaelDanielczok\Piklis\Lists\ListSharing;
use MichaelDanielczok\Piklis\Lists\ShareRepository;
use MichaelDanielczok\Piklis\Users\Profile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function array_map;

use const DATE_ATOM;

/**
 * Einzelne Listen teilen (Regeln in `Lists\ListSharing`).
 *
 * | Route | Wer | Wirkung |
 * | --- | --- | --- |
 * | `POST /lists/{id}/invites` | Besitzer, Mitglieder | neuer Link: `{ token, invite }` (201) |
 * | `GET /lists/{id}/shares` | Besitzer, Mitglieder | `{ shares, invites }` |
 * | `DELETE /lists/{id}/shares/{userId}` | Besitzer, Mitglieder; Gast sich selbst | Freigabe entziehen bzw. verlassen |
 * | `DELETE /lists/{id}/invites/{inviteId}` | Besitzer, Mitglieder | offenen Link zurückziehen |
 * | `GET /invites/{token}` | angemeldet | Einladung ansehen: `{ invite, access }` |
 * | `POST /invites/{token}/accept` | angemeldet | annehmen: `{ status, list }` |
 *
 * Ungültige Links: `invite_invalid` mit `reason` (`unknown` 404; `used`,
 * `expired`, `list_deleted`, `revoked` 410). Die App zeigt für alle „Link
 * nicht mehr gültig, bitte um einen neuen“.
 */
class SharesEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function createInvite(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $listId = (string)($args['id'] ?? '');

        return $this->run($Response, static function (ListSharing $Sharing, string $userId) use ($listId): array {
            $created = $Sharing->createInvite($listId, $userId);

            return [
                'token' => $created['token'],
                'invite' => self::presentInvite($created['invite'])
            ];
        }, 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function participants(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $listId = (string)($args['id'] ?? '');

        return $this->run(
            $Response,
            static fn(ListSharing $Sharing, string $userId): array => self::presentParticipants(
                $Sharing->participants($listId, $userId)
            )
        );
    }

    /**
     * Antwort: `{ shares, invites }` wie `GET`, für einen Gast, der die
     * Liste verlässt, `{ status: 'left' }`.
     *
     * @param array<string, string> $args
     */
    public function removeShare(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $listId = (string)($args['id'] ?? '');
        $targetUserId = (string)($args['userId'] ?? '');

        return $this->run(
            $Response,
            static function (ListSharing $Sharing, string $userId) use ($listId, $targetUserId): array {
                $Sharing->removeShare($listId, $targetUserId, $userId);

                if ($targetUserId === $userId) {
                    return ['status' => 'left'];
                }

                return self::presentParticipants($Sharing->participants($listId, $userId));
            }
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function withdrawInvite(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $listId = (string)($args['id'] ?? '');
        $inviteId = (string)($args['inviteId'] ?? '');

        return $this->run(
            $Response,
            static function (ListSharing $Sharing, string $userId) use ($listId, $inviteId): array {
                $Sharing->withdrawInvite($listId, $inviteId, $userId);

                return self::presentParticipants($Sharing->participants($listId, $userId));
            }
        );
    }

    /**
     * `GET /invites/{token}`: `{ invite: { list: { id, name, shopDate },
     * invitedBy, expiresAt }, access: null | 'owner' | 'member' | 'guest' }`.
     *
     * @param array<string, string> $args
     */
    public function inspect(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $token = (string)($args['token'] ?? '');

        return $this->run($Response, static function (ListSharing $Sharing, string $userId) use ($token): array {
            $found = $Sharing->inspect($token, $userId);

            return [
                'invite' => [
                    'list' => [
                        'id' => $found['list']->id,
                        'name' => $found['list']->name,
                        'shopDate' => $found['list']->shopDate
                    ],
                    'invitedBy' => self::profile($found['invite']->createdBy),
                    'expiresAt' => $found['invite']->expiresAt->format(DATE_ATOM)
                ],
                'access' => $found['role']?->value
            ];
        });
    }

    /**
     * `POST /invites/{token}/accept`: `{ status: 'accepted' | 'already', list }`.
     *
     * @param array<string, string> $args
     */
    public function accept(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $token = (string)($args['token'] ?? '');

        return $this->run($Response, static function (ListSharing $Sharing, string $userId) use ($token): array {
            $result = $Sharing->accept($token, $userId);

            return [
                'status' => $result['status'],
                'list' => [...$result['list']->toArray(), 'role' => $result['role']->value]
            ];
        });
    }

    /**
     * Name und Bild eines Benutzers wie im Kopf; ein gelöschter nur mit ID.
     *
     * @return array<string, mixed>
     */
    public static function profile(string $userId): array
    {
        try {
            return (new Profile(QUI::getUsers()->get($userId)))->toPublicArray();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            return ['id' => $userId, 'name' => '?', 'initials' => '?', 'avatarUrl' => null];
        }
    }

    /**
     * @param callable(ListSharing, string): array<string, mixed> $action
     */
    private function run(ResponseInterface $Response, callable $action, int $status = 200): ResponseInterface
    {
        $userId = SessionUser::id();

        try {
            $data = $action(new ListSharing(new ListRepository(), new ShareRepository()), $userId);
        } catch (ListException $Exception) {
            $error = [
                'error' => $Exception->getErrorCode(),
                'message' => $Exception->getMessage()
            ];

            if ($Exception->getReason() !== null) {
                $error['reason'] = $Exception->getReason();
            }

            return Json::respond($Response, $error, $Exception->getHttpStatus());
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The share could not be saved.', 500);
        }

        return Json::respond($Response, $data, $status);
    }

    /**
     * @param array{shares: list<ListShare>, invites: list<ListInvite>} $participants
     * @return array<string, mixed>
     */
    private static function presentParticipants(array $participants): array
    {
        return [
            'shares' => array_map(
                static fn(ListShare $Share): array => [
                    ...self::profile($Share->userId),
                    'invitedBy' => self::profile($Share->invitedBy)['name'],
                    'acceptedAt' => $Share->acceptedAt->format(DATE_ATOM)
                ],
                $participants['shares']
            ),
            'invites' => array_map(self::presentInvite(...), $participants['invites'])
        ];
    }

    /**
     * Offener Link ohne Token (das gibt es nur beim Erzeugen).
     *
     * @return array<string, mixed>
     */
    private static function presentInvite(ListInvite $Invite): array
    {
        return [
            'id' => $Invite->tokenHash,
            'createdBy' => self::profile($Invite->createdBy)['name'],
            'createdAt' => $Invite->createdAt->format(DATE_ATOM),
            'expiresAt' => $Invite->expiresAt->format(DATE_ATOM)
        ];
    }
}
