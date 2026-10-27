<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Database\Tables;
use MichaelDanielczok\Piklis\Sync\ChangeRecord;
use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\EntryStatus;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStatus;
use MichaelDanielczok\Piklis\Sync\ListStore;
use QUI;

use function array_keys;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Listen, Einträge und Änderungsprotokoll in der Datenbank
 * (`pk_lists`, `pk_list_entries`, `pk_list_changes`).
 *
 * Schreibende Operationen sperren die Listenzeile (`SELECT … FOR UPDATE`),
 * damit Revisionen einer Liste lückenlos und in Eingangsreihenfolge
 * vergeben werden.
 */
class ListRepository implements ListStore
{
    private Connection $Connection;

    public function __construct(?Connection $Connection = null)
    {
        $this->Connection = $Connection ?? QUI::getDataBaseConnection();
    }

    public function transactional(callable $callback): mixed
    {
        return $this->Connection->transactional(static fn(): mixed => $callback());
    }

    /**
     * @throws DBALException
     */
    public function findOperationResult(string $operationId): ?array
    {
        $result = $this->Connection->createQueryBuilder()
            ->select('result')
            ->from(Tables::name(Tables::LIST_CHANGES))
            ->where('operation_id = :id')
            ->setParameter('id', $operationId)
            ->executeQuery()
            ->fetchOne();

        if (!is_string($result)) {
            return null;
        }

        $data = json_decode($result, true);

        /** @var array<string, mixed>|null $data */
        return is_array($data) ? $data : null;
    }

    /**
     * @throws DBALException
     */
    public function loadList(string $listId, bool $forUpdate): ?ListState
    {
        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LISTS))
            ->where('id = :id')
            ->setParameter('id', $listId);

        if ($forUpdate) {
            $Query->forUpdate();
        }

        $row = $Query->executeQuery()->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $List = $this->listFromRow($row);

        $entries = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LIST_ENTRIES))
            ->where('list_id = :id')
            ->setParameter('id', $listId)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($entries as $entry) {
            $List->loadEntry($this->entryFromRow($entry));
        }

        return $List;
    }

    /**
     * Besitzer persönlicher Listen. Haushaltsmitglieder (Schritt 9) und
     * eingeladene Teilnehmer (Schritt 11) kommen mit ihren Tabellen dazu.
     */
    public function roleOf(ListState $list, string $userId): ?ListRole
    {
        if ($list->ownerUser !== null && $list->ownerUser === $userId) {
            return ListRole::Owner;
        }

        if ($list->householdId !== null && $this->isHouseholdMember($userId, $list->householdId)) {
            return ListRole::Member;
        }

        return null;
    }

    /**
     * Haushalte gibt es ab Schritt 9 (`pk_household_members`).
     */
    public function isHouseholdMember(string $userId, string $householdId): bool
    {
        return false;
    }

    /**
     * @throws DBALException
     */
    public function listIdOfEntry(string $entryId): ?string
    {
        $listId = $this->Connection->createQueryBuilder()
            ->select('list_id')
            ->from(Tables::name(Tables::LIST_ENTRIES))
            ->where('id = :id')
            ->setParameter('id', $entryId)
            ->executeQuery()
            ->fetchOne();

        return is_string($listId) ? $listId : null;
    }

    /**
     * @throws DBALException
     */
    public function save(ListState $list, ChangeRecord $change): void
    {
        if ($list->isNew) {
            $this->Connection->insert(Tables::name(Tables::LISTS), $this->listToRow($list));
        } elseif ($list->isDirty()) {
            $this->Connection->update(Tables::name(Tables::LISTS), $this->listToRow($list), ['id' => $list->id]);
        }

        foreach ($list->dirtyEntries() as $Entry) {
            if ($Entry->isNew) {
                $this->Connection->insert(Tables::name(Tables::LIST_ENTRIES), $this->entryToRow($Entry));
                continue;
            }

            $this->Connection->update(
                Tables::name(Tables::LIST_ENTRIES),
                $this->entryToRow($Entry),
                ['id' => $Entry->id]
            );
        }

        $this->Connection->insert(Tables::name(Tables::LIST_CHANGES), [
            'operation_id' => $change->operationId,
            'list_id' => $change->listId,
            'revision' => $change->revision,
            'type' => $change->type,
            'entry_id' => $change->entryId,
            'user_id' => $change->userId,
            'created_at' => $change->createdAt->format(Tables::DATETIME_FORMAT),
            'payload' => self::json($change->payload),
            'result' => self::json($change->result)
        ]);

        $list->markSaved();
    }

    /**
     * Listen, auf die der Benutzer Zugriff hat (vorerst: eigene persönliche),
     * ohne gelöschte, mit Zählern der sichtbaren Einträge je Zustand.
     *
     * @return list<array{list: ListState, role: ListRole, counts: array<string, int>}>
     * @throws DBALException
     */
    public function listsForUser(string $userId): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LISTS))
            ->where('owner_user = :user')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('user', $userId)
            ->orderBy('updated_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $lists = [];

        foreach ($rows as $row) {
            $List = $this->listFromRow($row);
            $lists[$List->id] = $List;
        }

        $counts = $this->countEntries(array_keys($lists));
        $result = [];

        foreach ($lists as $listId => $List) {
            $result[] = [
                'list' => $List,
                'role' => ListRole::Owner,
                'counts' => $counts[$listId] ?? self::emptyCounts()
            ];
        }

        return $result;
    }

    /**
     * Sichtbare Einträge je Liste und Zustand.
     *
     * @param list<string> $listIds
     * @return array<string, array<string, int>>
     * @throws DBALException
     */
    private function countEntries(array $listIds): array
    {
        if (count($listIds) === 0) {
            return [];
        }

        $rows = $this->Connection->createQueryBuilder()
            ->select('list_id', 'status', 'COUNT(*) AS amount')
            ->from(Tables::name(Tables::LIST_ENTRIES))
            ->where('list_id IN (:lists)')
            ->andWhere('removed_at IS NULL')
            ->andWhere('merged_into IS NULL')
            ->setParameter('lists', $listIds, ArrayParameterType::STRING)
            ->groupBy('list_id', 'status')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];

        foreach ($rows as $row) {
            $listId = (string)$row['list_id'];
            $status = EntryStatus::tryFrom((string)$row['status']) ?? EntryStatus::Open;
            $counts[$listId] ??= self::emptyCounts();
            $counts[$listId][$status->value] += (int)$row['amount'];
            $counts[$listId]['total'] += (int)$row['amount'];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private static function emptyCounts(): array
    {
        return [
            'open' => 0,
            'done' => 0,
            'notGot' => 0,
            'total' => 0
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function listFromRow(array $row): ListState
    {
        return new ListState(
            (string)$row['id'],
            (string)$row['name'],
            self::nullableString($row['shop_date'] ?? null),
            self::nullableString($row['owner_user'] ?? null),
            self::nullableString($row['household_id'] ?? null),
            ListStatus::tryFrom((string)$row['status']) ?? ListStatus::Active,
            self::date($row['completed_at'] ?? null),
            self::nullableString($row['completed_by'] ?? null),
            (bool)$row['changed_after_completion'],
            (int)$row['revision'],
            self::date($row['created_at']) ?? new DateTimeImmutable(),
            (string)$row['created_by'],
            self::date($row['updated_at']) ?? new DateTimeImmutable(),
            (string)$row['updated_by'],
            self::date($row['deleted_at'] ?? null),
            self::nullableString($row['deleted_by'] ?? null)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listToRow(ListState $List): array
    {
        return [
            'id' => $List->id,
            'name' => $List->name,
            'shop_date' => $List->shopDate,
            'owner_user' => $List->ownerUser,
            'household_id' => $List->householdId,
            'status' => $List->status->value,
            'completed_at' => $List->completedAt?->format(Tables::DATETIME_FORMAT),
            'completed_by' => $List->completedBy,
            'changed_after_completion' => $List->changedAfterCompletion ? 1 : 0,
            'revision' => $List->revision,
            'created_by' => $List->createdBy,
            'created_at' => $List->createdAt->format(Tables::DATETIME_FORMAT),
            'updated_by' => $List->updatedBy,
            'updated_at' => $List->updatedAt->format(Tables::DATETIME_FORMAT),
            'deleted_at' => $List->deletedAt?->format(Tables::DATETIME_FORMAT),
            'deleted_by' => $List->deletedBy
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entryFromRow(array $row): EntryState
    {
        $checked = null;

        if (is_numeric($row['checked_amount'] ?? null)) {
            $checked = Quantity::fromDatabase($row['checked_amount'], $row['checked_unit'] ?? null);
        }

        return new EntryState(
            (string)$row['id'],
            (string)$row['list_id'],
            (string)$row['product_id'],
            Quantity::fromDatabase($row['amount'], $row['unit'] ?? null),
            (string)$row['note'],
            (string)$row['sort_key'],
            EntryStatus::tryFrom((string)$row['status']) ?? EntryStatus::Open,
            $checked,
            self::date($row['status_changed_at'] ?? null),
            self::nullableString($row['status_changed_by'] ?? null),
            self::nullableString($row['transferred_to'] ?? null),
            self::date($row['removed_at'] ?? null),
            self::nullableString($row['removed_by'] ?? null),
            self::nullableString($row['merged_into'] ?? null),
            (bool)$row['changed_after_completion'],
            (int)$row['revision'],
            self::date($row['created_at']) ?? new DateTimeImmutable(),
            (string)$row['created_by'],
            self::date($row['updated_at']) ?? new DateTimeImmutable(),
            (string)$row['updated_by']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entryToRow(EntryState $Entry): array
    {
        return [
            'id' => $Entry->id,
            'list_id' => $Entry->listId,
            'product_id' => $Entry->productId,
            'amount' => $Entry->quantity->toDatabase(),
            'unit' => $Entry->quantity->unit(),
            'note' => $Entry->note,
            'sort_key' => $Entry->sortKey,
            'status' => $Entry->status->value,
            'checked_amount' => $Entry->checkedQuantity?->toDatabase(),
            'checked_unit' => $Entry->checkedQuantity?->unit(),
            'status_changed_at' => $Entry->statusChangedAt?->format(Tables::DATETIME_FORMAT),
            'status_changed_by' => $Entry->statusChangedBy,
            'transferred_to' => $Entry->transferredTo,
            'removed_at' => $Entry->removedAt?->format(Tables::DATETIME_FORMAT),
            'removed_by' => $Entry->removedBy,
            'merged_into' => $Entry->mergedInto,
            'changed_after_completion' => $Entry->changedAfterCompletion ? 1 : 0,
            'revision' => $Entry->revision,
            'created_by' => $Entry->createdBy,
            'created_at' => $Entry->createdAt->format(Tables::DATETIME_FORMAT),
            'updated_by' => $Entry->updatedBy,
            'updated_at' => $Entry->updatedAt->format(Tables::DATETIME_FORMAT)
        ];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
