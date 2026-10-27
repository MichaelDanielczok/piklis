<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Images\InvalidImageException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductImageService;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\InMemoryImageMedia;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\Jpeg;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_keys;

/**
 * Ablage und Rechte der Produktbilder: Michael (Produktpfleger), Tomasz
 * (kein Produktpfleger) und Ewa.
 */
class ProductImageServiceTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';

    private const MILK = 'aaaaaaaa-0000-4000-8000-000000000002';

    private static string $crop;

    private static string $original;

    private InMemoryProductStore $Store;

    private InMemoryImageMedia $Media;

    private ProductImageService $Service;

    public static function setUpBeforeClass(): void
    {
        self::$crop = Jpeg::make(1600, 1600);
        self::$original = Jpeg::make(2048, 1536);
    }

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Media = new InMemoryImageMedia();
        $this->Service = new ProductImageService($this->Store, $this->Media, new ImageLimits(2048, 15 * 1024 * 1024));

        (new ProductService($this->Store))->create(self::MILK, ['name' => 'Milch 1,5 %'], self::TOMASZ);
    }

    public function testCropAndOriginalAreStoredInTheirFolders(): void
    {
        $Product = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);

        $this->assertNotNull($Product->imageId);
        $this->assertNotNull($Product->imageOriginalId);
        $this->assertSame('Produktbilder/bild-100.jpg', $this->Media->media[$Product->imageId]['file']);
        $this->assertSame('Produktbilder/Originale/bild-101.jpg', $this->Media->media[$Product->imageOriginalId]['file']);
        $this->assertSame(self::$crop, $this->Media->media[$Product->imageId]['content']);
        $this->assertSame($Product, $this->Store->find(self::MILK));
        $this->assertSame(self::TOMASZ, $Product->updatedBy);
    }

    public function testReplacingDeletesTheOldCropAndOriginal(): void
    {
        $First = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);
        [$oldImage, $oldOriginal] = [$First->imageId, $First->imageOriginalId];

        $Product = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);

        $this->assertNotSame($oldImage, $Product->imageId);
        $this->assertNotSame($oldOriginal, $Product->imageOriginalId);
        $this->assertSame([$oldImage, $oldOriginal], $this->Media->deleted);
        $this->assertSame([$Product->imageId, $Product->imageOriginalId], array_keys($this->Media->media));
    }

    public function testRecroppingKeepsTheOriginal(): void
    {
        $First = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);
        $originalId = $First->imageOriginalId;
        $oldImage = $First->imageId;

        $Product = $this->Service->setImage(self::MILK, self::$crop, null, self::TOMASZ, false);

        $this->assertSame($originalId, $Product->imageOriginalId);
        $this->assertNotSame($oldImage, $Product->imageId);
        $this->assertSame([$oldImage], $this->Media->deleted);
    }

    public function testRemovingDeletesBothAndShowsTheIconAgain(): void
    {
        $First = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);
        $stored = [$First->imageId, $First->imageOriginalId];
        $writes = $this->Store->writes;

        $Product = $this->Service->removeImage(self::MILK, self::TOMASZ, false);

        $this->assertNull($Product->imageId);
        $this->assertNull($Product->imageOriginalId);
        $this->assertNull($Product->toArray()['imageUrl']);
        $this->assertSame($stored, $this->Media->deleted);
        $this->assertSame([], $this->Media->media);
        $this->assertSame($writes + 1, $this->Store->writes);
    }

    public function testRemovingWithoutImageChangesNothing(): void
    {
        $writes = $this->Store->writes;
        $before = $this->Store->find(self::MILK)?->updatedAt;

        $Product = $this->Service->removeImage(self::MILK, self::TOMASZ, false, new DateTimeImmutable('+1 hour'));

        $this->assertSame($writes, $this->Store->writes);
        $this->assertSame($before, $Product->updatedAt);
    }

    public function testOthersMayNotChangeTheImageOfSomebodysProduct(): void
    {
        $this->assertForbidden(fn() => $this->Service->setImage(self::MILK, self::$crop, null, self::EWA, false));
        $this->assertForbidden(fn() => $this->Service->removeImage(self::MILK, self::EWA, false));
        $this->assertSame([], $this->Media->media);
    }

    public function testMaintainersChangeEveryImage(): void
    {
        $Product = $this->Service->setImage(self::MILK, self::$crop, self::$original, self::MICHAEL, true);

        $this->assertNotNull($Product->imageId);
        $this->assertNull($this->Service->removeImage(self::MILK, self::MICHAEL, true)->imageId);
    }

    public function testHiddenProductsOnlyForMaintainers(): void
    {
        $Product = $this->product();
        $Product->setHidden(true, self::MICHAEL, new DateTimeImmutable());

        $this->assertForbidden(fn() => $this->Service->setImage(self::MILK, self::$crop, null, self::TOMASZ, false));
        $this->assertNotNull($this->Service->setImage(self::MILK, self::$crop, null, self::MICHAEL, true)->imageId);
    }

    public function testUnknownProduct(): void
    {
        try {
            $this->Service->setImage('aaaaaaaa-0000-4000-8000-0000000000ff', self::$crop, null, self::MICHAEL, true);
            $this->fail('Expected product_not_found.');
        } catch (ProductException $Exception) {
            $this->assertSame('product_not_found', $Exception->getErrorCode());
        }
    }

    public function testInvalidImagesStoreNothing(): void
    {
        try {
            $this->Service->setImage(self::MILK, Jpeg::make(800, 800), self::$original, self::TOMASZ, false);
            $this->fail('Expected wrong_size.');
        } catch (InvalidImageException $Exception) {
            $this->assertSame('wrong_size', $Exception->getReason());
        }

        try {
            $this->Service->setImage(self::MILK, self::$crop, Jpeg::png(100, 100), self::TOMASZ, false);
            $this->fail('Expected not_jpeg.');
        } catch (InvalidImageException $Exception) {
            $this->assertSame('original', $Exception->getField());
        }

        $this->assertSame([], $this->Media->media);
        $this->assertNull($this->product()->imageId);
    }

    public function testFailedStoreLeavesTheProductUnchanged(): void
    {
        $this->Media->failOnStore = true;

        try {
            $this->Service->setImage(self::MILK, self::$crop, self::$original, self::TOMASZ, false);
            $this->fail('Expected the store to fail.');
        } catch (RuntimeException) {
            $this->assertNull($this->product()->imageId);
        }
    }

    private function product(): Product
    {
        $Product = $this->Store->find(self::MILK);
        $this->assertNotNull($Product);

        return $Product;
    }

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected forbidden.');
        } catch (ProductException $Exception) {
            $this->assertSame('forbidden', $Exception->getErrorCode());
            $this->assertSame(403, $Exception->getHttpStatus());
        }
    }
}
