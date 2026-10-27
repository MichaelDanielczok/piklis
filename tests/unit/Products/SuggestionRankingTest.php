<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\SuggestionRanking;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Gewichtung der Vorschläge: Häufigkeit in 90 Tagen, kürzlich etwas höher.
 */
class SuggestionRankingTest extends TestCase
{
    private const NOW = '2026-10-27 12:00:00';

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /**
     * @return array{product_id: string, list_id: string, used_at: DateTimeImmutable}
     */
    private static function usage(string $productId, string $listId, int $daysAgo): array
    {
        return [
            'product_id' => $productId,
            'list_id' => $listId,
            'used_at' => self::now()->modify('-' . $daysAgo . ' days')
        ];
    }

    /**
     * @param list<array{product_id: string, list_id: string, used_at: DateTimeImmutable}> $usages
     * @return list<string>
     */
    private static function order(array $usages): array
    {
        return array_column(SuggestionRanking::rank($usages, self::now()), 'productId');
    }

    public function testWeightFallsFromOneAndAHalfToOneOverNinetyDays(): void
    {
        $now = self::now();

        $this->assertEqualsWithDelta(1.5, SuggestionRanking::weight($now, $now), 0.0001);
        $this->assertEqualsWithDelta(1.25, SuggestionRanking::weight($now->modify('-45 days'), $now), 0.0001);
        $this->assertEqualsWithDelta(1.0, SuggestionRanking::weight($now->modify('-90 days'), $now), 0.0001);
    }

    public function testFutureTimesCountAsToday(): void
    {
        $now = self::now();

        $this->assertEqualsWithDelta(1.5, SuggestionRanking::weight($now->modify('+2 days'), $now), 0.0001);
    }

    public function testFrequencyBeatsRecency(): void
    {
        // dreimal vor rund zwei Monaten (≈ 3,3) vor zweimal gestern (≈ 3,0)
        $usages = [
            self::usage('milk', 'l1', 60),
            self::usage('milk', 'l2', 61),
            self::usage('milk', 'l3', 62),
            self::usage('bananas', 'l4', 1),
            self::usage('bananas', 'l5', 1)
        ];

        $this->assertSame(['milk', 'bananas'], self::order($usages));
    }

    public function testRecencyDecidesAtEqualFrequency(): void
    {
        $usages = [
            self::usage('old', 'l1', 80),
            self::usage('old', 'l2', 70),
            self::usage('new', 'l3', 3),
            self::usage('new', 'l4', 2)
        ];

        $this->assertSame(['new', 'old'], self::order($usages));
    }

    public function testCountsAProductOncePerList(): void
    {
        // „noch mal Milch“ auf derselben Liste zählt nicht doppelt
        $usages = [
            self::usage('milk', 'lidl', 5),
            self::usage('milk', 'lidl', 4),
            self::usage('milk', 'lidl', 3),
            self::usage('bread', 'lidl', 10),
            self::usage('bread', 'rewe', 10)
        ];

        $ranked = SuggestionRanking::rank($usages, self::now());

        $this->assertSame(['bread', 'milk'], array_column($ranked, 'productId'));
        $this->assertSame(1, $ranked[1]['uses']);
        $this->assertEquals(self::now()->modify('-3 days'), $ranked[1]['lastUsedAt']);
    }

    public function testIgnoresUsagesOlderThanNinetyDays(): void
    {
        $usages = [
            self::usage('fresh', 'l1', 89),
            self::usage('stale', 'l2', 91),
            self::usage('stale', 'l3', 120)
        ];

        $this->assertSame(['fresh'], self::order($usages));
    }

    public function testEqualScoresFollowLastUseThenProductId(): void
    {
        $now = self::now();
        $usages = [
            ['product_id' => 'b', 'list_id' => 'l1', 'used_at' => $now],
            ['product_id' => 'a', 'list_id' => 'l2', 'used_at' => $now],
            ['product_id' => 'c', 'list_id' => 'l3', 'used_at' => $now->modify('+1 day')]
        ];

        // c zählt wie heute (gleiches Gewicht), ist aber zuletzt benutzt
        $this->assertSame(['c', 'a', 'b'], self::order($usages));
    }

    public function testScoreAndUsesInResult(): void
    {
        $ranked = SuggestionRanking::rank(
            [
                self::usage('milk', 'l1', 0),
                self::usage('milk', 'l2', 45)
            ],
            self::now()
        );

        $this->assertCount(1, $ranked);
        $this->assertSame(2, $ranked[0]['uses']);
        $this->assertEqualsWithDelta(2.75, $ranked[0]['score'], 0.0001);
    }

    public function testEmptyHistoryGivesNoSuggestions(): void
    {
        $this->assertSame([], SuggestionRanking::rank([], self::now()));
    }
}
