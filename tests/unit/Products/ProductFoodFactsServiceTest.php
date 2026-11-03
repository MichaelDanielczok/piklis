<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsLookup;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;
use MichaelDanielczok\Piklis\FoodFacts\OpenFoodFactsClient;
use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductFoodFactsService;
use MichaelDanielczok\Piklis\Products\ProductImageService;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\FixtureHttpClient;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\InMemoryFoodFactsCache;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\InMemoryImageMedia;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\Jpeg;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_slice;

/**
 * Produkt mit Open Food Facts ergänzen: nur gewählte Felder, eigene Namen,
 * Fotos und Hinweise bleiben, Fehlendes bleibt leer. Anna legt an (kein
 * Produktpfleger), Ben ist Produktpfleger, Carla weder noch.
 */
class ProductFoodFactsServiceTest extends TestCase
{
    private const ANNA = 'user-anna';
    private const BEN = 'user-ben';
    private const CARLA = 'user-carla';

    private const MILK = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const MILK_BARCODE = '4311501489840';
    private const SPARSE_BARCODE = '4316268526463';
    private const COLA_BARCODE = '5449000000996';

    private InMemoryProductStore $Store;

    private FixtureHttpClient $Http;

    private ProductFoodFactsService $Service;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Http = (new FixtureHttpClient())
            ->product(self::MILK_BARCODE, 'complete-4311501489840.json')
            ->product(self::SPARSE_BARCODE, 'sparse-4316268526463.json')
            ->product(self::COLA_BARCODE, 'serving-5449000000996.json')
            ->product('4099999999019', 'not-found-4099999999019.json', 404);
        $Lookup = new FoodFactsLookup(new InMemoryFoodFactsCache(), new OpenFoodFactsClient($this->Http));
        $this->Service = new ProductFoodFactsService($this->Store, $Lookup);

        (new ProductService($this->Store))->create(
            self::MILK,
            [
                'name' => 'Milch',
                'description' => 'die blaue Packung',
                'packageSize' => '1 l'
            ],
            self::ANNA
        );
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03 17:00:00');
    }

    public function testAppliesChosenFieldsAndKeepsTheOwnName(): void
    {
        $Product = $this->Service->apply(
            self::MILK,
            self::MILK_BARCODE,
            ['brand', 'packageSize', 'ingredients', 'nutrition'],
            self::ANNA,
            false,
            self::now()
        );

        $this->assertSame('Milch', $Product->name, 'own name stays without being asked');
        $this->assertSame('die blaue Packung', $Product->description, 'own notes are never touched');
        $this->assertSame('Edeka, GUT&GÜNSTIG', $Product->brand);
        $this->assertSame('1 l', $Product->packageSize);
        $this->assertSame(self::MILK_BARCODE, $Product->barcode);
        $this->assertNotNull($Product->foodFacts);
        $this->assertSame('Haltbare vollmilch', $Product->foodFacts->ingredients);
        $this->assertSame('de', $Product->foodFacts->language);
        $this->assertEquals(self::now(), $Product->foodFacts->fetchedAt);
        $this->assertSame('ml', $Product->foodFacts->nutrition['per'] ?? null);
        $this->assertSame(self::ANNA, $Product->updatedBy);
        $this->assertSame(2, $this->Store->writes, 'created and updated once');
    }

    public function testNameOnlyWhenChosen(): void
    {
        $Product = $this->Service->apply(self::MILK, self::MILK_BARCODE, ['name'], self::ANNA, false, self::now());

        $this->assertSame('H-Vollmilch 3,5 % Fett', $Product->name);
        $this->assertSame('H-Vollmilch 3,5 % Fett', $this->Store->find(self::MILK)?->name);
        $this->assertNull($Product->brand, 'brand not chosen');
        $this->assertNull($Product->foodFacts?->ingredients, 'ingredients not chosen');
    }

    public function testMissingValuesStayEmptyAndDoNotEraseOwnValues(): void
    {
        $Product = $this->Service->apply(
            self::MILK,
            self::SPARSE_BARCODE,
            ['brand', 'packageSize', 'ingredients', 'nutrition'],
            self::ANNA,
            false,
            self::now()
        );

        $this->assertSame('1 l', $Product->packageSize, 'Open Food Facts has no size, the own one stays');
        $this->assertNull($Product->brand);
        $this->assertNull($Product->foodFacts?->ingredients);
        $this->assertNull($Product->foodFacts?->nutrition);
        $this->assertSame(self::SPARSE_BARCODE, $Product->barcode, 'linked anyway, as the source of the data');
    }

    public function testRefetchKeepsEarlierValuesThatAreNowMissing(): void
    {
        $this->Service->apply(self::MILK, self::MILK_BARCODE, ['ingredients', 'nutrition'], self::ANNA, false, self::now());

        $Product = $this->Service->apply(
            self::MILK,
            self::SPARSE_BARCODE,
            ['ingredients', 'nutrition'],
            self::ANNA,
            false,
            self::now()
        );

        $this->assertSame('Haltbare vollmilch', $Product->foodFacts?->ingredients);
        $this->assertSame('de', $Product->foodFacts?->language);
    }

    public function testServingValuesAreStoredWithTheirReference(): void
    {
        $Product = $this->Service->apply(self::MILK, self::COLA_BARCODE, ['nutrition'], self::ANNA, false, self::now());

        $nutrition = $Product->foodFacts?->nutrition;
        $this->assertNotNull($nutrition);
        $this->assertSame('1 portion (330 ml)', $nutrition['servingSize']);
        $this->assertContains(140.0, array_column($nutrition['rows'], 'perServing'));
    }

    public function testApiFormOfTheProduct(): void
    {
        $Product = $this->Service->apply(self::MILK, self::MILK_BARCODE, ['ingredients'], self::ANNA, false, self::now());
        $data = $Product->toArray();

        $this->assertSame(self::MILK_BARCODE, $data['barcode']);
        $this->assertNull($data['brand']);
        $this->assertSame('Haltbare vollmilch', $data['foodFacts']['ingredients']);
        $this->assertNull($data['foodFacts']['nutrition']);
        $this->assertSame('https://world.openfoodfacts.org/product/4311501489840', $data['foodFacts']['url']);
        $this->assertNull($data['imageSource']);
    }

    public function testNothingChangesWithoutWrite(): void
    {
        $this->Service->apply(self::MILK, self::MILK_BARCODE, ['brand'], self::ANNA, false, self::now());
        $writes = $this->Store->writes;

        $this->Service->apply(self::MILK, self::MILK_BARCODE, ['brand'], self::ANNA, false, self::now());

        $this->assertSame($writes, $this->Store->writes);
    }

    public function testOnlyWhoMayEditMayApply(): void
    {
        $Product = $this->Service->apply(self::MILK, self::MILK_BARCODE, ['brand'], self::BEN, true, self::now());
        $this->assertSame(self::BEN, $Product->updatedBy, 'product maintainers edit all products');

        try {
            $this->Service->apply(self::MILK, self::MILK_BARCODE, ['name'], self::CARLA, false, self::now());
            $this->fail('Expected forbidden');
        } catch (ProductException $Exception) {
            $this->assertSame('forbidden', $Exception->getErrorCode());
        }

        $this->assertSame([], array_slice($this->Http->requests, 1), 'right checked before asking Open Food Facts');
    }

    public function testRightIsCheckedBeforeTheInput(): void
    {
        $this->expectException(ProductException::class);

        $this->Service->apply(self::MILK, 'kein Barcode', 'name', self::CARLA, false, self::now());
    }

    public function testFieldsMustBeChosen(): void
    {
        foreach ([[], 'name', ['description'], ['name', 5]] as $fields) {
            try {
                $this->Service->apply(self::MILK, self::MILK_BARCODE, $fields, self::ANNA, false, self::now());
                $this->fail('Expected InvalidProductException');
            } catch (InvalidProductException $Exception) {
                $this->assertSame('fields', $Exception->getField());
            }
        }

        $this->assertSame([], $this->Http->requests);
    }

    public function testUnknownAndInvalidBarcodes(): void
    {
        try {
            $this->Service->apply(self::MILK, '4099999999019', ['name'], self::ANNA, false, self::now());
            $this->fail('Expected off_not_found');
        } catch (FoodFactsException $Exception) {
            $this->assertSame('off_not_found', $Exception->getErrorCode());
        }

        $this->expectException(InvalidBarcodeException::class);
        $this->Service->apply(self::MILK, '123', ['name'], self::ANNA, false, self::now());
    }

    public function testRemovingTheLinkKeepsTakenOverValues(): void
    {
        $this->Service->apply(
            self::MILK,
            self::MILK_BARCODE,
            ['name', 'brand', 'ingredients'],
            self::ANNA,
            false,
            self::now()
        );

        $Product = $this->Service->remove(self::MILK, self::ANNA, false, self::now());

        $this->assertNull($Product->barcode);
        $this->assertNull($Product->foodFacts);
        $this->assertSame('H-Vollmilch 3,5 % Fett', $Product->name);
        $this->assertSame('Edeka, GUT&GÜNSTIG', $Product->brand);
    }

    public function testImageFromOpenFoodFactsIsMarkedUntilAnOwnPhotoReplacesIt(): void
    {
        $Images = new ProductImageService(
            $this->Store,
            new InMemoryImageMedia(),
            new ImageLimits(2048, 15 * 1024 * 1024)
        );
        $crop = Jpeg::make(1600, 1600);
        $original = Jpeg::make(1200, 1600);

        $Product = $Images->setImage(
            self::MILK,
            $crop,
            $original,
            self::ANNA,
            false,
            null,
            Product::IMAGE_SOURCE_FOOD_FACTS
        );
        $this->assertSame('openfoodfacts', $Product->imageSource);

        $Product = $Images->setImage(self::MILK, $crop, null, self::ANNA, false);
        $this->assertSame('openfoodfacts', $Product->imageSource, 'cropping again keeps the source');

        $Product = $Images->setImage(self::MILK, $crop, $original, self::ANNA, false);
        $this->assertNull($Product->imageSource, 'own photo');

        $Images->setImage(self::MILK, $crop, $original, self::ANNA, false, null, Product::IMAGE_SOURCE_FOOD_FACTS);
        $Product = $Images->removeImage(self::MILK, self::ANNA, false);
        $this->assertNull($Product->imageSource);
    }

    public function testBrandIsAnOrdinaryField(): void
    {
        $Products = new ProductService($this->Store);
        $Product = $Products->update(self::MILK, ['brand' => '  Hofmolkerei  '], self::ANNA, false);

        $this->assertSame('Hofmolkerei', $Product->brand);

        $Product = $Products->update(self::MILK, ['brand' => ''], self::ANNA, false);
        $this->assertNull($Product->brand);
    }
}
