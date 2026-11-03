<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use DateTimeImmutable;

/**
 * Gespeicherte Antwort von Open Food Facts zu einem Barcode: das
 * verkleinerte Produkt (`OpenFoodFactsClient::compact()`) oder null für
 * „nicht gefunden“, mit Abrufzeit.
 */
final class CacheEntry
{
    /**
     * @param array<string, mixed>|null $product
     */
    public function __construct(
        public readonly string $barcode,
        public readonly ?array $product,
        public readonly DateTimeImmutable $fetchedAt
    ) {
    }

    public function found(): bool
    {
        return $this->product !== null;
    }
}
