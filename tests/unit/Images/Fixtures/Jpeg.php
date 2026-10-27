<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures;

use RuntimeException;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function imagepng;
use function ob_get_clean;
use function ob_start;

/**
 * Testbilder mit GD.
 */
final class Jpeg
{
    public static function make(int $width, int $height): string
    {
        $Image = self::canvas($width, $height);

        ob_start();
        imagejpeg($Image, null, 70);

        return (string)ob_get_clean();
    }

    public static function png(int $width, int $height): string
    {
        $Image = self::canvas($width, $height);

        ob_start();
        imagepng($Image);

        return (string)ob_get_clean();
    }

    private static function canvas(int $width, int $height): \GdImage
    {
        $Image = imagecreatetruecolor($width, $height);

        if ($Image === false) {
            throw new RuntimeException('GD could not create an image.');
        }

        $color = imagecolorallocate($Image, 200, 60, 40);

        if ($color !== false) {
            imagefilledrectangle($Image, 0, 0, (int)($width / 2), (int)($height / 2), $color);
        }

        return $Image;
    }
}
