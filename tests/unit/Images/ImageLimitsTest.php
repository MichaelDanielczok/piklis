<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Images;

use MichaelDanielczok\Piklis\Images\ImageLimits;
use PHPUnit\Framework\TestCase;

class ImageLimitsTest extends TestCase
{
    public function testValuesComeFromTheProjectSettings(): void
    {
        $Limits = ImageLimits::fromConfig('2048', '15728640');

        $this->assertSame(2048, $Limits->maxPixels);
        $this->assertSame(15 * 1024 * 1024, $Limits->maxBytes);
        $this->assertSame(
            [
                'maxPixels' => 2048,
                'maxBytes' => 15728640,
                'cropSize' => 1600
            ],
            $Limits->toArray()
        );
    }

    public function testEmptySettingsFallBackToDefaults(): void
    {
        $Limits = ImageLimits::fromConfig('', null);

        $this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $Limits->maxPixels);
        $this->assertSame(ImageLimits::DEFAULT_MAX_BYTES, $Limits->maxBytes);
    }

    public function testInvalidSettingsFallBackToDefaults(): void
    {
        $Limits = ImageLimits::fromConfig('0', 'viel');

        $this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $Limits->maxPixels);
        $this->assertSame(ImageLimits::DEFAULT_MAX_BYTES, $Limits->maxBytes);
    }
}
