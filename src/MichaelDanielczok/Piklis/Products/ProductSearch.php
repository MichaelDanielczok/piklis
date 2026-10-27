<?php

namespace MichaelDanielczok\Piklis\Products;

use function array_map;
use function array_slice;
use function explode;
use function implode;
use function is_numeric;
use function max;
use function min;
use function str_contains;
use function str_starts_with;
use function strcmp;
use function usort;

/**
 * Suche im Katalog nach dem Namen, für Vorschläge beim Tippen.
 *
 * - Jedes Suchwort muss im Namen vorkommen (normalisiert, siehe
 *   `SearchText`), auch mitten im Wort: „brot“ findet „Vollkornbrot“.
 * - Reihenfolge: gleicher Name · Name beginnt mit der Eingabe · jedes
 *   Suchwort beginnt ein Wort · übrige Treffer; innerhalb gleich guter
 *   Treffer alphabetisch.
 * - Ausgeblendete Produkte nur auf Wunsch (Produktpfleger).
 * - Treffer sind nur Vorschläge: ähnliche Namen werden nie zusammengeführt.
 *
 * Dieselbe Regel steht in `frontend/utils/productSearch.js`; beide prüfen die
 * Fälle aus `tests/fixtures/product-search.json`.
 */
final class ProductSearch
{
    public const RANK_EXACT = 0;
    public const RANK_PREFIX = 1;
    public const RANK_WORD_PREFIX = 2;
    public const RANK_CONTAINS = 3;

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /**
     * @param iterable<Product> $products
     * @return list<Product>
     */
    public static function search(
        string $query,
        iterable $products,
        int $limit = self::DEFAULT_LIMIT,
        bool $includeHidden = false
    ): array {
        $tokens = SearchText::tokens($query);

        if ($tokens === [] || $limit < 1) {
            return [];
        }

        $hits = [];

        foreach ($products as $Product) {
            if ($Product->hidden && !$includeHidden) {
                continue;
            }

            $name = $Product->searchName();
            $rank = self::rank($name, $tokens);

            if ($rank === null) {
                continue;
            }

            $hits[] = [
                'rank' => $rank,
                'name' => $name,
                'product' => $Product
            ];
        }

        usort(
            $hits,
            static function (array $a, array $b): int {
                if ($a['rank'] !== $b['rank']) {
                    return $a['rank'] <=> $b['rank'];
                }

                $byName = strcmp($a['name'], $b['name']);

                if ($byName !== 0) {
                    return $byName;
                }

                return strcmp($a['product']->id, $b['product']->id);
            }
        );

        $hits = array_slice($hits, 0, $limit);

        return array_map(static fn(array $hit): Product => $hit['product'], $hits);
    }

    /**
     * Rang eines normalisierten Namens, null ohne Treffer.
     *
     * @param list<string> $tokens normalisierte Suchwörter
     */
    public static function rank(string $name, array $tokens): ?int
    {
        foreach ($tokens as $token) {
            if (!str_contains($name, $token)) {
                return null;
            }
        }

        $query = implode(' ', $tokens);

        if ($name === $query) {
            return self::RANK_EXACT;
        }

        if (str_starts_with($name, $query)) {
            return self::RANK_PREFIX;
        }

        $words = explode(' ', $name);

        foreach ($tokens as $token) {
            if (!self::startsAnyWord($words, $token)) {
                return self::RANK_CONTAINS;
            }
        }

        return self::RANK_WORD_PREFIX;
    }

    /**
     * Begrenzung aus `?limit=`: Standard 20, höchstens 100.
     */
    public static function limit(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int)$value));
    }

    /**
     * @param list<string> $words
     */
    private static function startsAnyWord(array $words, string $token): bool
    {
        foreach ($words as $word) {
            if (str_starts_with($word, $token)) {
                return true;
            }
        }

        return false;
    }
}
