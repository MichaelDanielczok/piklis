<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\ListItemCleanup;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures\InMemoryImageMedia;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

/**
 * Aufräumen: Bild 90 Tage nach dem Abschluss der letzten Liste, außer eine
 * Vorlage nutzt es; Zeile, sobald nichts mehr den Artikel nutzt.
 */
class ListItemCleanupTest extends TestCase
{
    private const ITEM = 'dddddddd-0000-4000-8000-000000000001';
    private const USER = 'user-michael';

    private InMemoryProductStore $Store;

    private InMemoryImageMedia $Media;

    private ListItemCleanup $Cleanup;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Media = new InMemoryImageMedia();
        $this->Cleanup = new ListItemCleanup($this->Store, $this->Media);
        $this->now = new DateTimeImmutable('2026-11-06 03:30:00');
    }

    public function testImageGoes90DaysAfterTheLastCompletion(): void
    {
        $Item = $this->item();
        $this->Store->usages[self::ITEM] = ['completed' => 2, 'lastCompletedAt' => $this->daysAgo(90)];

        $result = $this->Cleanup->run($this->now);

        $this->assertSame(['images' => 1, 'rows' => 0], $result);
        $this->assertNull($Item->imageId);
        $this->assertNull($Item->imageOriginalId);
        $this->assertSame([], $this->Media->media);
        $this->assertSame(ListItemCleanup::USER, $Item->updatedBy);
    }

    public function testNameAndIconStayForTheArchive(): void
    {
        $this->item();
        $this->Store->usages[self::ITEM] = ['completed' => 1, 'lastCompletedAt' => $this->daysAgo(120)];

        $this->Cleanup->run($this->now);

        $Item = $this->Store->find(self::ITEM);

        $this->assertSame('Lichterkette', $Item?->name);
        $this->assertSame('allgemein-test', $Item?->icon);
    }

    public function testImageStaysUntilDay90(): void
    {
        $Item = $this->item();
        $this->Store->usages[self::ITEM] = ['completed' => 1, 'lastCompletedAt' => $this->daysAgo(89)];

        $this->assertSame(['images' => 0, 'rows' => 0], $this->Cleanup->run($this->now));
        $this->assertNotNull($Item->imageId);
    }

    public function testAnActiveListKeepsTheImage(): void
    {
        $Item = $this->item();
        $this->Store->usages[self::ITEM] = [
            'active' => 1,
            'completed' => 1,
            'lastCompletedAt' => $this->daysAgo(200)
        ];

        $this->Cleanup->run($this->now);

        $this->assertNotNull($Item->imageId);
    }

    public function testATemplateKeepsTheImage(): void
    {
        $Item = $this->item();
        $this->Store->usages[self::ITEM] = [
            'completed' => 1,
            'lastCompletedAt' => $this->daysAgo(200),
            'inTemplate' => true
        ];

        $this->Cleanup->run($this->now);

        $this->assertNotNull($Item->imageId);
    }

    public function testATemplateKeepsTheRow(): void
    {
        $this->item();
        $this->Store->usages[self::ITEM] = ['inTemplate' => true];

        $this->assertSame(['images' => 0, 'rows' => 0], $this->Cleanup->run($this->now));
        $this->assertNotNull($this->Store->find(self::ITEM));
    }

    public function testRowGoesWithItsImageWhenNothingUsesIt(): void
    {
        $this->item();

        $this->assertSame(['images' => 0, 'rows' => 1], $this->Cleanup->run($this->now));
        $this->assertNull($this->Store->find(self::ITEM));
        $this->assertSame([], $this->Media->media);
    }

    public function testAFreshItemIsNotDeleted(): void
    {
        $this->item($this->now->modify('-23 hours'));

        $this->assertSame(['images' => 0, 'rows' => 0], $this->Cleanup->run($this->now));
        $this->assertNotNull($this->Store->find(self::ITEM));
    }

    public function testACompletedListKeepsTheRowAfterTheImageIsGone(): void
    {
        $this->item();
        $this->Store->usages[self::ITEM] = ['completed' => 1, 'lastCompletedAt' => $this->daysAgo(200)];

        $this->Cleanup->run($this->now);
        $result = $this->Cleanup->run($this->now);

        $this->assertSame(['images' => 0, 'rows' => 0], $result);
        $this->assertNotNull($this->Store->find(self::ITEM));
    }

    public function testCatalogProductsAreNeverTouched(): void
    {
        $Service = new ProductService($this->Store);
        $id = 'dddddddd-0000-4000-8000-000000000002';

        $Service->create($id, ['name' => 'Milch'], self::USER, $this->daysAgo(400));

        $this->assertSame(['images' => 0, 'rows' => 0], $this->Cleanup->run($this->now));
        $this->assertNotNull($this->Store->find($id));
    }

    public function testAddedToTheCatalogItIsSafe(): void
    {
        $Item = $this->item();

        (new ProductService($this->Store))->addToCatalog(self::ITEM, self::USER, $this->daysAgo(10));

        $this->assertSame(['images' => 0, 'rows' => 0], $this->Cleanup->run($this->now));
        $this->assertNotNull($Item->imageId);
    }

    /**
     * Listenartikel mit Bild, zuletzt vor einer Woche geändert.
     */
    private function item(?DateTimeImmutable $changedAt = null): Product
    {
        $changedAt ??= $this->daysAgo(7);

        $Item = (new ProductService($this->Store))->create(
            self::ITEM,
            ['name' => 'Lichterkette', 'icon' => 'allgemein-test', 'listItem' => true],
            self::USER,
            $changedAt
        )['product'];

        $Item->setImage(
            $this->Media->store('crop', InMemoryImageMedia::KIND_CROP),
            $this->Media->store('original', InMemoryImageMedia::KIND_ORIGINAL),
            self::USER,
            $changedAt
        );

        return $Item;
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return $this->now->modify('-' . $days . ' days');
    }
}
