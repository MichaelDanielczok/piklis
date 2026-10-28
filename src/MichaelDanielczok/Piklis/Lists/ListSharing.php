<?php

namespace MichaelDanielczok\Piklis\Lists;

use Closure;
use DateTimeImmutable;
use MichaelDanielczok\Piklis\Sync\ListPermissions;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStore;

/**
 * Einzelne Listen mit Außenstehenden teilen (Konzept „Einzelne Listen mit
 * Außenstehenden teilen“), ohne HTTP und ohne Datenbank:
 *
 * - **Link erzeugen:** Besitzer persönlicher Listen und Mitglieder bei
 *   Haushaltslisten (`ListPermissions::canManageSharing`), nicht Gäste.
 *   Einmal verwendbar, 7 Tage gültig; gespeichert wird nur der Hash.
 * - **Annehmen:** mit vorhandenem Konto; danach ist der Benutzer Gast an
 *   dieser Liste (`pk_list_shares`), der Link ist verbraucht. Wer schon
 *   Zugriff hat (Besitzer, Mitglied, Gast), verbraucht ihn nicht.
 *   Benutzte, abgelaufene, zurückgezogene Links, gelöschte Listen und Links
 *   von jemandem, der die Liste nicht mehr teilen darf: `invite_invalid`.
 * - **Teilnehmer und offene Links** sehen und entfernen: wer teilen darf.
 *   Ein Gast darf sich selbst entfernen (Liste verlassen).
 *
 * Gäste arbeiten über Operationen mit (`ListPermissions::allows`); die Liste
 * verschwindet nach dem Entfernen beim nächsten Abgleich.
 */
final class ListSharing
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_ALREADY = 'already';

    /** @var Closure(): string */
    private Closure $newToken;

    /**
     * @param (Closure(): string)|null $newToken Token eines neuen Links (Tests: fest)
     */
    public function __construct(
        private readonly ListStore $Lists,
        private readonly ShareStore $Shares,
        ?Closure $newToken = null
    ) {
        $this->newToken = $newToken ?? static fn(): string => InviteToken::generate();
    }

    /**
     * Neuer Einladungslink. Das Token gibt es nur in dieser Antwort.
     *
     * @return array{token: string, invite: ListInvite}
     * @throws ListException
     */
    public function createInvite(string $listId, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        return $this->Lists->transactional(function () use ($listId, $userId, $now): array {
            $this->managedList($listId, $userId);

            $token = ($this->newToken)();
            $Invite = new ListInvite(
                InviteToken::hash($token),
                $listId,
                $userId,
                $now,
                $now->modify('+' . InviteToken::VALID_DAYS . ' days')
            );

            $this->Shares->insertInvite($Invite);

            return ['token' => $token, 'invite' => $Invite];
        });
    }

    /**
     * Einladung ansehen, bevor man annimmt: Liste, wer eingeladen hat, und
     * die eigene Rolle an der Liste (null = noch kein Zugriff).
     *
     * @return array{invite: ListInvite, list: ListState, role: ListRole|null}
     * @throws ListException
     */
    public function inspect(mixed $token, string $userId, ?DateTimeImmutable $now = null): array
    {
        return $this->usableInvite($token, $userId, $now ?? new DateTimeImmutable(), false);
    }

    /**
     * Einladung annehmen: Gast an der Liste, der Link ist danach verbraucht.
     * Mit vorhandenem Zugriff `already`, der Link bleibt offen.
     *
     * @return array{status: string, list: ListState, role: ListRole}
     * @throws ListException
     */
    public function accept(mixed $token, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        return $this->Lists->transactional(function () use ($token, $userId, $now): array {
            $found = $this->usableInvite($token, $userId, $now, true);
            $Invite = $found['invite'];
            $List = $found['list'];

            if ($found['role'] !== null) {
                return ['status' => self::STATUS_ALREADY, 'list' => $List, 'role' => $found['role']];
            }

            $this->Shares->insertShare(new ListShare($List->id, $userId, $Invite->createdBy, $now));

            $Invite->usedAt = $now;
            $Invite->usedBy = $userId;
            $this->Shares->markInviteUsed($Invite);

            return ['status' => self::STATUS_ACCEPTED, 'list' => $List, 'role' => ListRole::Guest];
        });
    }

    /**
     * Teilnehmer und offene Links einer Liste (nur wer teilen darf).
     *
     * @return array{shares: list<ListShare>, invites: list<ListInvite>}
     * @throws ListException
     */
    public function participants(string $listId, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $this->managedList($listId, $userId);

        return [
            'shares' => $this->Shares->shares($listId),
            'invites' => $this->Shares->openInvites($listId, $now)
        ];
    }

    /**
     * Freigabe entziehen (wer teilen darf) oder als Gast die Liste verlassen
     * (`$targetUserId` = man selbst).
     *
     * @throws ListException
     */
    public function removeShare(string $listId, string $targetUserId, string $userId): void
    {
        $this->Lists->transactional(function () use ($listId, $targetUserId, $userId): void {
            if ($targetUserId !== $userId) {
                $this->managedList($listId, $userId);
            } else {
                $this->existingList($listId);
            }

            if ($this->Shares->findShare($listId, $targetUserId) === null) {
                throw new ListException('share_not_found', 'This person has no share of the list.', 404);
            }

            $this->Shares->deleteShare($listId, $targetUserId);
        });
    }

    /**
     * Offenen Link zurückziehen: Er funktioniert danach nicht mehr.
     *
     * @throws ListException
     */
    public function withdrawInvite(string $listId, string $inviteId, string $userId): void
    {
        $this->Lists->transactional(function () use ($listId, $inviteId, $userId): void {
            $this->managedList($listId, $userId);

            $Invite = $this->Shares->findInvite($inviteId, true);

            if ($Invite === null || $Invite->listId !== $listId || $Invite->isUsed()) {
                throw new ListException('invite_not_found', 'No open invite with this id.', 404);
            }

            $this->Shares->deleteInvite($Invite->tokenHash);
        });
    }

    /**
     * @return array{invite: ListInvite, list: ListState, role: ListRole|null}
     * @throws ListException
     */
    private function usableInvite(mixed $token, string $userId, DateTimeImmutable $now, bool $forUpdate): array
    {
        $Invite = InviteToken::isWellFormed($token)
            ? $this->Shares->findInvite(InviteToken::hash((string)$token), $forUpdate)
            : null;

        if ($Invite === null) {
            throw self::invalidInvite('unknown', 404);
        }

        $List = $this->Lists->loadList($Invite->listId, false);

        if ($List === null || $List->isDeleted()) {
            throw self::invalidInvite('list_deleted');
        }

        $Role = $this->Lists->roleOf($List, $userId);

        // wer schon Zugriff hat, kommt einfach zur Liste (auch über einen alten Link)
        if ($Role !== null) {
            return ['invite' => $Invite, 'list' => $List, 'role' => $Role];
        }

        if ($Invite->isUsed()) {
            throw self::invalidInvite('used');
        }

        if ($Invite->isExpired($now)) {
            throw self::invalidInvite('expired');
        }

        $CreatorRole = $this->Lists->roleOf($List, $Invite->createdBy);

        if ($CreatorRole === null || !ListPermissions::canManageSharing($CreatorRole)) {
            throw self::invalidInvite('revoked');
        }

        return ['invite' => $Invite, 'list' => $List, 'role' => null];
    }

    /**
     * Liste, deren Freigaben der Benutzer verwalten darf.
     *
     * @throws ListException
     */
    private function managedList(string $listId, string $userId): ListState
    {
        $List = $this->existingList($listId);
        $Role = $this->Lists->roleOf($List, $userId);

        if ($Role === null) {
            throw new ListException('no_access', 'No access to this list.', 403);
        }

        if (!ListPermissions::canManageSharing($Role)) {
            throw new ListException('forbidden', 'Guests may not share the list or manage its shares.', 403);
        }

        return $List;
    }

    /**
     * @throws ListException
     */
    private function existingList(string $listId): ListState
    {
        $List = $this->Lists->loadList($listId, false);

        if ($List === null) {
            throw new ListException('list_not_found', 'The list does not exist.', 404);
        }

        if ($List->isDeleted()) {
            throw new ListException('list_deleted', 'The list has been deleted.', 410);
        }

        return $List;
    }

    private static function invalidInvite(string $reason, int $status = 410): ListException
    {
        return new ListException('invite_invalid', 'The invite link is no longer valid.', $status, $reason);
    }
}
