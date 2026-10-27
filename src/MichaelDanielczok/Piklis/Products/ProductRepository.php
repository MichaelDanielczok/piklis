<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function addcslashes;
use function array_map;
use function is_numeric;
use function is_string;

/**
 * Katalog in der Datenbank (`pk_products`). `search_name` wird bei jedem
 * Schreiben aus dem Namen neu gebildet.
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
            ->from(Tables::name(Tables::PRODUCTS));

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
            self::date($row['updated_at']) ?? new DateTimeImmutable()
        );
    }

    /**
     * Ohne `barcode`: den setzt Schritt 14.
     *
     * @return array<string, mixed>
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
            'image_id' => $Product->imageId,
            'image_original_id' => $Product->imageOriginalId,
            'hidden' => $Product->hidden ? 1 : 0,
            'hidden_at' => $Product->hiddenAt?->format(Tables::DATETIME_FORMAT),
            'hidden_by' => $Product->hiddenBy,
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
