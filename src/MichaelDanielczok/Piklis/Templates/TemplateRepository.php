<?php

namespace MichaelDanielczok\Piklis\Templates;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Database\Tables;
use MichaelDanielczok\Piklis\Lists\Quantity;
use MichaelDanielczok\Piklis\Sync\ListRole;
use QUI;

use function array_diff;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function is_string;

/**
 * Vorlagen in `pk_templates` und `pk_template_entries`. Speichern ersetzt
 * alle Einträge einer Vorlage; die Kopfzeile wird beim Speichern gesperrt
 * (`SELECT … FOR UPDATE`), damit die Revision gleichzeitiges Speichern
 * sicher erkennt.
 */
class TemplateRepository implements TemplateStore
{
    public const PREVIEW_SIZE = 4;

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
    public function load(string $templateId, bool $forUpdate = false): ?TemplateState
    {
        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::TEMPLATES))
            ->where('id = :id')
            ->setParameter('id', $templateId);

        if ($forUpdate) {
            $Query->forUpdate();
        }

        $row = $Query->executeQuery()->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $Template = self::templateFromRow($row);
        $entries = $this->entriesOf([$templateId]);
        $Template->setEntries($entries[$templateId] ?? []);

        return $Template;
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
    public function missingProducts(array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if (count($productIds) === 0) {
            return [];
        }

        $found = $this->Connection->createQueryBuilder()
            ->select('id')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('id IN (:ids)')
            ->setParameter('ids', $productIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_diff($productIds, $found));
    }

    /**
     * @throws DBALException
     */
    public function insert(TemplateState $Template): void
    {
        $this->Connection->insert(Tables::name(Tables::TEMPLATES), self::templateToRow($Template));
        $this->insertEntries($Template);
    }

    /**
     * @throws DBALException
     */
    public function update(TemplateState $Template): void
    {
        $this->Connection->update(
            Tables::name(Tables::TEMPLATES),
            self::templateToRow($Template),
            ['id' => $Template->id]
        );

        $this->Connection->delete(Tables::name(Tables::TEMPLATE_ENTRIES), ['template_id' => $Template->id]);
        $this->insertEntries($Template);
    }

    /**
     * Zugängliche Vorlagen (eigene persönliche und die des Haushalts) ohne
     * gelöschte, nach Name, mit Rolle, Anzahl der Produkte und den ersten
     * Produkten (Vorschau in der Übersicht).
     *
     * @return list<array{template: TemplateState, role: ListRole, count: int, preview: list<string>}>
     * @throws DBALException
     */
    public function templatesForUser(string $userId): array
    {
        $householdId = $this->householdIdOf($userId);

        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::TEMPLATES))
            ->where('deleted_at IS NULL')
            ->orderBy('name')
            ->addOrderBy('id')
            ->setParameter('user', $userId);

        if ($householdId === null) {
            $Query->andWhere('owner_user = :user');
        } else {
            $Query->andWhere('(owner_user = :user OR household_id = :household)')
                ->setParameter('household', $householdId);
        }

        $templates = [];

        foreach ($Query->executeQuery()->fetchAllAssociative() as $row) {
            $Template = self::templateFromRow($row);
            $templates[$Template->id] = $Template;
        }

        $entries = $this->entriesOf(array_keys($templates));
        $result = [];

        foreach ($templates as $templateId => $Template) {
            $Template->setEntries($entries[$templateId] ?? []);
            $preview = [];

            foreach (array_slice($Template->entries(), 0, self::PREVIEW_SIZE) as $Entry) {
                $preview[] = $Entry->productId;
            }

            $result[] = [
                'template' => $Template,
                'role' => TemplateService::roleOf($Template, $userId, $householdId) ?? ListRole::Owner,
                'count' => count($Template->entries()),
                'preview' => $preview
            ];
        }

        return $result;
    }

    /**
     * @throws DBALException
     */
    private function insertEntries(TemplateState $Template): void
    {
        foreach ($Template->entries() as $Entry) {
            $this->Connection->insert(Tables::name(Tables::TEMPLATE_ENTRIES), [
                'id' => $Entry->id,
                'template_id' => $Template->id,
                'product_id' => $Entry->productId,
                'amount' => $Entry->quantity->toDatabase(),
                'unit' => $Entry->quantity->unit(),
                'note' => $Entry->note,
                'sort_key' => $Entry->sortKey
            ]);
        }
    }

    /**
     * Einträge je Vorlage (unsortiert; `TemplateState::setEntries` sortiert).
     *
     * @param list<string> $templateIds
     * @return array<string, list<TemplateEntry>>
     * @throws DBALException
     */
    private function entriesOf(array $templateIds): array
    {
        if (count($templateIds) === 0) {
            return [];
        }

        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::TEMPLATE_ENTRIES))
            ->where('template_id IN (:ids)')
            ->setParameter('ids', $templateIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            $entries[(string)$row['template_id']][] = new TemplateEntry(
                (string)$row['id'],
                (string)$row['product_id'],
                Quantity::fromDatabase($row['amount'], $row['unit'] ?? null),
                (string)$row['note'],
                (string)$row['sort_key']
            );
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function templateFromRow(array $row): TemplateState
    {
        return new TemplateState(
            (string)$row['id'],
            (string)$row['name'],
            self::nullableString($row['owner_user'] ?? null),
            self::nullableString($row['household_id'] ?? null),
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
    private static function templateToRow(TemplateState $Template): array
    {
        return [
            'id' => $Template->id,
            'name' => $Template->name,
            'owner_user' => $Template->ownerUser,
            'household_id' => $Template->householdId,
            'revision' => $Template->revision,
            'created_by' => $Template->createdBy,
            'created_at' => $Template->createdAt->format(Tables::DATETIME_FORMAT),
            'updated_by' => $Template->updatedBy,
            'updated_at' => $Template->updatedAt->format(Tables::DATETIME_FORMAT),
            'deleted_at' => $Template->deletedAt?->format(Tables::DATETIME_FORMAT),
            'deleted_by' => $Template->deletedBy
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
}
