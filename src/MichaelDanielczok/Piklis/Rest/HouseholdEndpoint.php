<?php

namespace MichaelDanielczok\Piklis\Rest;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Households\Household;
use MichaelDanielczok\Piklis\Households\HouseholdException;
use MichaelDanielczok\Piklis\Households\HouseholdRepository;
use MichaelDanielczok\Piklis\Households\HouseholdService;
use MichaelDanielczok\Piklis\Households\Membership;
use MichaelDanielczok\Piklis\Users\Profile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function array_map;
use function is_array;

use const DATE_ATOM;

/**
 * Haushalt des angemeldeten Benutzers (Regeln in `Households\HouseholdService`).
 *
 * | Route | Wer | Wirkung |
 * | --- | --- | --- |
 * | `GET /household` | alle | `{ household: {…} \| null }` |
 * | `POST /household` `{ name }` | ohne Haushalt | anlegen, Administrator (201) |
 * | `POST /household/join` `{ code, switch?, confirmDissolve? }` | alle | beitreten bzw. wechseln |
 * | `POST /household/invite` | Administratoren | neuer Einladungscode |
 * | `POST /household/leave` `{ confirmDissolve? }` | Mitglieder | austreten |
 * | `DELETE /household` | Administratoren | auflösen (Listen werden gelöscht) |
 * | `PATCH /household/members/{userId}` `{ role }` | Administratoren | ernennen, zurückstufen |
 * | `DELETE /household/members/{userId}` | Administratoren | entfernen |
 *
 * Aktionen antworten wie `GET /household`. Den Einladungscode sehen nur
 * Administratoren.
 */
class HouseholdEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return $this->respond($Response, SessionUser::id());
    }

    /**
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::input($Request);

        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->create($userId, $input['name'] ?? null),
            201
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function join(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::input($Request);

        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->join(
                $userId,
                $input['code'] ?? null,
                ($input['switch'] ?? false) === true,
                ($input['confirmDissolve'] ?? false) === true
            )
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function renewInvite(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->renewInvite($userId)
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function leave(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::input($Request);

        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->leave(
                $userId,
                ($input['confirmDissolve'] ?? false) === true
            )
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function dissolve(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->dissolve($userId)
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function setRole(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::input($Request);
        $memberId = (string)($args['userId'] ?? '');

        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->setRole(
                $userId,
                $memberId,
                $input['role'] ?? null
            )
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function removeMember(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $memberId = (string)($args['userId'] ?? '');

        return $this->run(
            $Response,
            static fn(HouseholdService $Service, string $userId) => $Service->removeMember($userId, $memberId)
        );
    }

    /**
     * Kurzform für `GET /me` und `GET /lists`: `{ id, name, role }` oder null.
     *
     * @return array{id: string, name: string, role: string}|null
     * @throws DBALException
     */
    public static function summary(string $userId): ?array
    {
        $Store = new HouseholdRepository();
        $Membership = $Store->membershipOf($userId);
        $Household = $Membership === null ? null : $Store->find($Membership->householdId);

        if ($Membership === null || $Household === null) {
            return null;
        }

        return [
            'id' => $Household->id,
            'name' => $Household->name,
            'role' => $Membership->role->value
        ];
    }

    /**
     * @param callable(HouseholdService, string): mixed $action
     */
    private function run(ResponseInterface $Response, callable $action, int $status = 200): ResponseInterface
    {
        $userId = SessionUser::id();

        try {
            $action(new HouseholdService(new HouseholdRepository()), $userId);
        } catch (HouseholdException $Exception) {
            return Json::error(
                $Response,
                $Exception->getErrorCode(),
                $Exception->getMessage(),
                $Exception->getHttpStatus()
            );
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The household could not be saved.', 500);
        }

        return $this->respond($Response, $userId, $status);
    }

    private function respond(ResponseInterface $Response, string $userId, int $status = 200): ResponseInterface
    {
        try {
            $Store = new HouseholdRepository();
            $Membership = $Store->membershipOf($userId);
            $Household = $Membership === null ? null : $Store->find($Membership->householdId);
            $data = $Membership === null || $Household === null
                ? null
                : self::present($Household, $Membership, $Store->members($Household->id));
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'The household could not be loaded.', 500);
        }

        return Json::respond($Response, ['household' => $data], $status);
    }

    /**
     * @param list<Membership> $members
     * @return array<string, mixed>
     */
    private static function present(Household $Household, Membership $Own, array $members): array
    {
        $code = $Own->isAdmin() ? $Household->activeInviteCode(new DateTimeImmutable()) : null;

        return [
            'id' => $Household->id,
            'name' => $Household->name,
            'role' => $Own->role->value,
            'createdAt' => $Household->createdAt->format(DATE_ATOM),
            'members' => array_map(
                static fn(Membership $Member): array => [
                    ...self::profile($Member->userId),
                    'role' => $Member->role->value,
                    'joinedAt' => $Member->joinedAt->format(DATE_ATOM)
                ],
                $members
            ),
            'invite' => $code === null ? null : [
                'code' => $code,
                'expiresAt' => $Household->inviteExpiresAt?->format(DATE_ATOM)
            ]
        ];
    }

    /**
     * Name und Bild wie im Kopf; ein gelöschter Benutzer nur mit ID.
     *
     * @return array<string, mixed>
     */
    private static function profile(string $userId): array
    {
        try {
            return (new Profile(QUI::getUsers()->get($userId)))->toPublicArray();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            return ['id' => $userId, 'name' => '?', 'initials' => '?', 'avatarUrl' => null];
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function input(ServerRequestInterface $Request): array
    {
        $input = $Request->getParsedBody();

        return is_array($input) ? $input : [];
    }
}
