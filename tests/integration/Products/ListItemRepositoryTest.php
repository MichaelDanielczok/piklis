<?php

namespace MichaelDanielczok\Piklis\Tests\Integration\Products;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use MichaelDanielczok\Piklis\Database\Tables;
use MichaelDanielczok\Piklis\Lists\ListRepository;
use MichaelDanielczok\Piklis\Products\ListItemUsage;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use MichaelDanielczok\Piklis\Products\ProductService;
use PHPUnit\Framework\TestCase;
use QUI;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function sprintf;

/**
 * Die Abfragen zu Listenartikeln gegen die echte Datenbank: Katalog, Suche,
 * Vorschläge, Zugriff und Nutzung. Alles läuft in einer Transaktion, die am
 * Ende zurückgerollt wird, die Instanz bleibt unverändert.
 */
class ListItemRepositoryTest extends TestCase
{
    private const OWNER = 'test-owner-0001';
    private const MEMBER = 'test-member-0001';
    private const GUEST = 'test-guest-0001';
    private const STRANGER = 'test-stranger-0001';

    private const HOUSEHOLD = 'eeeeeeee-0000-4000-8000-000000000000';
    private const PERSONAL_LIST = 'eeeeeeee-0000-4000-8000-000000000001';
    private const HOUSEHOLD_LIST = 'eeeeeeee-0000-4000-8000-000000000002';
    private const ITEM = 'eeeeeeee-0000-4000-8000-000000000010';
    private const CATALOG_PRODUCT = 'eeeeeeee-0000-4000-8000-000000000011';

    private Connection $Connection;

    private ProductRepository $Products;

    private int $entries = 0;

    protected function setUp(): void
    {
        $this->Connection = QUI::getDataBaseConnection();
        $this->Connection->beginTransaction();
        $this->Products = new ProductRepository($this->Connection);

        $Service = new ProductService($this->Products);

        $Service->create(self::ITEM, ['name' => 'Zzq Lichterkette', 'listItem' => true], self::OWNER);
        $Service->create(self::CATALOG_PRODUCT, ['name' => 'Zzq Lichterschlauch'], self::OWNER);

        $this->insertList(self::PERSONAL_LIST, self::OWNER, null);
        $this->insertList(self::HOUSEHOLD_LIST, null, self::HOUSEHOLD);

        $this->Connection->insert(Tables::name(Tables::HOUSEHOLD_MEMBERS), [
            'user_id' => self::MEMBER,
            'household_id' => self::HOUSEHOLD,
            'role' => 'member',
            'joined_at' => '2026-11-01 10:00:00'
        ]);
        $this->Connection->insert(Tables::name(Tables::LIST_SHARES), [
            'list_id' => self::PERSONAL_LIST,
            'user_id' => self::GUEST,
            'invited_by' => self::OWNER,
            'accepted_at' => '2026-11-01 10:00:00'
        ]);
    }

    protected function tearDown(): void
    {
        $this->Connection->rollBack();
    }

    public function testCatalogLeavesListItemsOut(): void
    {
        $ids = array_map(static fn(Product $Product): string => $Product->id, $this->Products->all());

        $this->assertContains(self::CATALOG_PRODUCT, $ids);
        $this->assertNotContains(self::ITEM, $ids);
    }

    public function testSearchLeavesListItemsOut(): void
    {
        foreach ([false, true] as $includeHidden) {
            $ids = array_map(
                static fn(Product $Product): string => $Product->id,
                $this->Products->findContainingAll(['zzq'], $includeHidden)
            );

            $this->assertSame([self::CATALOG_PRODUCT], $ids);
        }
    }

    public function testSuggestionsLeaveListItemsOut(): void
    {
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM);
        $this->insertEntry(self::PERSONAL_LIST, self::CATALOG_PRODUCT);

        $usages = (new ListRepository($this->Connection))->productUsagesForUser(
            self::OWNER,
            new DateTimeImmutable('2026-01-01')
        );

        $this->assertSame([self::CATALOG_PRODUCT], array_column($usages, 'product_id'));
    }

    public function testListItemsPicksThemFromTheProductsOfAList(): void
    {
        $items = $this->Products->listItems([self::ITEM, self::CATALOG_PRODUCT]);

        $this->assertSame([self::ITEM], array_map(static fn(Product $Product): string => $Product->id, $items));
        $this->assertTrue($items[0]->listItem);
        $this->assertSame([], $this->Products->listItems([]));
    }

    public function testOwnerAndGuestOfAListHaveAccess(): void
    {
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM);

        $this->assertTrue($this->canAccess(self::OWNER));
        $this->assertTrue($this->canAccess(self::GUEST));
        $this->assertFalse($this->canAccess(self::MEMBER));
        $this->assertFalse($this->canAccess(self::STRANGER));
    }

    public function testHouseholdMembersHaveAccess(): void
    {
        $this->insertEntry(self::HOUSEHOLD_LIST, self::ITEM);

        $this->assertTrue($this->canAccess(self::MEMBER));
        $this->assertFalse($this->canAccess(self::GUEST));
    }

    public function testATemplateGivesAccess(): void
    {
        $this->insertTemplate(self::MEMBER, null, self::ITEM);

        $this->assertTrue($this->canAccess(self::MEMBER));
        $this->assertFalse($this->canAccess(self::STRANGER));
    }

    public function testAHouseholdTemplateGivesAccess(): void
    {
        $this->insertTemplate(null, self::HOUSEHOLD, self::ITEM);

        $this->assertTrue($this->canAccess(self::MEMBER));
    }

    public function testARemovedEntryGivesNoAccess(): void
    {
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM, ['removed_at' => '2026-11-02 10:00:00']);

        $this->assertFalse($this->canAccess(self::GUEST));
    }

    public function testADeletedListGivesNoAccess(): void
    {
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM);
        $this->Connection->update(
            Tables::name(Tables::LISTS),
            ['deleted_at' => '2026-11-02 10:00:00', 'deleted_by' => self::OWNER],
            ['id' => self::PERSONAL_LIST]
        );

        $this->assertFalse($this->canAccess(self::GUEST));
    }

    public function testCreatorHasAccessOnlyWhileNothingUsesIt(): void
    {
        $this->assertTrue($this->canAccess(self::OWNER));

        $this->insertEntry(self::HOUSEHOLD_LIST, self::ITEM);

        $this->assertFalse($this->canAccess(self::OWNER));
    }

    public function testUsageCountsVisibleEntriesAndTemplates(): void
    {
        $this->Connection->update(
            Tables::name(Tables::LISTS),
            ['status' => 'completed', 'completed_at' => '2026-10-01 12:00:00'],
            ['id' => self::HOUSEHOLD_LIST]
        );
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM);
        $this->insertEntry(self::HOUSEHOLD_LIST, self::ITEM);
        $this->insertEntry(self::HOUSEHOLD_LIST, self::ITEM, ['removed_at' => '2026-10-01 11:00:00']);
        $this->insertTemplate(self::OWNER, null, self::ITEM);

        $Usage = $this->usage();

        $this->assertSame(1, $Usage->activeLists);
        $this->assertSame(1, $Usage->completedLists);
        $this->assertSame('2026-10-01 12:00:00', $Usage->lastCompletedAt?->format(Tables::DATETIME_FORMAT));
        $this->assertTrue($Usage->inTemplate);
        $this->assertFalse($Usage->isUnused());
    }

    public function testRemovedEntriesAndDeletedTemplatesDoNotCount(): void
    {
        $this->insertEntry(self::PERSONAL_LIST, self::ITEM, ['removed_at' => '2026-10-01 11:00:00']);
        $this->insertTemplate(self::OWNER, null, self::ITEM, '2026-10-02 11:00:00');

        $this->assertTrue($this->usage()->isUnused());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $this->Products->delete(self::ITEM);

        $this->assertNull($this->Products->find(self::ITEM));
    }

    private function canAccess(string $userId): bool
    {
        $Item = $this->Products->find(self::ITEM);

        $this->assertNotNull($Item);

        return $this->Products->canAccessListItem($Item, $userId);
    }

    private function usage(): ListItemUsage
    {
        $usages = array_values(array_filter(
            $this->Products->listItemUsages(),
            static fn(ListItemUsage $Usage): bool => in_array($Usage->Product->id, [self::ITEM], true)
        ));

        $this->assertCount(1, $usages);

        return $usages[0];
    }

    private function insertList(string $id, ?string $owner, ?string $householdId): void
    {
        $this->Connection->insert(Tables::name(Tables::LISTS), [
            'id' => $id,
            'name' => 'Test',
            'owner_user' => $owner,
            'household_id' => $householdId,
            'status' => 'active',
            'created_by' => self::OWNER,
            'created_at' => '2026-11-01 10:00:00',
            'updated_by' => self::OWNER,
            'updated_at' => '2026-11-01 10:00:00'
        ]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function insertEntry(string $listId, string $productId, array $fields = []): void
    {
        $this->entries++;

        $this->Connection->insert(Tables::name(Tables::LIST_ENTRIES), [
            'id' => 'eeeeeeee-1000-4000-8000-' . sprintf('%012d', $this->entries),
            'list_id' => $listId,
            'product_id' => $productId,
            'sort_key' => 'i',
            'status' => 'open',
            'created_by' => self::OWNER,
            'created_at' => '2026-11-01 10:00:00',
            'updated_by' => self::OWNER,
            'updated_at' => '2026-11-01 10:00:00',
            ...$fields
        ]);
    }

    private function insertTemplate(?string $owner, ?string $householdId, string $productId, ?string $deletedAt = null): void
    {
        $this->entries++;
        $id = 'eeeeeeee-2000-4000-8000-' . sprintf('%012d', $this->entries);

        $this->Connection->insert(Tables::name(Tables::TEMPLATES), [
            'id' => $id,
            'name' => 'Test',
            'owner_user' => $owner,
            'household_id' => $householdId,
            'created_by' => self::OWNER,
            'created_at' => '2026-11-01 10:00:00',
            'updated_by' => self::OWNER,
            'updated_at' => '2026-11-01 10:00:00',
            'deleted_at' => $deletedAt
        ]);
        $this->Connection->insert(Tables::name(Tables::TEMPLATE_ENTRIES), [
            'id' => 'eeeeeeee-3000-4000-8000-' . sprintf('%012d', $this->entries),
            'template_id' => $id,
            'product_id' => $productId,
            'sort_key' => 'i'
        ]);
    }
}
