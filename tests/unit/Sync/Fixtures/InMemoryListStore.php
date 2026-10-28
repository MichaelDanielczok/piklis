<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures;

use MichaelDanielczok\Piklis\Lists\ListAccess;
use MichaelDanielczok\Piklis\Sync\ChangeRecord;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStore;

use function in_array;

/**
 * Speicher im Arbeitsspeicher für Tests ohne Datenbank. Rollen wie in der
 * App: Besitzer persönlicher Listen, Haushaltsmitglieder, eingeladene Gäste.
 */
final class InMemoryListStore implements ListStore
{
    /** @var array<string, ListState> */
    public array $lists = [];

    /** @var list<ChangeRecord> */
    public array $changes = [];

    /** @var array<string, list<string>> Haushalt => Benutzer */
    public array $householdMembers = [];

    /** @var array<string, list<string>> Liste => eingeladene Benutzer */
    public array $guests = [];

    /** @var list<string> Produkt-IDs, die es nicht gibt (alle anderen gibt es) */
    public array $unknownProducts = [];

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }

    public function findOperationResult(string $operationId): ?array
    {
        foreach ($this->changes as $Change) {
            if ($Change->operationId === $operationId) {
                return $Change->result;
            }
        }

        return null;
    }

    public function loadList(string $listId, bool $forUpdate): ?ListState
    {
        return $this->lists[$listId] ?? null;
    }

    public function roleOf(ListState $list, string $userId): ?ListRole
    {
        return ListAccess::role(
            $list,
            $userId,
            $this->householdIdOf($userId),
            in_array($userId, $this->guests[$list->id] ?? [], true)
        );
    }

    public function isHouseholdMember(string $userId, string $householdId): bool
    {
        return in_array($userId, $this->householdMembers[$householdId] ?? [], true);
    }

    public function householdIdOf(string $userId): ?string
    {
        foreach ($this->householdMembers as $householdId => $members) {
            if (in_array($userId, $members, true)) {
                return (string)$householdId;
            }
        }

        return null;
    }

    public function listIdOfEntry(string $entryId): ?string
    {
        foreach ($this->lists as $List) {
            if ($List->entry($entryId) !== null) {
                return $List->id;
            }
        }

        return null;
    }

    public function productExists(string $productId): bool
    {
        return !in_array($productId, $this->unknownProducts, true);
    }

    public function save(ListState $list, ChangeRecord $change): void
    {
        $this->lists[$list->id] = $list;
        $this->changes[] = $change;

        $list->markSaved();
    }
}
