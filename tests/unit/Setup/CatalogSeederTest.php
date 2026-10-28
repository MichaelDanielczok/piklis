<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Setup;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\Categories;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\ProductPermissions;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Products\SearchText;
use MichaelDanielczok\Piklis\Setup\CatalogSeeder;
use MichaelDanielczok\Piklis\Tests\Unit\Products\Fixtures\InMemoryProductStore;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function array_unique;
use function count;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Grundkatalog: einmalig, vorhandene Produkte nie überschrieben, ohne
 * Ersteller (nur Produktpfleger bearbeiten).
 */
class CatalogSeederTest extends TestCase
{
    private const MICHAEL = 'user-michael';

    private const MILK_ID = 'cccccccc-0000-4000-8000-000000000001';
    private const BREAD_ID = 'cccccccc-0000-4000-8000-000000000002';
    private const OWN_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    private InMemoryProductStore $Store;

    private CatalogSeeder $Seeder;

    protected function setUp(): void
    {
        $this->Store = new InMemoryProductStore();
        $this->Seeder = new CatalogSeeder($this->Store);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function items(): array
    {
        return [
            ['id' => self::MILK_ID, 'name' => 'Milch', 'category' => 'dairy', 'icon' => 'milch'],
            ['id' => self::BREAD_ID, 'name' => 'Vollkornbrot', 'category' => 'bread', 'icon' => 'brot']
        ];
    }

    public function testSeedsTheCatalogWithoutCreator(): void
    {
        $result = $this->Seeder->seed(self::items());

        $this->assertSame(['seeded' => true, 'created' => 2, 'skipped' => 0], $result);

        $Milk = $this->Store->find(self::MILK_ID);
        $this->assertNotNull($Milk);
        $this->assertSame('Milch', $Milk->name);
        $this->assertSame('dairy', $Milk->category);
        $this->assertSame('milch', $Milk->icon);
        $this->assertSame(CatalogSeeder::CREATOR, $Milk->createdBy);
    }

    public function testSeedingTwiceCreatesTheCatalogOnlyOnce(): void
    {
        $this->Seeder->seed(self::items());
        $writes = $this->Store->writes;

        $again = $this->Seeder->seed(self::items());

        $this->assertSame(['seeded' => false, 'created' => 0, 'skipped' => 0], $again);
        $this->assertSame($writes, $this->Store->writes);
        $this->assertCount(2, $this->Store->all());
    }

    public function testHiddenOrRenamedCatalogProductsDoNotComeBack(): void
    {
        $this->Seeder->seed(self::items());

        $Service = new ProductService($this->Store);
        $Service->setHidden(self::MILK_ID, true, self::MICHAEL, true);
        $Service->update(self::BREAD_ID, ['name' => 'Dinkelbrot'], self::MICHAEL, true);

        $this->Seeder->seed(self::items());

        $this->assertCount(2, $this->Store->all());
        $this->assertSame('Dinkelbrot', $this->Store->find(self::BREAD_ID)?->name);
        $this->assertTrue($this->Store->find(self::MILK_ID)?->hidden);
    }

    public function testExistingProductsAreNeverOverwrittenOrDuplicated(): void
    {
        $Service = new ProductService($this->Store);
        $Service->create(self::OWN_ID, ['name' => 'milch', 'icon' => 'flasche'], self::MICHAEL);
        $Service->create(self::BREAD_ID, ['name' => 'Mein Brot'], self::MICHAEL);

        $result = $this->Seeder->seed(self::items());

        // „milch“ gibt es schon (gleicher Name), die Brot-ID auch: beide übersprungen
        $this->assertSame(['seeded' => true, 'created' => 0, 'skipped' => 2], $result);
        $this->assertCount(2, $this->Store->all());

        $Own = $this->Store->find(self::OWN_ID);
        $this->assertSame('milch', $Own?->name);
        $this->assertSame('flasche', $Own?->icon);
        $this->assertSame(self::MICHAEL, $Own?->createdBy);
        $this->assertSame('Mein Brot', $this->Store->find(self::BREAD_ID)?->name);
    }

    public function testOnlyProductMaintainersEditCatalogProducts(): void
    {
        $this->Seeder->seed(self::items());
        $Milk = $this->Store->find(self::MILK_ID);
        $this->assertNotNull($Milk);

        $this->assertFalse(ProductPermissions::canEdit($Milk, self::MICHAEL, false));
        $this->assertTrue(ProductPermissions::canEdit($Milk, self::MICHAEL, true));
    }

    public function testAnInvalidEntryWritesNothing(): void
    {
        $items = self::items();
        $items[] = ['id' => 'cccccccc-0000-4000-8000-000000000003', 'name' => 'X', 'category' => 'Obst'];

        try {
            $this->Seeder->seed($items, new DateTimeImmutable('2026-10-28 12:00:00'));
            $this->fail('Invalid category was accepted');
        } catch (InvalidProductException) {
            $this->assertSame(0, $this->Store->writes);
        }
    }

    public function testThePackageCatalogIsValid(): void
    {
        $items = CatalogSeeder::catalog();

        $this->assertGreaterThanOrEqual(140, count($items));
        $this->assertLessThanOrEqual(170, count($items));

        $json = (string)file_get_contents(__DIR__ . '/../../../data/icons.json');
        /** @var array{icons: list<array{key: string}>} $icons */
        $icons = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $iconKeys = array_column($icons['icons'], 'key');

        foreach ($items as $item) {
            $this->assertTrue(Categories::isValid((string)($item['category'] ?? '')), (string)($item['name'] ?? ''));
            $this->assertContains($item['icon'] ?? null, $iconKeys, (string)($item['name'] ?? ''));
        }

        $ids = array_column($items, 'id');
        $names = array_map(static fn(mixed $name): string => SearchText::normalize((string)$name), array_column($items, 'name'));

        $this->assertSame(count($items), count(array_unique($ids)), 'IDs are unique');
        $this->assertSame(count($items), count(array_unique($names)), 'Names are unique');

        // der ganze Datensatz lässt sich anlegen
        $result = $this->Seeder->seed($items);
        $this->assertSame(count($items), $result['created']);
    }
}
