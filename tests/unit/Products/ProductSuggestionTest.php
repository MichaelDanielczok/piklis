<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use MichaelDanielczok\Piklis\Products\Categories;
use MichaelDanielczok\Piklis\Products\ProductSuggestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function count;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Vorschlag von Kategorie und Ersatzicon aus dem Namen mit den Fällen aus
 * tests/fixtures/product-suggestions.json (dieselben prüft der JS-Test).
 */
class ProductSuggestionTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/../../..';

    /**
     * @return array<string, array{string, array{icon: string, category: string}|null}>
     */
    public static function fixtureCases(): array
    {
        /** @var array{cases: list<array{name: string, expected: array{icon: string, category: string}|null}>} $data */
        $data = self::json('/tests/fixtures/product-suggestions.json');
        $cases = [];

        foreach ($data['cases'] as $case) {
            $cases['„' . $case['name'] . '“'] = [$case['name'], $case['expected']];
        }

        return $cases;
    }

    /**
     * @param array{icon: string, category: string}|null $expected
     */
    #[DataProvider('fixtureCases')]
    public function testSuggestionMatchesTheSharedFixture(string $name, ?array $expected): void
    {
        $suggestion = ProductSuggestion::default()->suggest($name);

        if ($expected === null) {
            $this->assertNull($suggestion);
            return;
        }

        $this->assertSame(['category' => $expected['category'], 'icon' => $expected['icon']], $suggestion);
    }

    public function testTheFixtureHasAtLeastHundredNames(): void
    {
        $this->assertGreaterThanOrEqual(100, count(self::fixtureCases()));
    }

    public function testRequiredExamplesGetAMatchingIcon(): void
    {
        $Suggestion = ProductSuggestion::default();

        $this->assertSame(['category' => 'fruit', 'icon' => 'banane'], $Suggestion->suggest('Bananen'));
        $this->assertSame(
            ['category' => 'household', 'icon' => 'spuelmaschinentabs'],
            $Suggestion->suggest('Spülmaschinentabs')
        );
        $this->assertSame(['category' => 'meat', 'icon' => 'fleisch'], $Suggestion->suggest('Hackfleisch'));
        $this->assertSame(['category' => 'bread', 'icon' => 'brot'], $Suggestion->suggest('Vollkornbrot'));
    }

    public function testKeywordsUseKnownIconsAndCategories(): void
    {
        /** @var array{icons: list<array{key: string}>} $icons */
        $icons = self::json('/data/icons.json');
        $iconKeys = array_column($icons['icons'], 'key');

        /** @var array{keywords: list<array{icon: string, category: string}>} $keywords */
        $keywords = self::json('/data/keywords.json');

        foreach ($keywords['keywords'] as $entry) {
            $this->assertContains($entry['icon'], $iconKeys, 'Icon ' . $entry['icon']);
            $this->assertTrue(Categories::isValid($entry['category']), 'Kategorie ' . $entry['category']);
        }
    }

    public function testExactKeywordsMatchOnlyWholeWords(): void
    {
        $Suggestion = new ProductSuggestion([
            ['word' => 'ei', 'exact' => true, 'icon' => 'eier', 'category' => 'dairy']
        ]);

        $this->assertSame(['category' => 'dairy', 'icon' => 'eier'], $Suggestion->suggest('Eier'));
        $this->assertNull($Suggestion->suggest('Brei'));
        $this->assertNull($Suggestion->suggest('Eimer'));
    }

    public function testTheFirstWordWithAMatchWins(): void
    {
        $Suggestion = new ProductSuggestion([
            ['word' => 'joghurt', 'exact' => false, 'icon' => 'becher', 'category' => 'dairy'],
            ['word' => 'erdbeere', 'exact' => false, 'icon' => 'erdbeere', 'category' => 'fruit']
        ]);

        $this->assertSame(['category' => 'dairy', 'icon' => 'becher'], $Suggestion->suggest('Joghurt Erdbeere'));
        $this->assertSame(['category' => 'fruit', 'icon' => 'erdbeere'], $Suggestion->suggest('Erdbeeren, Bio'));
    }

    public function testWordStartsCountOnlyWithoutAnyWordEnd(): void
    {
        $Suggestion = new ProductSuggestion([
            ['word' => 'lachs', 'exact' => false, 'icon' => 'fisch', 'category' => 'fish'],
            ['word' => 'tee', 'exact' => false, 'icon' => 'tee', 'category' => 'coffee_tea']
        ]);

        $this->assertSame(['category' => 'fish', 'icon' => 'fisch'], $Suggestion->suggest('Lachsfilet'));
        // „tee“ ist zu kurz für einen Wortanfang
        $this->assertNull($Suggestion->suggest('Teekanne'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $path): array
    {
        $data = json_decode((string)file_get_contents(self::PACKAGE . $path), true, 512, JSON_THROW_ON_ERROR);

        return (array)$data;
    }
}
