<?php

namespace MichaelDanielczok\Piklis\Products;

/**
 * Speicher des Katalogs. In der App `ProductRepository` (Datenbank), in Tests
 * ein Speicher im Arbeitsspeicher.
 */
interface ProductStore
{
    public function find(string $id): ?Product;

    /**
     * Alle Produkte, auch ausgeblendete.
     *
     * @return list<Product>
     */
    public function all(): array;

    /**
     * Produkte, deren Suchname alle Suchwörter enthält (Vorauswahl für
     * `ProductSearch`, die genaue Reihenfolge bestimmt diese).
     *
     * @param list<string> $tokens normalisierte Suchwörter
     * @return list<Product>
     */
    public function findContainingAll(array $tokens, bool $includeHidden): array;

    public function insert(Product $Product): void;

    public function update(Product $Product): void;
}
