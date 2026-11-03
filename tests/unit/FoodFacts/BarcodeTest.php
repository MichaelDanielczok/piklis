<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use MichaelDanielczok\Piklis\FoodFacts\Barcode;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function validBarcodes(): array
    {
        return [
            'EAN-13' => ['4311501489840', '4311501489840'],
            'EAN-13 mit Leerzeichen' => [' 4 311501 489840 ', '4311501489840'],
            'EAN-13 mit Bindestrichen' => ['4311501-489840', '4311501489840'],
            'EAN-8' => ['22144928', '22144928'],
            'UPC-A' => ['036000291452', '036000291452'],
            'GTIN-14' => ['14311501489847', '14311501489847'],
            'Zahl' => [4311501489840, '4311501489840']
        ];
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
        return [
            'leer' => ['  ', 'required'],
            'null' => [null, 'required'],
            'Buchstaben' => ['43115O1489840', 'invalid'],
            'zu kurz' => ['1234567', 'invalid'],
            '11 Ziffern' => ['12345678901', 'invalid'],
            'Tippfehler' => ['4311501489841', 'checksum'],
            'Zahlendreher' => ['4311501498840', 'checksum']
        ];
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
