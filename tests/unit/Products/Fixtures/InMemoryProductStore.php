<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures;

use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductStore;

use function array_values;
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

    public function find(string $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->products);
    }

    public function findContainingAll(array $tokens, bool $includeHidden): array
    {
        $found = [];

        foreach ($this->products as $Product) {
            if ($Product->hidden && !$includeHidden) {
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
}
