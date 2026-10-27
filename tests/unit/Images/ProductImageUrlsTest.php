<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Images;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Images\ImageMedia;
use MichaelDanielczok\Piklis\Images\ProductImageUrls;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductInput;
use MichaelDanielczok\Piklis\Products\ProductPresenter;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\InMemoryImageMedia;
use PHPUnit\Framework\TestCase;

/**
 * Größenadressen: drei WebP-Größen des Zuschnitts im Media-Cache, dazu das
 * Original als JPEG.
 */
class ProductImageUrlsTest extends TestCase
{
    private const UUID = '0f3c2a9e-5b1d-4c7a-9e2f-8d6b4a1c3e5f';

    public function testSizedAddressFollowsTheMediaCacheNaming(): void
    {
        $this->assertSame(
            '/media/cache/piklis/Produktbilder/' . self::UUID . '__128x128.webp',
            ProductImageUrls::sized('/media/cache/piklis/', 'Produktbilder/' . self::UUID . '.jpg', 128)
        );
        $this->assertSame(
            '/media/cache/piklis/' . self::UUID . '__640x640.webp',
            ProductImageUrls::sized('/media/cache/piklis/', self::UUID . '.jpg', 640)
        );
    }

    public function testPathSegmentsAreEncoded(): void
    {
        $this->assertSame(
            '/media/cache/piklis/Produkt%20bilder/a%C3%A4__128x128.webp',
            ProductImageUrls::sized('/media/cache/piklis/', 'Produkt bilder/aä.jpg', 128)
        );
    }

    public function testProductWithImageGetsThreeSizesAndTheOriginal(): void
    {
        $Media = new InMemoryImageMedia();
        $Product = $this->product(
            $Media->store('crop', ImageMedia::KIND_CROP),
            $Media->store('original', ImageMedia::KIND_ORIGINAL)
        );

        $image = ProductImageUrls::load($Media, [$Product])->of($Product);

        $this->assertSame(
            [
                'small' => '/media/cache/piklis/Produktbilder/bild-100__128x128.webp',
                'medium' => '/media/cache/piklis/Produktbilder/bild-100__640x640.webp',
                'large' => '/media/cache/piklis/Produktbilder/bild-100__1600x1600.webp',
                'original' => '/media/cache/piklis/Produktbilder/Originale/bild-101.jpg'
            ],
            $image
        );
    }

    public function testProductWithoutImageOrWithMissingMediaHasNone(): void
    {
        $Media = new InMemoryImageMedia();
        $Without = $this->product(null, null);
        $Missing = $this->product(999, null);
        $Urls = ProductImageUrls::load($Media, [$Without, $Missing]);

        $this->assertNull($Urls->of($Without));
        $this->assertNull($Urls->of($Missing));
    }

    public function testMissingOriginalLeavesTheSizes(): void
    {
        $Media = new InMemoryImageMedia();
        $Product = $this->product($Media->store('crop', ImageMedia::KIND_CROP), 999);

        $image = ProductImageUrls::load($Media, [$Product])->of($Product);

        $this->assertNotNull($image);
        $this->assertNull($image['original']);
    }

    public function testApiFormHasImageAndSmallImageUrl(): void
    {
        $Media = new InMemoryImageMedia();
        $Product = $this->product($Media->store('crop', ImageMedia::KIND_CROP), null);

        $data = (new ProductPresenter($Media))->one($Product);

        $this->assertSame('/media/cache/piklis/Produktbilder/bild-100__128x128.webp', $data['imageUrl']);
        $this->assertSame('/media/cache/piklis/Produktbilder/bild-100__1600x1600.webp', $data['image']['large']);

        $without = (new ProductPresenter())->one($Product);

        $this->assertNull($without['imageUrl']);
        $this->assertNull($without['image']);
    }

    private function product(?int $imageId, ?int $originalId): Product
    {
        $Product = Product::create(
            'aaaaaaaa-0000-4000-8000-00000000000' . ($imageId === null ? '1' : '2'),
            ProductInput::forCreate(['name' => 'Milch']),
            'user',
            new DateTimeImmutable('2026-10-27 12:00:00')
        );

        $Product->imageId = $imageId;
        $Product->imageOriginalId = $originalId;

        return $Product;
    }
}
