<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductInput;
use MichaelDanielczok\Piklis\Products\ProductSearch;
use MichaelDanielczok\Piklis\Products\SearchText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Suchtext und Trefferreihenfolge mit den Fällen aus
 * tests/fixtures/product-search.json (dieselben prüft der JS-Test).
 */
class SearchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = (string)file_get_contents(__DIR__ . '/../../fixtures/product-search.json');

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizeCases(): iterable
    {
        /** @var list<array{input: string, expected: string}> $cases */
        $cases = self::fixture()['normalize'];

        foreach ($cases as $case) {
            yield '"' . $case['input'] . '"' => [$case['input'], $case['expected']];
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function searchCases(): iterable
    {
        /** @var list<array<string, mixed>> $cases */
        $cases = self::fixture()['search'];

        foreach ($cases as $case) {
            yield (string)$case['name'] => [$case];
        }
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, SearchText::normalize($input));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('searchCases')]
    public function testSearch(array $case): void
    {
        $products = [];

        /** @var list<string|array{name: string, hidden?: bool}> $items */
        $items = $case['products'];

        foreach ($items as $index => $item) {
            $name = is_array($item) ? $item['name'] : $item;
            $Product = Product::create(
                sprintf('00000000-0000-4000-8000-%012d', $index),
                ProductInput::forCreate(['name' => $name]),
                'user',
                new DateTimeImmutable()
            );
            $Product->hidden = is_array($item) && ($item['hidden'] ?? false);
            $products[] = $Product;
        }

        $found = ProductSearch::search(
            (string)$case['query'],
            $products,
            (int)($case['limit'] ?? ProductSearch::DEFAULT_LIMIT),
            (bool)($case['includeHidden'] ?? false)
        );

        $this->assertSame($case['expected'], array_map(static fn(Product $Product): string => $Product->name, $found));
    }

    public function testTokensIgnoreSpacesAndPunctuation(): void
    {
        $this->assertSame(['gouda', 'jung'], SearchText::tokens('  Gouda, jung! '));
        $this->assertSame([], SearchText::tokens(' - '));
    }

    public function testRanks(): void
    {
        $this->assertSame(ProductSearch::RANK_EXACT, ProductSearch::rank('milch', ['milch']));
        $this->assertSame(ProductSearch::RANK_PREFIX, ProductSearch::rank('milchreis', ['milch']));
        $this->assertSame(ProductSearch::RANK_WORD_PREFIX, ProductSearch::rank('frische milch', ['milch']));
        $this->assertSame(ProductSearch::RANK_CONTAINS, ProductSearch::rank('hafermilch', ['milch']));
        $this->assertNull(ProductSearch::rank('butter', ['milch']));
    }

    public function testLimitFromQuery(): void
    {
        $this->assertSame(ProductSearch::DEFAULT_LIMIT, ProductSearch::limit(null));
        $this->assertSame(ProductSearch::DEFAULT_LIMIT, ProductSearch::limit('abc'));
        $this->assertSame(5, ProductSearch::limit('5'));
        $this->assertSame(1, ProductSearch::limit('0'));
        $this->assertSame(ProductSearch::MAX_LIMIT, ProductSearch::limit('5000'));
    }
}
