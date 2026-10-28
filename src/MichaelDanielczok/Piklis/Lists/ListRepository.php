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
use function implode;
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
     * Besitzer persönlicher Listen, Mitglieder des Haushalts und eingeladene
     * Gäste (`pk_list_shares`), die stärkste Rolle zählt (`ListAccess`).
     *
     * @throws DBALException
     */
    public function roleOf(ListState $list, string $userId): ?ListRole
    {
        if ($list->ownerUser !== null && $list->ownerUser === $userId) {
            return ListRole::Owner;
        }

        $householdId = $list->householdId === null ? null : $this->householdIdOf($userId);

        return ListAccess::role($list, $userId, $householdId, $this->isGuest($list->id, $userId));
    }

    /**
     * Hat der Benutzer eine angenommene Einladung zu dieser Liste?
     *
     * @throws DBALException
     */
    public function isGuest(string $listId, string $userId): bool
    {
        $found = $this->Connection->createQueryBuilder()
            ->select('list_id')
            ->from(Tables::name(Tables::LIST_SHARES))
            ->where('list_id = :list')
            ->andWhere('user_id = :user')
            ->setParameter('list', $listId)
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchOne();

        return $found !== false;
    }

    /**
     * @throws DBALException
     */
    public function isHouseholdMember(string $userId, string $householdId): bool
    {
        return $this->householdIdOf($userId) === $householdId;
    }

    /**
     * @throws DBALException
     */
    public function householdIdOf(string $userId): ?string
    {
        $householdId = $this->Connection->createQueryBuilder()
            ->select('household_id')
            ->from(Tables::name(Tables::HOUSEHOLD_MEMBERS))
            ->where('user_id = :user')
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($householdId) && $householdId !== '' ? $householdId : null;
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
    public function productExists(string $productId): bool
    {
        $found = $this->Connection->createQueryBuilder()
            ->select('id')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('id = :id')
            ->setParameter('id', $productId)
            ->executeQuery()
            ->fetchOne();

        return $found !== false;
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
     * Listen, auf die der Benutzer Zugriff hat (eigene persönliche, die
     * seines Haushalts und die mit ihm geteilten), ohne gelöschte, mit Rolle,
     * Zählern der sichtbaren Einträge je Zustand, den Produkten der ersten
     * offenen Einträge (Vorschau in der Übersicht) und bei Gästen, wer
     * eingeladen hat (`sharedBy`, Benutzer-ID).
     *
     * @return list<array{list: ListState, role: ListRole, counts: array<string, int>, preview: list<string>, sharedBy: string|null}>
     * @throws DBALException
     */
    public function listsForUser(string $userId): array
    {
        $householdId = $this->householdIdOf($userId);

        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LISTS))
            ->where($this->accessibleCondition($householdId))
            ->andWhere('deleted_at IS NULL')
            ->setParameter('user', $userId)
            ->orderBy('updated_at', 'DESC');

        if ($householdId !== null) {
            $Query->setParameter('household', $householdId);
        }

        $rows = $Query->executeQuery()->fetchAllAssociative();

        $lists = [];

        foreach ($rows as $row) {
            $List = $this->listFromRow($row);
            $lists[$List->id] = $List;
        }

        $counts = $this->countEntries(array_keys($lists));
        $previews = $this->previewProducts(array_keys($lists));
        $invitedBy = $this->sharesOfUser($userId);
        $result = [];

        foreach ($lists as $listId => $List) {
            $Role = ListAccess::role($List, $userId, $householdId, isset($invitedBy[$listId])) ?? ListRole::Owner;

            $result[] = [
                'list' => $List,
                'role' => $Role,
                'counts' => $counts[$listId] ?? self::emptyCounts(),
                'preview' => $previews[$listId] ?? [],
                'sharedBy' => $Role === ListRole::Guest ? $invitedBy[$listId] : null
            ];
        }

        return $result;
    }

    /**
     * Nutzung von Produkten für die Vorschläge (`Products\SuggestionRanking`):
     * sichtbare Einträge aller Listen, auf die der Benutzer Zugriff hat
     * (eigene persönliche, die seines Haushalts und mit ihm geteilte, wie
     * `listsForUser`), ohne gelöschte Listen
     * und ohne ausgeblendete Produkte. Zeitpunkt: abgehakt = wann gekauft,
     * sonst wann hinzugefügt; nur ab `$since`.
     *
     * @return list<array{product_id: string, list_id: string, used_at: DateTimeImmutable}>
     * @throws DBALException
     */
    public function productUsagesForUser(string $userId, DateTimeImmutable $since): array
    {
        $sinceText = $since->format(Tables::DATETIME_FORMAT);
        $householdId = $this->householdIdOf($userId);

        $Query = $this->Connection->createQueryBuilder()
            ->select('e.product_id', 'e.list_id', 'e.status', 'e.status_changed_at', 'e.created_at')
            ->from(Tables::name(Tables::LIST_ENTRIES), 'e')
            ->innerJoin('e', Tables::name(Tables::LISTS), 'l', 'l.id = e.list_id')
            ->innerJoin('e', Tables::name(Tables::PRODUCTS), 'p', 'p.id = e.product_id')
            ->where($this->accessibleCondition($householdId, 'l.'))
            ->andWhere('l.deleted_at IS NULL')
            ->andWhere('e.removed_at IS NULL')
            ->andWhere('e.merged_into IS NULL')
            ->andWhere('p.hidden = 0')
            ->andWhere('(e.created_at >= :since OR e.status_changed_at >= :since)')
            ->setParameter('user', $userId)
            ->setParameter('since', $sinceText);

        if ($householdId !== null) {
            $Query->setParameter('household', $householdId);
        }

        $rows = $Query->executeQuery()->fetchAllAssociative();

        $usages = [];

        foreach ($rows as $row) {
            $usedAt = self::date($row['created_at']);

            if ($row['status'] === EntryStatus::Done->value) {
                $usedAt = self::date($row['status_changed_at'] ?? null) ?? $usedAt;
            }

            if ($usedAt === null || $usedAt < $since) {
                continue;
            }

            $usages[] = [
                'product_id' => (string)$row['product_id'],
                'list_id' => (string)$row['list_id'],
                'used_at' => $usedAt
            ];
        }

        return $usages;
    }

    /**
     * Bedingung „Liste ist zugänglich“: eigene persönliche, (mit Haushalt)
     * die des Haushalts oder mit dem Benutzer geteilte. Parameter `:user`
     * und `:household`.
     */
    private function accessibleCondition(?string $householdId, string $alias = ''): string
    {
        $conditions = [
            $alias . 'owner_user = :user',
            $alias . 'id IN (SELECT s.list_id FROM ' . Tables::name(Tables::LIST_SHARES) . ' s WHERE s.user_id = :user)'
        ];

        if ($householdId !== null) {
            $conditions[] = $alias . 'household_id = :household';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Mit dem Benutzer geteilte Listen: Liste => wer eingeladen hat.
     *
     * @return array<string, string>
     * @throws DBALException
     */
    private function sharesOfUser(string $userId): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('list_id', 'invited_by')
            ->from(Tables::name(Tables::LIST_SHARES))
            ->where('user_id = :user')
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchAllAssociative();

        $shares = [];

        foreach ($rows as $row) {
            $shares[(string)$row['list_id']] = (string)$row['invited_by'];
        }

        return $shares;
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
     * Produkte der ersten offenen Einträge je Liste (`ListPreview`).
     *
     * @param list<string> $listIds
     * @return array<string, list<string>>
     * @throws DBALException
     */
    private function previewProducts(array $listIds): array
    {
        if (count($listIds) === 0) {
            return [];
        }

        $rows = $this->Connection->createQueryBuilder()
            ->select('list_id', 'id', 'product_id', 'sort_key')
            ->from(Tables::name(Tables::LIST_ENTRIES))
            ->where('list_id IN (:lists)')
            ->andWhere('status = :status')
            ->andWhere('removed_at IS NULL')
            ->andWhere('merged_into IS NULL')
            ->setParameter('lists', $listIds, ArrayParameterType::STRING)
            ->setParameter('status', EntryStatus::Open->value)
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = [
                'list_id' => (string)$row['list_id'],
                'id' => (string)$row['id'],
                'product_id' => (string)$row['product_id'],
                'sort_key' => (string)$row['sort_key']
            ];
        }

        return ListPreview::firstProducts($entries);
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
