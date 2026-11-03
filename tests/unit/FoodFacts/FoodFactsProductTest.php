<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsProduct;
use MichaelDanielczok\Piklis\FoodFacts\OpenFoodFactsClient;
use MichaelDanielczok\Piklis\FoodFacts\HttpResponse;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\FixtureHttpClient;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_unique;

/**
 * Form der App aus gespeicherten Antworten von Open Food Facts.
 */
class FoodFactsProductTest extends TestCase
{
    private static function product(string $file, string $barcode): FoodFactsProduct
    {
        $product = OpenFoodFactsClient::parse(new HttpResponse(200, FixtureHttpClient::fixture($file)));
        self::assertNotNull($product);

        return FoodFactsProduct::fromApi($product, $barcode, new DateTimeImmutable('2026-11-03T17:00:00+00:00'));
    }

    public function testCompleteMilk(): void
    {
        $Product = self::product('complete-4311501489840.json', '4311501489840');

        $this->assertSame('H-Vollmilch 3,5 % Fett', $Product->name);
        $this->assertSame('de', $Product->nameLanguage);
        $this->assertSame('Edeka, GUT&GÜNSTIG', $Product->brand);
        $this->assertSame('1 l', $Product->packageSize);
        $this->assertSame('Haltbare vollmilch', $Product->ingredients);
        $this->assertSame('de', $Product->ingredientsLanguage);
        $this->assertSame(
            'https://images.openfoodfacts.org/images/products/431/150/148/9840/front_de.109.400.jpg',
            $Product->imageUrl
        );
        $this->assertSame(
            'https://images.openfoodfacts.org/images/products/431/150/148/9840/front_de.109.full.jpg',
            $Product->imageFullUrl()
        );
        $this->assertSame('https://world.openfoodfacts.org/product/4311501489840', $Product->productPage());
        $this->assertSame(
            ['name', 'brand', 'packageSize', 'ingredients', 'nutrition', 'image'],
            $Product->availableFields()
        );
    }

    public function testNutritionPer100MlInLabelOrderWithoutServing(): void
    {
        $nutrition = self::product('complete-4311501489840.json', '4311501489840')->nutrition;

        $this->assertNotNull($nutrition);
        $this->assertSame('ml', $nutrition['per']);
        $this->assertNull($nutrition['servingSize']);
        $this->assertSame(
            ['energy-kj', 'energy-kcal', 'fat', 'saturated-fat', 'carbohydrates', 'sugars', 'proteins', 'salt'],
            array_column($nutrition['rows'], 'key'),
            'fiber is missing at Open Food Facts and stays missing'
        );
        $this->assertSame(
            [267.0, 64.0, 3.5, 2.4, 4.8, 4.8, 3.3, 0.13],
            array_column($nutrition['rows'], 'per100')
        );
        $this->assertSame([null], array_unique(array_column($nutrition['rows'], 'perServing')));
        $this->assertSame('kJ', $nutrition['rows'][0]['unit']);
        $this->assertSame('kcal', $nutrition['rows'][1]['unit']);
    }

    public function testSparseProductLeavesMissingValuesEmpty(): void
    {
        $Product = self::product('sparse-4316268526463.json', '4316268526463');

        $this->assertSame('Milch frisch', $Product->name);
        $this->assertNull($Product->brand);
        $this->assertNull($Product->packageSize, 'empty quantity stays empty');
        $this->assertNull($Product->ingredients);
        $this->assertNull($Product->ingredientsLanguage);
        $this->assertNull($Product->nutrition);
        $this->assertNotNull($Product->imageUrl);
        $this->assertSame(['name', 'image'], $Product->availableFields());
        $this->assertSame('de', $Product->language(), 'German name although the main language is English');
    }

    public function testServingValuesKeepTheirReference(): void
    {
        $Product = self::product('serving-5449000000996.json', '5449000000996');

        $this->assertSame('Coca-Cola', $Product->name, 'German name before the Spanish main name');
        $this->assertSame('330 ml', $Product->packageSize);
        $this->assertSame('de', $Product->ingredientsLanguage);
        $this->assertStringStartsWith('Wasser, Zucker', (string)$Product->ingredients);

        $nutrition = $Product->nutrition;
        $this->assertNotNull($nutrition);
        $this->assertSame('ml', $nutrition['per'], 'filled in ml although Open Food Facts says 100g');
        $this->assertSame('1 portion (330 ml)', $nutrition['servingSize']);

        $kcal = $nutrition['rows'][1];
        $this->assertSame('energy-kcal', $kcal['key']);
        $this->assertSame(42.4, $kcal['per100']);
        $this->assertSame(140.0, $kcal['perServing']);
    }

    public function testApiFormHasNoAddressAtOpenFoodFactsForTheImage(): void
    {
        $data = self::product('complete-4311501489840.json', '4311501489840')->toArray();

        $this->assertTrue($data['hasImage']);
        $this->assertArrayNotHasKey('imageUrl', $data);
        $this->assertSame('2026-11-03T17:00:00+00:00', $data['fetchedAt']);
        $this->assertSame('de', $data['language']);
    }

    public function testOtherLanguagesAreNotUsedForIngredients(): void
    {
        $Product = FoodFactsProduct::fromApi(
            [
                'lang' => 'es',
                'product_name' => 'Galletas',
                'ingredients_text' => 'harina, azúcar',
                'ingredients_text_de' => '',
                'brands' => 'Marca, marca , '
            ],
            '8410000000000',
            new DateTimeImmutable()
        );

        $this->assertSame('Galletas', $Product->name, 'names are often language neutral');
        $this->assertSame('es', $Product->nameLanguage);
        $this->assertNull($Product->ingredients);
        $this->assertSame('Marca', $Product->brand, 'duplicate brands removed');
    }

    public function testNoNutritionDataFlag(): void
    {
        $Product = FoodFactsProduct::fromApi(
            [
                'no_nutrition_data' => 'on',
                'nutriments' => ['energy-kcal_100g' => 10]
            ],
            '4311501489840',
            new DateTimeImmutable()
        );

        $this->assertNull($Product->nutrition);
        $this->assertNull($Product->name);
        $this->assertSame([], $Product->availableFields());
    }
}
