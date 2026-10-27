<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductPermissions;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Pflegerechte am Katalog mit den drei Testpersonen: Michael (`test`) und Ewa
 * (`test2`) sind Produktpfleger, Tomasz (`test3`) nicht.
 */
class ProductServiceTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';

    private const GOUDA = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const MILK = 'aaaaaaaa-0000-4000-8000-000000000002';

    private InMemoryProductStore $Store;

    private ProductService $Service;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Service = new ProductService($this->Store);
    }

    public function testEverybodyCreatesProductsWithJustAName(): void
    {
        $result = $this->Service->create(self::GOUDA, ['name' => 'Gouda jung'], self::TOMASZ);

        $this->assertSame(ProductService::CREATED, $result['status']);
        $this->assertSame('Gouda jung', $result['product']->name);
        $this->assertSame(self::TOMASZ, $result['product']->createdBy);
        $this->assertFalse($result['product']->hidden);
        $this->assertSame($result['product'], $this->Store->find(self::GOUDA));
    }

    public function testCreatingTheSameIdAgainIsIdempotent(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda jung'], self::MICHAEL);
        $again = $this->Service->create(self::GOUDA, ['name' => 'Anderer Name'], self::MICHAEL);

        $this->assertSame(ProductService::UNCHANGED, $again['status']);
        $this->assertSame('Gouda jung', $again['product']->name);
        $this->assertSame(1, $this->Store->writes);
    }

    public function testAnotherUsersIdIsRejected(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda jung'], self::MICHAEL);

        $this->assertProductError(
            'product_exists',
            409,
            fn() => $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::TOMASZ)
        );
    }

    public function testSimilarNamesAreNeverMerged(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL);
        $result = $this->Service->create(self::MILK, ['name' => 'Gouda'], self::TOMASZ);

        $this->assertSame(ProductService::CREATED, $result['status']);
        $this->assertCount(2, $this->Store->all());
    }

    public function testInvalidIdIsRejected(): void
    {
        $this->expectException(InvalidProductException::class);

        $this->Service->create('not-a-uuid', ['name' => 'Gouda'], self::MICHAEL);
    }

    public function testCreatorEditsOwnProduct(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::TOMASZ);

        $Product = $this->Service->update(self::GOUDA, ['description' => 'am Stück'], self::TOMASZ, false);

        $this->assertSame('am Stück', $Product->description);
        $this->assertSame(self::TOMASZ, $Product->updatedBy);
    }

    public function testMaintainerEditsEveryProduct(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL);

        $Product = $this->Service->update(
            self::GOUDA,
            ['name' => 'Gouda jung am Stück', 'category' => 'cheese', 'icon' => 'kaese'],
            self::EWA,
            true
        );

        $this->assertSame('Gouda jung am Stück', $Product->name);
        $this->assertSame('cheese', $Product->category);
        $this->assertSame('kaese', $Product->icon);
        $this->assertSame(self::EWA, $Product->updatedBy);
        $this->assertSame(self::MICHAEL, $Product->createdBy);
    }

    public function testOthersWithoutPermissionMayNotEdit(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL);

        try {
            $this->Service->update(self::GOUDA, ['name' => 'Tomasz war hier'], self::TOMASZ, false);
            $this->fail('Expected forbidden');
        } catch (ProductException $Exception) {
            $this->assertSame('forbidden', $Exception->getErrorCode());
            $this->assertSame(403, $Exception->getHttpStatus());
        }

        $this->assertSame('Gouda', $this->Store->find(self::GOUDA)?->name);
    }

    public function testRightsAreCheckedBeforeTheInput(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL);

        $this->assertProductError(
            'forbidden',
            403,
            fn() => $this->Service->update(self::GOUDA, ['name' => ''], self::TOMASZ, false)
        );
    }

    public function testUnchangedEditDoesNotWrite(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL);
        $before = $this->Store->find(self::GOUDA)?->updatedAt;

        $this->Service->update(self::GOUDA, ['name' => 'Gouda'], self::MICHAEL, true, new DateTimeImmutable('+1 hour'));

        $this->assertSame(1, $this->Store->writes);
        $this->assertSame($before, $this->Store->find(self::GOUDA)?->updatedAt);
    }

    public function testUnknownProduct(): void
    {
        $this->assertProductError(
            'product_not_found',
            404,
            fn() => $this->Service->update(self::GOUDA, ['name' => 'X'], self::MICHAEL, true)
        );
    }

    public function testOnlyMaintainersHide(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::TOMASZ);

        try {
            $this->Service->setHidden(self::GOUDA, true, self::TOMASZ, false);
            $this->fail('Even the creator may not hide without permission');
        } catch (ProductException $Exception) {
            $this->assertSame('forbidden', $Exception->getErrorCode());
        }

        $Product = $this->Service->setHidden(self::GOUDA, true, self::EWA, true);

        $this->assertTrue($Product->hidden);
        $this->assertSame(self::EWA, $Product->hiddenBy);
        $this->assertNotNull($Product->hiddenAt);

        $Product = $this->Service->setHidden(self::GOUDA, false, self::MICHAEL, true);

        $this->assertFalse($Product->hidden);
        $this->assertNull($Product->hiddenBy);
    }

    public function testHiddenProductIsOnlyEditableByMaintainers(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda'], self::TOMASZ);
        $this->Service->setHidden(self::GOUDA, true, self::MICHAEL, true);
        $Product = $this->Store->find(self::GOUDA);

        $this->assertNotNull($Product);
        $this->assertFalse(ProductPermissions::canEdit($Product, self::TOMASZ, false));
        $this->assertTrue(ProductPermissions::canEdit($Product, self::EWA, true));

        $this->assertProductError(
            'forbidden',
            403,
            fn() => $this->Service->update(self::GOUDA, ['name' => 'Gouda alt'], self::TOMASZ, false)
        );
    }

    public function testSearchLeavesOutHiddenProducts(): void
    {
        $this->Service->create(self::GOUDA, ['name' => 'Gouda jung'], self::MICHAEL);
        $this->Service->create(self::MILK, ['name' => 'Gouda alt'], self::MICHAEL);
        $this->Service->setHidden(self::MILK, true, self::MICHAEL, true);

        $names = static fn(array $products): array => array_map(static fn(Product $P): string => $P->name, $products);

        $this->assertSame(['Gouda jung'], $names($this->Service->search('gouda', 20)));
        $this->assertSame(['Gouda alt', 'Gouda jung'], $names($this->Service->search('gouda', 20, true)));
        $this->assertSame([], $this->Service->search('', 20));
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertProductError(string $code, int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected ProductException ' . $code);
        } catch (ProductException $Exception) {
            $this->assertSame($code, $Exception->getErrorCode());
            $this->assertSame($status, $Exception->getHttpStatus());
        }
    }
}
