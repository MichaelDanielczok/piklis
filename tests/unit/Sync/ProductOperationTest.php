<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Sync\ProductOperation;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

/**
 * `product.create` in `POST /ops`: offline angelegte Produkte, idempotent
 * über die Produkt-ID.
 */
class ProductOperationTest extends TestCase
{
    private const PRODUCT = 'cccccccc-0000-4000-8000-000000000001';

    private InMemoryProductStore $Store;

    private ProductOperation $Operation;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Operation = new ProductOperation(new ProductService($this->Store));
    }

    public function testRecognisesTheType(): void
    {
        $this->assertTrue(ProductOperation::isProductOperation(['type' => 'product.create']));
        $this->assertFalse(ProductOperation::isProductOperation(['type' => 'entry.add']));
        $this->assertFalse(ProductOperation::isProductOperation('product.create'));
    }

    public function testCreatesTheProduct(): void
    {
        $Result = $this->Operation->process($this->op('op-1', 'Gouda jung'), 'user-michael');

        $this->assertSame(OperationResult::APPLIED, $Result->status());
        $this->assertSame('op-1', $Result->get('id'));
        $this->assertSame('Gouda jung', $this->Store->find(self::PRODUCT)?->name);
        $this->assertSame(self::PRODUCT, $Result->toArray()['product']['id']);
    }

    public function testSentAgainItIsUnchanged(): void
    {
        $this->Operation->process($this->op('op-1', 'Gouda jung'), 'user-michael');
        $Result = $this->Operation->process($this->op('op-2', 'Gouda'), 'user-michael');

        $this->assertSame(OperationResult::UNCHANGED, $Result->status());
        $this->assertSame('Gouda jung', $this->Store->find(self::PRODUCT)?->name);
    }

    public function testIdOfAnotherUsersProductIsRejected(): void
    {
        $this->Operation->process($this->op('op-1', 'Gouda jung'), 'user-michael');
        $Result = $this->Operation->process($this->op('op-2', 'Gouda'), 'user-tomasz');

        $this->assertTrue($Result->isRejected());
        $this->assertSame('product_exists', $Result->get('error'));
    }

    public function testInvalidFormIsRejected(): void
    {
        $noName = $this->Operation->process($this->op('op-1', ''), 'user-michael');
        $noProduct = $this->Operation->process(['id' => 'op-2', 'type' => 'product.create'], 'user-michael');

        $this->assertSame('invalid_operation', $noName->get('error'));
        $this->assertSame('invalid_operation', $noProduct->get('error'));
        $this->assertSame([], $this->Store->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function op(string $id, string $name): array
    {
        return [
            'id' => $id,
            'type' => ProductOperation::TYPE,
            'productId' => self::PRODUCT,
            'product' => [
                'name' => $name
            ]
        ];
    }
}
