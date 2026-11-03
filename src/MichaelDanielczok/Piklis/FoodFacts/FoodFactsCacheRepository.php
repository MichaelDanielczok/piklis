<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use JsonException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Abrufe bei Open Food Facts in `pk_off_cache`: ein Datensatz je Barcode,
 * ein neuer Abruf ersetzt ihn.
 */
class FoodFactsCacheRepository implements FoodFactsCache
{
    private Connection $Connection;

    public function __construct(?Connection $Connection = null)
    {
        $this->Connection = $Connection ?? QUI::getDataBaseConnection();
    }

    /**
     * @throws DBALException
     */
    public function find(string $barcode): ?CacheEntry
    {
        $row = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::OFF_CACHE))
            ->where('barcode = :barcode')
            ->setParameter('barcode', $barcode)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $product = null;

        if ((bool)$row['found'] && is_string($row['product'] ?? null)) {
            try {
                $decoded = json_decode($row['product'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // kaputt gespeichert: wie nicht vorhanden behandeln, neu abrufen
                return null;
            }

            if (!is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            $product = $decoded;
        }

        return new CacheEntry(
            (string)$row['barcode'],
            $product,
            new DateTimeImmutable((string)$row['fetched_at'])
        );
    }

    /**
     * @throws DBALException
     * @throws JsonException
     */
    public function save(CacheEntry $Entry): void
    {
        $data = [
            'found' => $Entry->found() ? 1 : 0,
            'product' => $Entry->product === null
                ? null
                : json_encode($Entry->product, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'fetched_at' => $Entry->fetchedAt->format(Tables::DATETIME_FORMAT)
        ];

        $table = Tables::name(Tables::OFF_CACHE);
        $updated = $this->Connection->update($table, $data, ['barcode' => $Entry->barcode]);

        if ($updated === 0 && $this->find($Entry->barcode) === null) {
            $this->Connection->insert($table, ['barcode' => $Entry->barcode] + $data);
        }
    }

    /**
     * @throws DBALException
     */
    public function countFetchedSince(DateTimeImmutable $since): int
    {
        $count = $this->Connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::name(Tables::OFF_CACHE))
            ->where('fetched_at >= :since')
            ->setParameter('since', $since->format(Tables::DATETIME_FORMAT))
            ->executeQuery()
            ->fetchOne();

        return (int)$count;
    }
}
