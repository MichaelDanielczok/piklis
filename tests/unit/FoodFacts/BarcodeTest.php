<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use MichaelDanielczok\Piklis\FoodFacts\Barcode;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

class BarcodeTest extends TestCase
{
    /**
     * Gemeinsame Fälle mit `tests/js/barcode.test.js`.
     *
     * @return array{valid: list<array{input: string, barcode: string}>, invalid: list<array{input: string, reason: string}>}
     */
    private static function fixture(): array
    {
        /** @var array{valid: list<array{input: string, barcode: string}>, invalid: list<array{input: string, reason: string}>} $fixture */
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/barcodes.json'), true);

        return $fixture;
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function validBarcodes(): array
    {
        $cases = [
            'Zahl' => [4311501489840, '4311501489840']
        ];

        foreach (self::fixture()['valid'] as $case) {
            $cases['"' . $case['input'] . '"'] = [$case['input'], $case['barcode']];
        }

        return $cases;
    }

    #[DataProvider('validBarcodes')]
    public function testValidBarcodesAreNormalized(mixed $input, string $expected): void
    {
        $this->assertSame($expected, Barcode::normalize($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidBarcodes(): array
    {
        $cases = [
            'null' => [null, 'required']
        ];

        foreach (self::fixture()['invalid'] as $case) {
            $cases['"' . $case['input'] . '"'] = [$case['input'], $case['reason']];
        }

        return $cases;
    }

    #[DataProvider('invalidBarcodes')]
    public function testInvalidBarcodesNameTheReason(mixed $input, string $reason): void
    {
        try {
            Barcode::normalize($input);
            $this->fail('Expected InvalidBarcodeException');
        } catch (InvalidBarcodeException $Exception) {
            $this->assertSame($reason, $Exception->getReason());
        }
    }
}
