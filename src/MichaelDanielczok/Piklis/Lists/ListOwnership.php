<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Support\Uuid;
use MichaelDanielczok\Piklis\Sync\ChangeRecord;
use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStatus;
use MichaelDanielczok\Piklis\Sync\ListStore;

/**
 * Zugehörigkeit einer Liste ändern (REST, nicht über Operationen):
 *
 * - **In den Haushalt verschieben:** nur der Besitzer einer persönlichen
 *   Liste, der einem Haushalt angehört. Die ID bleibt (ausstehende
 *   Operationen gelten weiter), die Liste gehört danach dem Haushalt und
 *   bleibt dort, auch wenn der Besitzer austritt. +1 Revision.
 * - **Kopieren:** eine neue, unabhängige Liste mit der ID aus dem Browser
 *   (idempotent) und neuen Eintrags-IDs; das Original bleibt. Persönliche
 *   Listen in den Haushalt (Besitzer), Haushaltslisten als persönliche Kopie
 *   (Mitglieder, z. B. vor dem Austritt). Name, Datum, Abschlussstand und die
 *   sichtbaren Einträge mit Menge, Notiz, Position und Zustand werden
 *   übernommen.
 *
 * Gäste (Schritt 11) dürfen beides nicht. Beides steht im
 * Änderungsprotokoll (`list.move`, `list.copy`).
 */
final class ListOwnership
{
    public const TO_HOUSEHOLD = 'household';
    public const TO_PERSONAL = 'personal';

    public function __construct(private readonly ListStore $Store)
    {
    }

    /**
     * @throws ListException
     */
    public function moveToHousehold(string $listId, string $userId, ?DateTimeImmutable $now = null): ListState
    {
        $now ??= new DateTimeImmutable();

        return $this->Store->transactional(function () use ($listId, $userId, $now): ListState {
            $List = $this->accessibleList($listId, $userId, true);
            $Role = $this->Store->roleOf($List, $userId);

            if ($Role !== ListRole::Owner) {
                throw new ListException('forbidden', 'Only the owner of a personal list may move it.', 403);
            }

            $householdId = $this->Store->householdIdOf($userId);

            if ($householdId === null) {
                throw self::noHousehold();
            }

            $List->householdId = $householdId;
            $List->ownerUser = null;
            $List->updatedAt = $now;
            $List->updatedBy = $userId;
            $revision = $List->nextRevision();

            $this->Store->save($List, new ChangeRecord(
                Uuid::random(),
                $List->id,
                'list.move',
                null,
                $userId,
                $now,
                $revision,
                ['to' => self::TO_HOUSEHOLD, 'householdId' => $householdId],
                ['status' => 'applied', 'revision' => $revision, 'list' => $List->toArray()]
            ));

            return $List;
        });
    }

    /**
     * @return array{status: string, list: ListState} `created` oder `unchanged`
     *         (dieselbe neue ID noch einmal vom selben Benutzer)
     * @throws ListException
     */
    public function copy(
        string $listId,
        mixed $newListId,
        mixed $to,
        string $userId,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable();

        if (!Uuid::isValid($newListId)) {
            throw self::invalid('The new list needs a UUID.');
        }

        if ($to !== self::TO_HOUSEHOLD && $to !== self::TO_PERSONAL) {
            throw self::invalid('Copy to "household" or "personal".');
        }

        /** @var string $newListId */
        return $this->Store->transactional(function () use ($listId, $newListId, $to, $userId, $now): array {
            $Existing = $this->Store->loadList($newListId, true);

            if ($Existing !== null) {
                if ($Existing->createdBy === $userId && !$Existing->isDeleted()) {
                    return ['status' => 'unchanged', 'list' => $Existing];
                }

                throw new ListException('list_exists', 'A list with this id already exists.', 409);
            }

            $Source = $this->accessibleList($listId, $userId, false);
            $Role = $this->Store->roleOf($Source, $userId);
            $householdId = null;

            if ($to === self::TO_HOUSEHOLD) {
                if ($Role !== ListRole::Owner) {
                    throw self::invalid('Only personal lists can be copied into the household.');
                }

                $householdId = $this->Store->householdIdOf($userId);

                if ($householdId === null) {
                    throw self::noHousehold();
                }
            } elseif ($Role !== ListRole::Member) {
                throw $Role === ListRole::Guest
                    ? new ListException('forbidden', 'Guests may not copy the list.', 403)
                    : self::invalid('Only household lists can be copied as a personal list.');
            }

            $Copy = $this->copyOf($Source, $newListId, $householdId, $userId, $now);

            $this->Store->save($Copy, new ChangeRecord(
                Uuid::random(),
                $Copy->id,
                'list.copy',
                null,
                $userId,
                $now,
                $Copy->revision,
                ['to' => $to, 'fromListId' => $Source->id],
                ['status' => 'applied', 'revision' => $Copy->revision, 'list' => $Copy->toArray()]
            ));

            return ['status' => 'created', 'list' => $Copy];
        });
    }

    private function copyOf(
        ListState $Source,
        string $newListId,
        ?string $householdId,
        string $userId,
        DateTimeImmutable $now
    ): ListState {
        $Copy = ListState::create($newListId, $Source->name, $Source->shopDate, $householdId, $userId, $now);

        if ($Source->status === ListStatus::Completed) {
            $Copy->status = ListStatus::Completed;
            $Copy->completedAt = $Source->completedAt;
            $Copy->completedBy = $Source->completedBy;
        }

        $revision = $Copy->nextRevision();

        foreach ($Source->visibleEntries() as $Entry) {
            $New = EntryState::create(
                Uuid::random(),
                $newListId,
                $Entry->productId,
                $Entry->quantity,
                $Entry->note,
                $Entry->sortKey,
                $userId,
                $now
            );

            $New->status = $Entry->status;
            $New->checkedQuantity = $Entry->checkedQuantity;
            $New->statusChangedAt = $Entry->statusChangedAt;
            $New->statusChangedBy = $Entry->statusChangedBy;
            $New->revision = $revision;

            $Copy->addEntry($New);
        }

        return $Copy;
    }

    /**
     * @throws ListException
     */
    private function accessibleList(string $listId, string $userId, bool $forUpdate): ListState
    {
        $List = $this->Store->loadList($listId, $forUpdate);

        if ($List === null) {
            throw new ListException('list_not_found', 'The list does not exist.', 404);
        }

        if ($List->isDeleted()) {
            throw new ListException('list_deleted', 'The list has been deleted.', 410);
        }

        if ($this->Store->roleOf($List, $userId) === null) {
            throw new ListException('no_access', 'No access to this list.', 403);
        }

        return $List;
    }

    private static function noHousehold(): ListException
    {
        return new ListException('no_household', 'Not a member of a household.', 409);
    }

    private static function invalid(string $message): ListException
    {
        return new ListException('invalid_request', $message, 400);
    }
}
