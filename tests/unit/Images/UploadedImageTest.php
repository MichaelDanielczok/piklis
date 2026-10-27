<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Images;

use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Images\InvalidImageException;
use MichaelDanielczok\Piklis\Images\UploadedImage;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\Jpeg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

class UploadedImageTest extends TestCase
{
    private ImageLimits $Limits;

    protected function setUp(): void
    {
        $this->Limits = new ImageLimits(2048, 15 * 1024 * 1024);
    }

    public function testASquareJpegOfTheCropSizeIsAccepted(): void
    {
        UploadedImage::checkCrop(Jpeg::make(1600, 1600), $this->Limits);

        $this->addToAssertionCount(1);
    }

    public function testAnOriginalUpToTheMaximumIsAccepted(): void
    {
        UploadedImage::checkOriginal(Jpeg::make(2048, 1536), $this->Limits);
        UploadedImage::checkOriginal(Jpeg::make(900, 1200), $this->Limits);

        $this->addToAssertionCount(2);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function wrongCropSizes(): array
    {
        return [
            'too small' => [1200, 1200],
            'not square' => [1600, 1200],
            'too large' => [2000, 2000]
        ];
    }

    #[DataProvider('wrongCropSizes')]
    public function testCropMustHaveTheExactSize(int $width, int $height): void
    {
        $this->assertRejected(
            fn() => UploadedImage::checkCrop(Jpeg::make($width, $height), $this->Limits),
            'image',
            'wrong_size'
        );
    }

    public function testCropMustBeAJpeg(): void
    {
        $this->assertRejected(
            fn() => UploadedImage::checkCrop(Jpeg::png(1600, 1600), $this->Limits),
            'image',
            'not_jpeg'
        );
        $this->assertRejected(
            fn() => UploadedImage::checkCrop('kein Bild', $this->Limits),
            'image',
            'not_jpeg'
        );
    }

    public function testEmptyUploadIsRequired(): void
    {
        $this->assertRejected(fn() => UploadedImage::checkCrop('', $this->Limits), 'image', 'required');
    }

    public function testOriginalLongerThanTheProjectLimitIsRejected(): void
    {
        $this->assertRejected(
            fn() => UploadedImage::checkOriginal(Jpeg::make(2049, 1000), $this->Limits),
            'original',
            'too_many_pixels'
        );
    }

    public function testFilesLargerThanTheProjectLimitAreRejectedWith413(): void
    {
        $jpeg = Jpeg::make(1600, 1600);
        $Limits = new ImageLimits(2048, strlen($jpeg) - 1);

        try {
            UploadedImage::checkCrop($jpeg, $Limits);
            $this->fail('Expected too_large.');
        } catch (InvalidImageException $Exception) {
            $this->assertSame('too_large', $Exception->getReason());
            $this->assertSame(413, $Exception->getHttpStatus());
        }
    }

    private function assertRejected(callable $check, string $field, string $reason): void
    {
        try {
            $check();
            $this->fail('Expected ' . $reason . '.');
        } catch (InvalidImageException $Exception) {
            $this->assertSame($field, $Exception->getField());
            $this->assertSame($reason, $Exception->getReason());
            $this->assertSame(400, $Exception->getHttpStatus());
        }
    }
}
