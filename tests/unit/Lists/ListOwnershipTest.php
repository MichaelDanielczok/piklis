<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\ListException;
use MichaelDanielczok\Piklis\Lists\ListOwnership;
use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\EntryStatus;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListStatus;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\TestCase;

use function array_key_last;
use function array_map;

/**
 * In den Haushalt verschieben und kopieren, als persönliche Kopie mitnehmen:
 * Rechte und Ergebnis (Konzept „Persönlicher Start und Haushalt“).
 */
class ListOwnershipTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';
    private const COPY_ID = '44444444-4444-4444-8444-444444444444';
    private const MILK_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const BREAD_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const EGGS_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000003';

    private InMemoryListStore $Store;

    private OperationProcessor $Processor;

    private ListOwnership $Ownership;

    protected function setUp(): void
    {
        $this->Store = new InMemoryListStore();
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL, self::EWA];
        $this->Processor = new OperationProcessor($this->Store);
        $this->Ownership = new ListOwnership($this->Store);
    }

    public function testOwnerMovesPersonalListIntoTheHousehold(): void
    {
        $this->personalListOfMichael();
        $this->assertNull($this->Store->roleOf($this->Store->lists[Ops::LIST_ID], self::EWA));

        $List = $this->Ownership->moveToHousehold(Ops::LIST_ID, self::MICHAEL, new DateTimeImmutable());

        $this->assertSame(Ops::LIST_ID, $List->id);
        $this->assertSame(self::HOUSEHOLD, $List->householdId);
        $this->assertNull($List->ownerUser);
        $this->assertSame(3, $List->revision);
        $this->assertSame('list.move', $this->Store->changes[2]->type);
        $this->assertSame(ListRole::Member, $this->Store->roleOf($List, self::MICHAEL));
        $this->assertSame(ListRole::Member, $this->Store->roleOf($List, self::EWA));
        $this->assertNull($this->Store->roleOf($List, self::TOMASZ));

        // Ewa arbeitet jetzt mit, ausstehende Operationen auf die alte ID gelten weiter
        $result = $this->send(self::EWA, Ops::add(self::BREAD_ENTRY, Ops::BREAD));
        $this->assertSame(OperationResult::APPLIED, $result->status());
    }

    public function testMovedListStaysInTheHouseholdWhenTheOwnerLeaves(): void
    {
        $this->personalListOfMichael();
        $this->Ownership->moveToHousehold(Ops::LIST_ID, self::MICHAEL);

        $this->Store->householdMembers[self::HOUSEHOLD] = [self::EWA];

        $List = $this->Store->lists[Ops::LIST_ID];
        $this->assertNull($this->Store->roleOf($List, self::MICHAEL));
        $this->assertSame(ListRole::Member, $this->Store->roleOf($List, self::EWA));
        $this->assertSame('no_access', $this->send(self::MICHAEL, Ops::add(self::BREAD_ENTRY, Ops::BREAD))->get('error'));
    }

    public function testOnlyTheOwnerWithAHouseholdMoves(): void
    {
        $this->personalListOfMichael();

        $this->assertError('no_access', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::EWA));
        $this->assertError('no_access', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::TOMASZ));

        $this->Store->guests[Ops::LIST_ID] = [self::TOMASZ];
        $this->assertError('forbidden', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::TOMASZ));

        $this->Store->householdMembers[self::HOUSEHOLD] = [self::EWA];
        $this->assertError('no_household', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::MICHAEL));

        $this->assertError('list_not_found', fn() => $this->Ownership->moveToHousehold(self::COPY_ID, self::MICHAEL));
        $this->assertSame(self::MICHAEL, $this->Store->lists[Ops::LIST_ID]->ownerUser);
    }

    public function testHouseholdListCannotBeMovedAgain(): void
    {
        $this->send(self::EWA, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD));

        $this->assertError('forbidden', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::MICHAEL));
    }

    public function testDeletedListIsReported(): void
    {
        $this->personalListOfMichael();
        $this->send(self::MICHAEL, Ops::make('list.delete'));

        $this->assertError('list_deleted', fn() => $this->Ownership->moveToHousehold(Ops::LIST_ID, self::MICHAEL));
        $this->assertError('list_deleted', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::MICHAEL));
    }

    public function testCopyIntoTheHouseholdKeepsTheOriginal(): void
    {
        $this->personalListOfMichael();
        $this->send(self::MICHAEL, Ops::add(self::BREAD_ENTRY, Ops::BREAD, 1, null, 'j', 'Vollkorn'));
        $this->send(self::MICHAEL, Ops::add(self::EGGS_ENTRY, Ops::EGGS, 10, null, 'k'));
        $this->send(self::MICHAEL, Ops::make('entry.setStatus', [
            'entryId' => self::BREAD_ENTRY,
            'status' => 'done',
            'seen' => ['amount' => 1, 'unit' => null]
        ]));
        $this->send(self::MICHAEL, Ops::make('entry.remove', ['entryId' => self::EGGS_ENTRY]));

        $result = $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::MICHAEL);
        $Copy = $result['list'];
        $Original = $this->Store->lists[Ops::LIST_ID];

        $this->assertSame('created', $result['status']);
        $this->assertSame(self::HOUSEHOLD, $Copy->householdId);
        $this->assertNull($Copy->ownerUser);
        $this->assertSame(self::MICHAEL, $Original->ownerUser);
        $this->assertSame(1, $Copy->revision);
        $this->assertSame('Lidl', $Copy->name);

        $entries = $Copy->visibleEntries();
        $this->assertSame(
            [Ops::MILK, Ops::BREAD],
            array_map(static fn(EntryState $Entry): string => $Entry->productId, $entries)
        );
        $this->assertNotSame(self::MILK_ENTRY, $entries[0]->id);
        $this->assertSame(2, $entries[0]->quantity->amount());
        $this->assertSame(EntryStatus::Done, $entries[1]->status);
        $this->assertSame('Vollkorn', $entries[1]->note);
        $this->assertSame('list.copy', $this->Store->changes[array_key_last($this->Store->changes)]->type);

        // die Kopie ist unabhängig: Abhaken im Original ändert sie nicht
        $this->send(self::MICHAEL, Ops::make('entry.setStatus', [
            'entryId' => self::MILK_ENTRY,
            'status' => 'done',
            'seen' => ['amount' => 2, 'unit' => null]
        ]));
        $this->assertSame(EntryStatus::Open, $Copy->visibleEntries()[0]->status);
        $this->assertSame(ListRole::Member, $this->Store->roleOf($Copy, self::EWA));
    }

    public function testCopyIsIdempotentThroughTheNewId(): void
    {
        $this->personalListOfMichael();

        $first = $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::MICHAEL);
        $second = $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::MICHAEL);

        $this->assertSame('created', $first['status']);
        $this->assertSame('unchanged', $second['status']);
        $this->assertError('list_exists', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::EWA));
    }

    public function testMemberTakesAPersonalCopyOfAHouseholdList(): void
    {
        $this->send(self::EWA, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD));
        $this->send(self::EWA, Ops::make('list.complete'));

        $Copy = $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::MICHAEL)['list'];

        $this->assertSame(self::MICHAEL, $Copy->ownerUser);
        $this->assertNull($Copy->householdId);
        $this->assertSame(ListStatus::Completed, $Copy->status);
        $this->assertNull($this->Store->roleOf($Copy, self::EWA));
    }

    public function testCopyRights(): void
    {
        $this->personalListOfMichael();

        $this->assertError('invalid_request', fn() => $this->Ownership->copy(Ops::LIST_ID, 'not-a-uuid', 'household', self::MICHAEL));
        $this->assertError('invalid_request', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'elsewhere', self::MICHAEL));
        $this->assertError('invalid_request', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::MICHAEL));
        $this->assertError('no_access', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::EWA));
        $this->assertError('no_access', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::TOMASZ));

        $this->Store->guests[Ops::LIST_ID] = [self::TOMASZ];
        $this->assertError('forbidden', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::TOMASZ));
        $this->assertError('invalid_request', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::TOMASZ));

        $this->Store->householdMembers[self::HOUSEHOLD] = [self::EWA];
        $this->assertError('no_household', fn() => $this->Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'household', self::MICHAEL));
        $this->assertArrayNotHasKey(self::COPY_ID, $this->Store->lists);
    }

    private function personalListOfMichael(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK, 2));
    }

    private function send(string $userId, Operation $Operation): OperationResult
    {
        return $this->Processor->process($Operation, $userId);
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertError(string $code, callable $action): void
    {
        try {
            $action();
        } catch (ListException $Exception) {
            $this->assertSame($code, $Exception->getErrorCode(), $Exception->getMessage());

            return;
        }

        $this->fail('Expected list error ' . $code);
    }
}
