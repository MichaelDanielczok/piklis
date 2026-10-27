<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use MichaelDanielczok\Piklis\Lists\InvalidQuantityException;
use MichaelDanielczok\Piklis\Lists\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Mengen und Einheiten; dieselben Fälle prüft tests/js/quantity.test.js.
 */
class QuantityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = (string)file_get_contents(__DIR__ . '/../../fixtures/quantities.json');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{string, ?string, int|float|null, ?string}>
     */
    public static function parseCases(): iterable
    {
        foreach (self::fixture()['parse'] as $case) {
            $name = '"' . $case['input'] . '" ' . ($case['unit'] ?? 'none');

            yield $name => [$case['input'], $case['unit'], $case['amount'] ?? null, $case['error'] ?? null];
        }
    }

    #[DataProvider('parseCases')]
    public function testParseMatchesFixture(string $input, ?string $unit, int|float|null $amount, ?string $error): void
    {
        if ($error !== null) {
            try {
                Quantity::parse($input, $unit);
                $this->fail('Expected error ' . $error);
            } catch (InvalidQuantityException $Exception) {
                $this->assertSame($error, $Exception->getErrorCode());
            }

            return;
        }

        $Quantity = Quantity::parse($input, $unit);

        $this->assertEquals($amount, $Quantity->amount());
        $this->assertSame($unit, $Quantity->unit());
    }

    /**
     * @return iterable<string, array{int|float, ?string, ?string}>
     */
    public static function validateCases(): iterable
    {
        foreach (self::fixture()['validate'] as $case) {
            $name = $case['amount'] . ' ' . ($case['unit'] ?? 'none');

            yield $name => [$case['amount'], $case['unit'], $case['error']];
        }
    }

    #[DataProvider('validateCases')]
    public function testValidateMatchesFixture(int|float $amount, ?string $unit, ?string $error): void
    {
        $this->assertSame($error, Quantity::validate($amount, $unit));
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function formatCases(): iterable
    {
        foreach (self::fixture()['formatAmount'] as $case) {
            yield (string)$case['amount'] => [$case['amount'], $case['text']];
        }
    }

    #[DataProvider('formatCases')]
    public function testFormatAmountMatchesFixture(int|float $amount, string $text): void
    {
        $this->assertSame($text, Quantity::formatAmount($amount));
    }

    public function testFormattedAmountParsesBack(): void
    {
        foreach ([1, 1.5, 0.125, 1000, 1234.5, 9999] as $amount) {
            $this->assertEquals($amount, Quantity::parseAmount(Quantity::formatAmount($amount)));
        }
    }

    public function testJsonShowsWholeNumbersWithoutFraction(): void
    {
        $this->assertSame('{"amount":2,"unit":"pack"}', json_encode(Quantity::create(2.0, 'pack')->toArray()));
        $this->assertSame('{"amount":1.5,"unit":"kg"}', json_encode(Quantity::create(1.5, 'kg')->toArray()));
    }

    public function testDatabaseRoundTrip(): void
    {
        $Quantity = Quantity::create(0.125, 'kg');

        $this->assertSame('0.125', $Quantity->toDatabase());
        $this->assertTrue(Quantity::fromDatabase('0.125', 'kg')->equals($Quantity));
        $this->assertTrue(Quantity::fromDatabase('2.000', null)->equals(Quantity::create(2, null)));
        $this->assertNull(Quantity::fromDatabase('1.000', '')->unit());
    }

    public function testEqualsComparesAmountAndUnit(): void
    {
        $this->assertTrue(Quantity::create(2, null)->equals(Quantity::create(2.0, null)));
        $this->assertFalse(Quantity::create(2, null)->equals(Quantity::create(2, 'pack')));
        $this->assertFalse(Quantity::create(1.5, 'kg')->equals(Quantity::create(1.25, 'kg')));
    }

    public function testExceedsTreatsOtherUnitAsMore(): void
    {
        $this->assertTrue(Quantity::create(3, null)->exceeds(Quantity::create(2, null)));
        $this->assertFalse(Quantity::create(2, null)->exceeds(Quantity::create(2, null)));
        $this->assertFalse(Quantity::create(1, null)->exceeds(Quantity::create(2, null)));
        $this->assertTrue(Quantity::create(1, 'kg')->exceeds(Quantity::create(2, null)));
    }

    public function testFromArrayChecksTypes(): void
    {
        $this->assertTrue(Quantity::fromArray(['amount' => 2])->equals(Quantity::create(2, null)));

        foreach ([null, [], ['amount' => '2'], ['amount' => 2, 'unit' => 5], ['amount' => 1.5, 'unit' => 'pack']] as $data) {
            try {
                Quantity::fromArray($data);
                $this->fail('Expected InvalidQuantityException for ' . json_encode($data));
            } catch (InvalidQuantityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOneIsOnePieceWithoutUnit(): void
    {
        $this->assertSame(['amount' => 1, 'unit' => null], Quantity::one()->toArray());
    }
}
