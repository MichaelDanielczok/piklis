<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use JsonException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function addcslashes;
use function array_map;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Katalog und Listenartikel in der Datenbank (`pk_products`). `search_name`
 * wird bei jedem Schreiben aus dem Namen neu gebildet.
 */
class ProductRepository implements ProductStore
{
    private Connection $Connection;

    public function __construct(?Connection $Connection = null)
    {
        $this->Connection = $Connection ?? QUI::getDataBaseConnection();
    }

    /**
     * @throws DBALException
     */
    public function find(string $id): ?Product
    {
        $row = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::fromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function all(): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('list_item = 0')
            ->orderBy('search_name')
            ->addOrderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::fromRow(...), $rows);
    }

    /**
     * @throws DBALException
     */
    public function findContainingAll(array $tokens, bool $includeHidden): array
    {
        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('list_item = 0');

        foreach ($tokens as $index => $token) {
            $parameter = 'token' . $index;

            $Query
                ->andWhere('search_name LIKE :' . $parameter)
                ->setParameter($parameter, '%' . addcslashes($token, '%_\\') . '%');
        }

        if (!$includeHidden) {
            $Query->andWhere('hidden = 0');
        }

        $rows = $Query->executeQuery()->fetchAllAssociative();

        return array_map(self::fromRow(...), $rows);
    }

    /**
     * @throws DBALException
     */
    public function insert(Product $Product): void
    {
        $this->Connection->insert(Tables::name(Tables::PRODUCTS), self::toRow($Product));
    }

    /**
     * @throws DBALException
     */
    public function update(Product $Product): void
    {
        $this->Connection->update(
            Tables::name(Tables::PRODUCTS),
            self::toRow($Product),
            ['id' => $Product->id]
        );
    }

    /**
     * @throws DBALException
     */
    public function delete(string $id): void
    {
        $this->Connection->delete(Tables::name(Tables::PRODUCTS), ['id' => $id]);
    }

    /**
     * @throws DBALException
     */
    public function listItems(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('list_item = 1')
            ->andWhere('id IN (:ids)')
            ->setParameter('ids', $productIds, ArrayParameterType::STRING)
            ->orderBy('search_name')
            ->addOrderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::fromRow(...), $rows);
    }

    /**
     * @throws DBALException
     */
    public function canAccessListItem(Product $Product, string $userId): bool
    {
        $householdId = $this->householdIdOf($userId);

        if (
            $this->isOnAccessibleList($Product->id, $userId, $householdId)
            || $this->isInAccessibleTemplate($Product->id, $userId, $householdId)
        ) {
            return true;
        }

        return $Product->createdBy === $userId && !$this->isUsed($Product->id);
    }

    /**
     * Sichtbare Einträge nicht gelöschter Listen und Einträge nicht gelöschter
     * Vorlagen, je Listenartikel zusammengezählt.
     *
     * @throws DBALException
     */
    public function listItemUsages(): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::PRODUCTS))
            ->where('list_item = 1')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        $lists = $this->visibleEntries()
            ->select(
                'e.product_id',
                "SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_lists",
                "SUM(CASE WHEN l.status = 'active' THEN 0 ELSE 1 END) AS completed_lists",
                'MAX(l.completed_at) AS last_completed_at'
            )
            ->innerJoin('e', Tables::name(Tables::PRODUCTS), 'p', 'p.id = e.product_id')
            ->andWhere('p.list_item = 1')
            ->groupBy('e.product_id')
            ->executeQuery()
            ->fetchAllAssociativeIndexed();

        $templates = $this->liveTemplateEntries()
            ->select('DISTINCT te.product_id')
            ->innerJoin('te', Tables::name(Tables::PRODUCTS), 'p', 'p.id = te.product_id')
            ->andWhere('p.list_item = 1')
            ->executeQuery()
            ->fetchFirstColumn();

        $inTemplate = [];

        foreach ($templates as $productId) {
            $inTemplate[(string)$productId] = true;
        }

        return array_map(
            static function (array $row) use ($lists, $inTemplate): ListItemUsage {
                $id = (string)$row['id'];
                $usage = $lists[$id] ?? [];

                return new ListItemUsage(
                    self::fromRow($row),
                    (int)($usage['active_lists'] ?? 0),
                    (int)($usage['completed_lists'] ?? 0),
                    self::date($usage['last_completed_at'] ?? null),
                    isset($inTemplate[$id])
                );
            },
            $rows
        );
    }

    /**
     * Noch ohne Spalten: sichtbare Einträge (`e`) nicht gelöschter Listen (`l`).
     */
    private function visibleEntries(): QueryBuilder
    {
        return $this->Connection->createQueryBuilder()
            ->from(Tables::name(Tables::LIST_ENTRIES), 'e')
            ->innerJoin('e', Tables::name(Tables::LISTS), 'l', 'l.id = e.list_id')
            ->where('l.deleted_at IS NULL')
            ->andWhere('e.removed_at IS NULL')
            ->andWhere('e.merged_into IS NULL');
    }

    /**
     * Noch ohne Spalten: Einträge (`te`) nicht gelöschter Vorlagen (`t`).
     */
    private function liveTemplateEntries(): QueryBuilder
    {
        return $this->Connection->createQueryBuilder()
            ->from(Tables::name(Tables::TEMPLATE_ENTRIES), 'te')
            ->innerJoin('te', Tables::name(Tables::TEMPLATES), 't', 't.id = te.template_id')
            ->where('t.deleted_at IS NULL');
    }

    /**
     * @throws DBALException
     */
    private function householdIdOf(string $userId): ?string
    {
        $householdId = $this->Connection->createQueryBuilder()
            ->select('household_id')
            ->from(Tables::name(Tables::HOUSEHOLD_MEMBERS))
            ->where('user_id = :user')
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchOne();

        return self::nullableString($householdId);
    }

    /**
     * Besitzer, Haushalt und Gäste wie `Lists\ListAccess`.
     *
     * @throws DBALException
     */
    private function isOnAccessibleList(string $productId, string $userId, ?string $householdId): bool
    {
        $conditions = [
            'l.owner_user = :user',
            'l.id IN (SELECT s.list_id FROM ' . Tables::name(Tables::LIST_SHARES) . ' s WHERE s.user_id = :user)'
        ];

        $Query = $this->visibleEntries()
            ->select('e.id')
            ->andWhere('e.product_id = :product')
            ->setParameter('product', $productId)
            ->setParameter('user', $userId)
            ->setMaxResults(1);

        if ($householdId !== null) {
            $conditions[] = 'l.household_id = :household';
            $Query->setParameter('household', $householdId);
        }

        return $Query->andWhere('(' . implode(' OR ', $conditions) . ')')->executeQuery()->fetchOne() !== false;
    }

    /**
     * Vorlagen kennen nur Besitzer und Haushalt, keine Gäste.
     *
     * @throws DBALException
     */
    private function isInAccessibleTemplate(string $productId, string $userId, ?string $householdId): bool
    {
        $conditions = ['t.owner_user = :user'];

        $Query = $this->liveTemplateEntries()
            ->select('te.id')
            ->andWhere('te.product_id = :product')
            ->setParameter('product', $productId)
            ->setParameter('user', $userId)
            ->setMaxResults(1);

        if ($householdId !== null) {
            $conditions[] = 't.household_id = :household';
            $Query->setParameter('household', $householdId);
        }

        return $Query->andWhere('(' . implode(' OR ', $conditions) . ')')->executeQuery()->fetchOne() !== false;
    }

    /**
     * Nutzt irgendeine Liste oder Vorlage dieses Produkt, egal von wem?
     *
     * @throws DBALException
     */
    private function isUsed(string $productId): bool
    {
        $onList = $this->visibleEntries()
            ->select('e.id')
            ->andWhere('e.product_id = :product')
            ->setParameter('product', $productId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($onList !== false) {
            return true;
        }

        return $this->liveTemplateEntries()
            ->select('te.id')
            ->andWhere('te.product_id = :product')
            ->setParameter('product', $productId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): Product
    {
        return new Product(
            (string)$row['id'],
            (string)$row['name'],
            self::nullableString($row['category'] ?? null),
            self::nullableString($row['icon'] ?? null),
            self::nullableString($row['description'] ?? null),
            self::nullableString($row['package_size'] ?? null),
            self::nullableString($row['barcode'] ?? null),
            self::nullableInt($row['image_id'] ?? null),
            self::nullableInt($row['image_original_id'] ?? null),
            (bool)$row['hidden'],
            self::date($row['hidden_at'] ?? null),
            self::nullableString($row['hidden_by'] ?? null),
            (string)$row['created_by'],
            self::date($row['created_at']) ?? new DateTimeImmutable(),
            (string)$row['updated_by'],
            self::date($row['updated_at']) ?? new DateTimeImmutable(),
            self::nullableString($row['brand'] ?? null),
            self::foodFacts($row),
            self::nullableString($row['image_source'] ?? null),
            (bool)($row['list_item'] ?? false)
        );
    }

    /**
     * Angaben aus Open Food Facts; ohne Abrufdatum keine.
     *
     * @param array<string, mixed> $row
     */
    private static function foodFacts(array $row): ?FoodFactsData
    {
        $fetchedAt = self::date($row['off_fetched_at'] ?? null);

        if ($fetchedAt === null) {
            return null;
        }

        $data = [];

        if (is_string($row['off_data'] ?? null) && $row['off_data'] !== '') {
            try {
                $decoded = json_decode($row['off_data'], true, 64, JSON_THROW_ON_ERROR);
                $data = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $data = [];
            }
        }

        $nutrition = is_array($data['nutrition'] ?? null) ? $data['nutrition'] : null;

        /** @var array<string, mixed>|null $nutrition */
        return new FoodFactsData(
            $fetchedAt,
            self::nullableString($row['off_language'] ?? null),
            self::nullableString($data['ingredients'] ?? null),
            $nutrition
        );
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private static function toRow(Product $Product): array
    {
        return [
            'id' => $Product->id,
            'name' => $Product->name,
            'search_name' => $Product->searchName(),
            'category' => $Product->category,
            'icon' => $Product->icon,
            'description' => $Product->description,
            'package_size' => $Product->packageSize,
            'brand' => $Product->brand,
            'barcode' => $Product->barcode,
            'off_data' => $Product->foodFacts === null
                ? null
                : json_encode($Product->foodFacts->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'off_language' => $Product->foodFacts?->language,
            'off_fetched_at' => $Product->foodFacts?->fetchedAt->format(Tables::DATETIME_FORMAT),
            'image_source' => $Product->imageSource,
            'image_id' => $Product->imageId,
            'image_original_id' => $Product->imageOriginalId,
            'hidden' => $Product->hidden ? 1 : 0,
            'hidden_at' => $Product->hiddenAt?->format(Tables::DATETIME_FORMAT),
            'hidden_by' => $Product->hiddenBy,
            'list_item' => $Product->listItem ? 1 : 0,
            'created_by' => $Product->createdBy,
            'created_at' => $Product->createdAt->format(Tables::DATETIME_FORMAT),
            'updated_by' => $Product->updatedBy,
            'updated_at' => $Product->updatedAt->format(Tables::DATETIME_FORMAT)
        ];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
