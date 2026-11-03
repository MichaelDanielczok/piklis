<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use MichaelDanielczok\Piklis\FoodFacts\PackageSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackageSizeTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function cases(): array
    {
        return [
            '1000 ml' => [['product_quantity' => 1000, 'product_quantity_unit' => 'ml', 'quantity' => '1l'], '1 l'],
            '1500 ml' => [['product_quantity' => 1500, 'product_quantity_unit' => 'ml'], '1,5 l'],
            '500 g' => [['product_quantity' => '500', 'product_quantity_unit' => 'g'], '500 g'],
            '2500 g' => [['product_quantity' => 2500, 'product_quantity_unit' => 'g'], '2,5 kg'],
            '0,25 l' => [['product_quantity' => 0.25, 'product_quantity_unit' => 'l'], '0,25 l'],
            'nur Text' => [['quantity' => '400 g e'], '400 g'],
            'ohne Leerzeichen' => [['quantity' => '1l'], '1 l'],
            'Stück' => [['quantity' => '1pcs'], '1 Stück'],
            'freier Text' => [['quantity' => '6 x 1,5 l'], '6 x 1,5 l'],
            'leer' => [['quantity' => '  '], null],
            'nichts' => [[], null],
            'Menge 0' => [['product_quantity' => 0, 'product_quantity_unit' => 'g', 'quantity' => ''], null]
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    #[DataProvider('cases')]
    public function testPackageSize(array $product, ?string $expected): void
    {
        $this->assertSame($expected, PackageSize::fromApi($product));
    }
}
