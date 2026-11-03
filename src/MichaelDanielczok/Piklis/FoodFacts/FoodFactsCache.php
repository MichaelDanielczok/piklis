<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use DateTimeImmutable;

/**
 * Speicher der Abrufe bei Open Food Facts. In der App
 * `FoodFactsCacheRepository` (Tabelle `pk_off_cache`), in Tests im
 * Arbeitsspeicher.
 */
interface FoodFactsCache
{
    public function find(string $barcode): ?CacheEntry;

    public function save(CacheEntry $Entry): void;

    /**
     * Anzahl der Abrufe seit dem Zeitpunkt (für die Bremse vor dem
     * Abruflimit).
     */
    public function countFetchedSince(DateTimeImmutable $since): int;
}
