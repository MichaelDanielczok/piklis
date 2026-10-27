<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use InvalidArgumentException;
use MichaelDanielczok\Piklis\Lists\SortKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function array_unique;
use function count;
use function file_get_contents;
use function json_decode;
use function mt_rand;
use function mt_srand;
use function strcmp;
use function strlen;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Sortierschlüssel; dieselben Fälle prüft tests/js/sortKey.test.js.
 */
class SortKeyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = (string)file_get_contents(__DIR__ . '/../../fixtures/sort-keys.json');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function betweenCases(): iterable
    {
        foreach (self::fixture()['between'] as $case) {
            $name = ($case['before'] ?? 'null') . ' | ' . ($case['after'] ?? 'null');

            yield $name => [$case['before'], $case['after'], $case['key']];
        }
    }

    #[DataProvider('betweenCases')]
    public function testBetweenMatchesFixture(?string $before, ?string $after, string $expected): void
    {
        $key = SortKey::between($before, $after);

        $this->assertSame($expected, $key);
        $this->assertTrue(SortKey::isValid($key));

        if ($before !== null) {
            $this->assertLessThan(0, strcmp($before, $key));
        }

        if ($after !== null) {
            $this->assertLessThan(0, strcmp($key, $after));
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, int, list<string>}>
     */
    public static function manyBetweenCases(): iterable
    {
        foreach (self::fixture()['manyBetween'] as $case) {
            $name = ($case['before'] ?? 'null') . ' | ' . ($case['after'] ?? 'null') . ' × ' . $case['count'];

            yield $name => [$case['before'], $case['after'], $case['count'], $case['keys']];
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('manyBetweenCases')]
    public function testManyBetweenMatchesFixture(?string $before, ?string $after, int $count, array $expected): void
    {
        $this->assertSame($expected, SortKey::manyBetween($before, $after, $count));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function invalidBetweenCases(): iterable
    {
        foreach (self::fixture()['invalidBetween'] as $case) {
            yield $case['reason'] => [$case['before'], $case['after']];
        }
    }

    #[DataProvider('invalidBetweenCases')]
    public function testBetweenRejectsInvalidNeighbours(?string $before, ?string $after): void
    {
        $this->expectException(InvalidArgumentException::class);

        SortKey::between($before, $after);
    }

    public function testValidation(): void
    {
        $fixture = self::fixture();

        foreach ($fixture['valid'] as $key) {
            $this->assertTrue(SortKey::isValid($key), $key);
        }

        foreach ($fixture['invalid'] as $key) {
            $this->assertFalse(SortKey::isValid($key), $key);
        }
    }

    public function testAppendingGrowsSlowly(): void
    {
        $key = null;

        for ($i = 0; $i < 500; $i++) {
            $next = SortKey::between($key, null);

            if ($key !== null) {
                $this->assertLessThan(0, strcmp($key, $next));
            }

            $key = $next;
        }

        $this->assertLessThanOrEqual(30, strlen((string)$key));
    }

    /**
     * Zufällige Einfügungen wie beim Sortieren per Finger: Reihenfolge bleibt
     * erhalten, alle Schlüssel gültig und verschieden.
     */
    public function testRandomInsertsKeepOrder(): void
    {
        mt_srand(42);
        $keys = [SortKey::between(null, null)];

        for ($i = 0; $i < 400; $i++) {
            $index = mt_rand(0, count($keys));
            $before = $index > 0 ? $keys[$index - 1] : null;
            $after = $index < count($keys) ? $keys[$index] : null;
            $key = SortKey::between($before, $after);

            $this->assertTrue(SortKey::isValid($key));

            $keys = [...array_slice($keys, 0, $index), $key, ...array_slice($keys, $index)];
        }

        $sorted = $keys;
        usort($sorted, 'strcmp');

        $this->assertSame($keys, $sorted);
        $this->assertCount(401, array_unique($keys));
    }

    public function testCompareUsesIdOnEqualKeys(): void
    {
        $this->assertSame(-1, SortKey::compare('i', 'b', 'j', 'a'));
        $this->assertSame(-1, SortKey::compare('i', 'a', 'i', 'b'));
        $this->assertSame(1, SortKey::compare('i', 'b', 'i', 'a'));
        $this->assertSame(0, SortKey::compare('i', 'a', 'i', 'a'));
    }
}
