<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\CacheEntry;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsLookup;
use MichaelDanielczok\Piklis\FoodFacts\HttpResponse;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;
use MichaelDanielczok\Piklis\FoodFacts\OpenFoodFactsClient;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\FixtureHttpClient;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\InMemoryFoodFactsCache;
use PHPUnit\Framework\TestCase;

use function count;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * Cache, „Neu abrufen“ und Bremse vor dem Abruflimit von Open Food Facts.
 */
class FoodFactsLookupTest extends TestCase
{
    private const MILK = '4311501489840';
    private const UNKNOWN = '4099999999019';

    private FixtureHttpClient $Http;

    private InMemoryFoodFactsCache $Cache;

    private FoodFactsLookup $Lookup;

    protected function setUp(): void
    {
        $this->Http = (new FixtureHttpClient())
            ->product(self::MILK, 'complete-4311501489840.json')
            ->product(self::UNKNOWN, 'not-found-4099999999019.json', 404);
        $this->Cache = new InMemoryFoodFactsCache();
        $this->Lookup = new FoodFactsLookup($this->Cache, new OpenFoodFactsClient($this->Http));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03 ' . $time);
    }

    public function testFoundProductComesFromTheCacheForAWeek(): void
    {
        $First = $this->Lookup->lookup(self::MILK, false, self::at('10:00'));
        $Again = $this->Lookup->lookup(self::MILK, false, new DateTimeImmutable('2026-11-09 10:00'));

        $this->assertSame('H-Vollmilch 3,5 % Fett', $Again->name);
        $this->assertEquals($First->fetchedAt, $Again->fetchedAt);
        $this->assertCount(1, $this->Http->requests);

        $this->Lookup->lookup(self::MILK, false, new DateTimeImmutable('2026-11-10 10:00'));
        $this->assertCount(2, $this->Http->requests, 'after 7 days Open Food Facts is asked again');
    }

    public function testNotFoundIsCachedForADay(): void
    {
        foreach (['10:00', '18:00'] as $time) {
            try {
                $this->Lookup->lookup(self::UNKNOWN, false, self::at($time));
                $this->fail('Expected off_not_found');
            } catch (FoodFactsException $Exception) {
                $this->assertSame('off_not_found', $Exception->getErrorCode());
                $this->assertSame(404, $Exception->getHttpStatus());
            }
        }

        $this->assertCount(1, $this->Http->requests);
        $this->assertFalse($this->Cache->entries[self::UNKNOWN]->found());
    }

    public function testRefreshAsksAgainButNotWithinTenMinutes(): void
    {
        $this->Lookup->lookup(self::MILK, false, self::at('10:00'));
        $this->Lookup->lookup(self::MILK, true, self::at('10:05'));
        $this->assertCount(1, $this->Http->requests, 'double tap costs no request');

        $Fresh = $this->Lookup->lookup(self::MILK, true, self::at('10:10'));
        $this->assertCount(2, $this->Http->requests);
        $this->assertEquals(self::at('10:10'), $Fresh->fetchedAt);
    }

    public function testInvalidBarcodeIsNotRequested(): void
    {
        try {
            $this->Lookup->lookup('4311501489841');
            $this->fail('Expected InvalidBarcodeException');
        } catch (InvalidBarcodeException $Exception) {
            $this->assertSame('checksum', $Exception->getReason());
        }

        $this->assertSame([], $this->Http->requests);
    }

    public function testBrakeBeforeTheRateLimit(): void
    {
        $now = self::at('10:00:30');

        for ($i = 0; $i < FoodFactsLookup::MAX_FETCHES_PER_MINUTE; $i++) {
            $barcode = str_pad((string)$i, 13, '0', STR_PAD_LEFT);
            $this->Cache->save(new CacheEntry($barcode, null, self::at('10:00:00')));
        }

        try {
            $this->Lookup->lookup(self::MILK, false, $now);
            $this->fail('Expected off_busy');
        } catch (FoodFactsException $Exception) {
            $this->assertSame('off_busy', $Exception->getErrorCode());
            $this->assertSame(429, $Exception->getHttpStatus());
        }

        $this->assertSame([], $this->Http->requests);

        $Product = $this->Lookup->lookup(self::MILK, false, self::at('10:01:01'));
        $this->assertSame('1 l', $Product->packageSize, 'a minute later it works again');
    }

    public function testUnreachableUsesAnOlderStateButNotOnRefresh(): void
    {
        $this->Lookup->lookup(self::MILK, false, self::at('10:00'));
        $this->Http->offline = true;

        $Old = $this->Lookup->lookup(self::MILK, false, new DateTimeImmutable('2026-11-20 10:00'));
        $this->assertSame('H-Vollmilch 3,5 % Fett', $Old->name);

        $this->expectException(FoodFactsException::class);
        $this->Lookup->lookup(self::MILK, true, new DateTimeImmutable('2026-11-20 10:00'));
    }

    public function testImageOfTheCachedProductOnly(): void
    {
        $this->Http->respond(
            '/images/products/431/150/148/9840/front_de.109.full.jpg',
            new HttpResponse(200, "\xFF\xD8\xFF", 'image/jpeg')
        );

        $image = $this->Lookup->image(self::MILK, true, self::at('10:00'));

        $this->assertSame('image/jpeg', $image['contentType']);
        $this->assertSame(2, count($this->Http->requests), 'product, then image');
    }

    public function testNoImage(): void
    {
        $this->Http->product('4316268526463', 'sparse-4316268526463.json');
        $this->Cache->save(new CacheEntry('4316268526463', ['product_name' => 'Milch frisch'], self::at('10:00')));

        $this->expectException(FoodFactsException::class);
        $this->expectExceptionMessage('no front image');

        $this->Lookup->image('4316268526463', false, self::at('10:01'));
    }
}
