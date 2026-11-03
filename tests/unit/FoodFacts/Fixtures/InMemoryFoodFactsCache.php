<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\CacheEntry;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsCache;

/**
 * Cache der Abrufe im Arbeitsspeicher. Jeder gespeicherte Abruf zählt für
 * die Bremse wie in der Datenbank (ein Datensatz je Barcode).
 */
final class InMemoryFoodFactsCache implements FoodFactsCache
{
    /** @var array<string, CacheEntry> */
    public array $entries = [];

    public function find(string $barcode): ?CacheEntry
    {
        return $this->entries[$barcode] ?? null;
    }

    public function save(CacheEntry $Entry): void
    {
        $this->entries[$Entry->barcode] = $Entry;
    }

    public function countFetchedSince(DateTimeImmutable $since): int
    {
        $count = 0;

        foreach ($this->entries as $Entry) {
            if ($Entry->fetchedAt >= $since) {
                $count++;
            }
        }

        return $count;
    }
}
