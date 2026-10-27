<?php

namespace MichaelDanielczok\Piklis\Tests\Integration\Images;

use MichaelDanielczok\Piklis\Images\ImageMedia;
use MichaelDanielczok\Piklis\Images\ProductImageUrls;
use MichaelDanielczok\Piklis\Images\QuiqqerImageMedia;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\Jpeg;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Media\Image;

use function array_values;
use function glob;
use function pathinfo;
use function preg_replace;

use const PATHINFO_FILENAME;

/**
 * Ablage in den echten QUIQQER-Medien des Standardprojekts: Ordner,
 * zufällige Namen, Größenadressen wie der Core, Löschen samt Cache.
 * Räumt alle angelegten Medien wieder ab.
 */
class QuiqqerImageMediaTest extends TestCase
{
    private const UUID_FILE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.jpg$/';

    private QuiqqerImageMedia $Media;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        try {
            $this->Media = new QuiqqerImageMedia();
        } catch (QUI\Exception $Exception) {
            $this->markTestSkipped('No QUIQQER project: ' . $Exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->Media->delete($id);
        }
    }

    public function testCropAndOriginalLandInProductImagesWithRandomNames(): void
    {
        $cropId = $this->store(Jpeg::make(1600, 1600), ImageMedia::KIND_CROP);
        $originalId = $this->store(Jpeg::make(2048, 1536), ImageMedia::KIND_ORIGINAL);

        $files = $this->Media->files([$cropId, $originalId]);

        $this->assertMatchesRegularExpression(
            self::UUID_FILE,
            (string)preg_replace('#^Produktbilder/#', '', $files[$cropId])
        );
        $this->assertMatchesRegularExpression(
            self::UUID_FILE,
            (string)preg_replace('#^Produktbilder/Originale/#', '', $files[$originalId])
        );
        $this->assertStringStartsWith('Produktbilder/', $files[$cropId]);
        $this->assertStringStartsWith('Produktbilder/Originale/', $files[$originalId]);
        $this->assertNotSame($files[$cropId], $files[$originalId]);
    }

    public function testSizedAddressesMatchTheCoreMediaCache(): void
    {
        $cropId = $this->store(Jpeg::make(1600, 1600), ImageMedia::KIND_CROP);
        $file = $this->Media->files([$cropId])[$cropId];
        $Image = QUI::getProjectManager()->getStandard()->getMedia()->get($cropId);

        $this->assertInstanceOf(Image::class, $Image);

        foreach (ProductImageUrls::SIZES as $size) {
            $core = (string)preg_replace('/\.jpe?g$/', '.webp', $Image->getSizeCacheUrl($size, $size));

            $this->assertSame($core, ProductImageUrls::sized($this->Media->cacheUrl(), $file, $size));
        }
    }

    public function testDeleteRemovesMediaAndCacheFiles(): void
    {
        $cropId = $this->Media->store(Jpeg::make(1600, 1600), ImageMedia::KIND_CROP);
        $file = $this->Media->files([$cropId])[$cropId];
        $Image = QUI::getProjectManager()->getStandard()->getMedia()->get($cropId);

        $this->assertInstanceOf(Image::class, $Image);
        $Image->createSizeCache(128, 128);

        $cacheGlob = CMS_DIR . 'media/cache/' . QUI::getProjectManager()->getStandard()->getName()
            . '/Produktbilder/' . pathinfo($file, PATHINFO_FILENAME) . '*';

        $this->assertNotEmpty(glob($cacheGlob));

        $this->Media->delete($cropId);

        $this->assertSame([], $this->Media->files([$cropId]));
        $this->assertSame([], array_values(glob($cacheGlob) ?: []));
    }

    private function store(string $jpeg, string $kind): int
    {
        $id = $this->Media->store($jpeg, $kind);
        $this->created[] = $id;

        return $id;
    }
}
