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
     * Der ganze Katalog, auch ausgeblendete Produkte, ohne Listenartikel.
     *
     * @return list<Product>
     */
    public function all(): array;

    /**
     * Katalogprodukte, deren Suchname alle Suchwörter enthält (Vorauswahl
     * für `ProductSearch`, die genaue Reihenfolge bestimmt diese).
     *
     * @param list<string> $tokens normalisierte Suchwörter
     * @return list<Product>
     */
    public function findContainingAll(array $tokens, bool $includeHidden): array;

    public function insert(Product $Product): void;

    public function update(Product $Product): void;

    /**
     * Zeile löschen (nur Listenartikel, siehe `ListItemCleanup`).
     */
    public function delete(string $id): void;

    /**
     * Die Listenartikel unter diesen Produkt-IDs; Katalogprodukte fallen weg.
     *
     * @param list<string> $productIds
     * @return list<Product>
     */
    public function listItems(array $productIds): array;

    /**
     * Darf der Benutzer diesen Listenartikel sehen? Ja, wenn er auf einer für
     * ihn zugänglichen Liste sichtbar ist oder in einer für ihn zugänglichen
     * Vorlage steht. Der Ersteller auch, solange ihn noch nichts nutzt: Das
     * Produkt ist da, der Eintrag kommt gleich danach.
     */
    public function canAccessListItem(Product $Product, string $userId): bool;

    /**
     * Alle Listenartikel mit ihrer Nutzung, für das Aufräumen.
     *
     * @return list<ListItemUsage>
     */
    public function listItemUsages(): array;
}
