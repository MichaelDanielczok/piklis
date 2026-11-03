<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use JsonException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function addcslashes;
use function array_map;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

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
            self::date($row['updated_at']) ?? new DateTimeImmutable(),
            self::nullableString($row['brand'] ?? null),
            self::foodFacts($row),
            self::nullableString($row['image_source'] ?? null)
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
