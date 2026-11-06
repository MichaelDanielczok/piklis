<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductImageService;
use MichaelDanielczok\Piklis\Products\ProductInput;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Sync\ProductOperation;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\InMemoryImageMedia;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\Jpeg;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Listenartikel: Sichtbarkeit und Rechte. Michael hat die Lichterkette auf
 * seine Liste gesetzt, Ewa ist Gast dieser Liste, Tomasz hat keinen Zugriff,
 * obwohl er Produktpfleger ist.
 */
class ListItemTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';

    private const LIGHTS = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const MILK = 'bbbbbbbb-0000-4000-8000-000000000002';

    private InMemoryProductStore $Store;

    private ProductService $Service;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Service = new ProductService($this->Store);

        $this->Service->create(self::LIGHTS, ['name' => 'Lichterkette', 'listItem' => true], self::MICHAEL);
        $this->Service->create(self::MILK, ['name' => 'Lichterkette warmweiß'], self::MICHAEL);
        $this->Store->listItemUsers[self::LIGHTS] = [self::MICHAEL, self::EWA];
    }

    public function testCreateMarksTheProductAsListItem(): void
    {
        $Product = $this->Store->find(self::LIGHTS);

        $this->assertTrue($Product?->listItem);
        $this->assertTrue($Product?->toArray()['listItem']);
        $this->assertFalse($this->Store->find(self::MILK)?->listItem);
    }

    public function testListItemMustBeABoolean(): void
    {
        $this->expectException(InvalidProductException::class);

        ProductInput::forCreate(['name' => 'Lichterkette', 'listItem' => 'yes']);
    }

    public function testEditingNeverChangesTheMark(): void
    {
        $Input = ProductInput::forUpdate(['name' => 'Lichterkette', 'listItem' => false]);

        $this->assertFalse($Input->has('listItem'));

        $Product = $this->Service->update(
            self::LIGHTS,
            ['name' => 'Lichterkette 10 m', 'listItem' => false],
            self::MICHAEL,
            false
        );

        $this->assertTrue($Product->listItem);
    }

    public function testProductOperationCreatesAListItem(): void
    {
        $id = 'bbbbbbbb-0000-4000-8000-000000000003';

        $Result = (new ProductOperation($this->Service))->process([
            'id' => 'op-1',
            'type' => 'product.create',
            'productId' => $id,
            'product' => ['name' => 'Geschenkband', 'listItem' => true]
        ], self::EWA);

        $this->assertTrue($this->Store->find($id)?->listItem);
        $this->assertTrue($Result->toArray()['product']['listItem']);
    }

    public function testListItemsStayOutOfTheCatalog(): void
    {
        $names = array_map(static fn(Product $Product): string => $Product->name, $this->Store->all());

        $this->assertSame(['Lichterkette warmweiß'], $names);
    }

    public function testListItemsStayOutOfTheSearch(): void
    {
        $names = array_map(
            static fn(Product $Product): string => $Product->name,
            $this->Service->search('Lichterkette', 20, true)
        );

        $this->assertSame(['Lichterkette warmweiß'], $names);
    }

    public function testListItemsOnlyReturnsListItems(): void
    {
        $items = $this->Store->listItems([self::LIGHTS, self::MILK]);

        $this->assertCount(1, $items);
        $this->assertSame(self::LIGHTS, $items[0]->id);
    }

    public function testPeopleWithAccessSeeTheListItem(): void
    {
        $this->assertSame(self::LIGHTS, $this->Service->loadVisible(self::LIGHTS, self::MICHAEL)->id);
        $this->assertSame(self::LIGHTS, $this->Service->loadVisible(self::LIGHTS, self::EWA)->id);
    }

    public function testWithoutAccessTheListItemDoesNotExist(): void
    {
        $this->expectException(ProductException::class);
        $this->expectExceptionMessage((ProductException::notFound())->getMessage());

        $this->Service->loadVisible(self::LIGHTS, self::TOMASZ);
    }

    public function testCatalogProductsStayVisibleForEverybody(): void
    {
        $this->assertSame(self::MILK, $this->Service->loadVisible(self::MILK, self::TOMASZ)->id);
    }

    public function testCreatorSeesAnItemNothingUsesYet(): void
    {
        $id = 'bbbbbbbb-0000-4000-8000-000000000004';

        $this->Service->create($id, ['name' => 'Geschenkband', 'listItem' => true], self::TOMASZ);

        $this->assertSame($id, $this->Service->loadVisible($id, self::TOMASZ)->id);
    }

    public function testAGuestMayEditAndChangeTheImage(): void
    {
        $Product = $this->Service->update(self::LIGHTS, ['description' => 'Für den Balkon'], self::EWA, false);

        $this->assertSame('Für den Balkon', $Product->description);

        $Images = new ProductImageService(
            $this->Store,
            new InMemoryImageMedia(),
            new ImageLimits(2048, 15 * 1024 * 1024)
        );
        $Product = $Images->setImage(self::LIGHTS, Jpeg::make(1600, 1600), Jpeg::make(2048, 1536), self::EWA, false);

        $this->assertNotNull($Product->imageId);
    }

    public function testAMaintainerWithoutAccessMayNotEdit(): void
    {
        try {
            $this->Service->update(self::LIGHTS, ['description' => 'x'], self::TOMASZ, true);
            $this->fail('Expected product_not_found');
        } catch (ProductException $Exception) {
            $this->assertSame('product_not_found', $Exception->getErrorCode());
        }

        $this->assertNull($this->Store->find(self::LIGHTS)?->description);
    }

    public function testImageNeedsAccessToo(): void
    {
        $Images = new ProductImageService(
            $this->Store,
            new InMemoryImageMedia(),
            new ImageLimits(2048, 15 * 1024 * 1024)
        );

        $this->expectException(ProductException::class);

        $Images->removeImage(self::LIGHTS, self::TOMASZ, true);
    }

    public function testListItemsCannotBeHidden(): void
    {
        try {
            $this->Service->setHidden(self::LIGHTS, true, self::EWA, true);
            $this->fail('Expected forbidden');
        } catch (ProductException $Exception) {
            $this->assertSame('forbidden', $Exception->getErrorCode());
        }

        $this->assertFalse($this->Store->find(self::LIGHTS)?->hidden);
    }

    public function testAGuestMayAddItToTheCatalog(): void
    {
        $Product = $this->Service->addToCatalog(self::LIGHTS, self::EWA);

        $this->assertFalse($Product->listItem);
        $this->assertSame(self::EWA, $Product->updatedBy);
        $this->assertCount(2, $this->Store->all());
        $this->assertCount(2, $this->Service->search('Lichterkette', 20));
    }

    public function testWithoutAccessItCannotBeAddedToTheCatalog(): void
    {
        try {
            $this->Service->addToCatalog(self::LIGHTS, self::TOMASZ);
            $this->fail('Expected product_not_found');
        } catch (ProductException $Exception) {
            $this->assertSame('product_not_found', $Exception->getErrorCode());
        }

        $this->assertTrue($this->Store->find(self::LIGHTS)?->listItem);
    }

    public function testAddingACatalogProductChangesNothing(): void
    {
        $writes = $this->Store->writes;

        $Product = $this->Service->addToCatalog(self::MILK, self::TOMASZ);

        $this->assertFalse($Product->listItem);
        $this->assertSame($writes, $this->Store->writes);
    }

    public function testAfterwardsTheNormalRightsApply(): void
    {
        $this->Service->addToCatalog(self::LIGHTS, self::EWA);

        // Ewa ist Gast und nicht Erstellerin: nur noch Michael oder Produktpfleger
        $this->expectException(ProductException::class);

        $this->Service->update(self::LIGHTS, ['description' => 'x'], self::EWA, false);
    }
}
