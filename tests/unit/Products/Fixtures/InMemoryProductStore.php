<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\ListItemUsage;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductStore;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function str_contains;

/**
 * Katalog im Arbeitsspeicher für Tests ohne Datenbank. Zählt Schreibzugriffe,
 * damit Tests sehen, ob wirklich gespeichert wurde.
 */
final class InMemoryProductStore implements ProductStore
{
    /** @var array<string, Product> */
    public array $products = [];

    public int $writes = 0;

    /** @var array<string, list<string>> Listenartikel => Benutzer mit Zugriff über Liste oder Vorlage */
    public array $listItemUsers = [];

    /** @var array<string, array{active?: int, completed?: int, lastCompletedAt?: DateTimeImmutable, inTemplate?: bool}> */
    public array $usages = [];

    public function find(string $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function all(): array
    {
        return array_values(array_filter($this->products, static fn(Product $Product): bool => !$Product->listItem));
    }

    public function findContainingAll(array $tokens, bool $includeHidden): array
    {
        $found = [];

        foreach ($this->products as $Product) {
            if ($Product->listItem || ($Product->hidden && !$includeHidden)) {
                continue;
            }

            foreach ($tokens as $token) {
                if (!str_contains($Product->searchName(), $token)) {
                    continue 2;
                }
            }

            $found[] = $Product;
        }

        return $found;
    }

    public function insert(Product $Product): void
    {
        $this->products[$Product->id] = $Product;
        $this->writes++;
    }

    public function update(Product $Product): void
    {
        $this->products[$Product->id] = $Product;
        $this->writes++;
    }

    public function delete(string $id): void
    {
        unset($this->products[$id]);
        $this->writes++;
    }

    public function listItems(array $productIds): array
    {
        return array_values(array_filter(
            $this->products,
            static fn(Product $Product): bool => $Product->listItem && in_array($Product->id, $productIds, true)
        ));
    }

    public function canAccessListItem(Product $Product, string $userId): bool
    {
        $users = $this->listItemUsers[$Product->id] ?? [];

        return in_array($userId, $users, true) || ($users === [] && $Product->createdBy === $userId);
    }

    public function listItemUsages(): array
    {
        $items = array_filter($this->products, static fn(Product $Product): bool => $Product->listItem);

        return array_values(array_map(function (Product $Product): ListItemUsage {
            $usage = $this->usages[$Product->id] ?? [];

            return new ListItemUsage(
                $Product,
                $usage['active'] ?? 0,
                $usage['completed'] ?? 0,
                $usage['lastCompletedAt'] ?? null,
                $usage['inTemplate'] ?? false
            );
        }, $items));
    }
}
