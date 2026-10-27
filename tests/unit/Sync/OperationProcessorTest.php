<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Idempotenz, Rechte und Zustände der Liste (gelöscht, verschoben,
 * Freigabe entzogen) ohne Datenbank.
 */
class OperationProcessorTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';
    private const MILK_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000001';

    private InMemoryListStore $Store;

    private OperationProcessor $Processor;

    protected function setUp(): void
    {
        $this->Store = new InMemoryListStore();
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL, self::EWA];
        $this->Processor = new OperationProcessor($this->Store);
    }

    public function testSameOperationTwiceIsExecutedOnce(): void
    {
        $this->send(self::MICHAEL, Ops::createList());

        $Add = Ops::add(self::MILK_ENTRY, Ops::MILK, 2);
        $first = $this->send(self::MICHAEL, $Add);
        $second = $this->send(self::MICHAEL, $Add);

        $this->assertSame(OperationResult::APPLIED, $first->status());
        $this->assertTrue($second->get('duplicate'));
        $this->assertSame($first->revision(), $second->revision());
        $this->assertSame(2, $this->Store->lists[Ops::LIST_ID]->revision);
        $this->assertCount(2, $this->Store->changes);
    }

    public function testRepeatedConflictGivesTheSameAnswer(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK, 2));
        $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));

        $Late = Ops::setQuantity(self::MILK_ENTRY, [4], [2]);
        $first = $this->send(self::MICHAEL, $Late);

        // inzwischen stimmt der Stand, die Wiederholung wird trotzdem nicht ausgeführt
        $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [2], [3]));
        $second = $this->send(self::MICHAEL, $Late);

        $this->assertSame(OperationResult::CONFLICT, $first->status());
        $this->assertSame(OperationResult::CONFLICT, $second->status());
        $this->assertTrue($second->get('duplicate'));
        $this->assertSame(2, $this->Store->lists[Ops::LIST_ID]->entry(self::MILK_ENTRY)?->quantity->amount());
    }

    public function testCreatingTheSameListAgainIsUnchanged(): void
    {
        $this->send(self::MICHAEL, Ops::createList());

        $again = $this->send(self::MICHAEL, Ops::createList());
        $other = $this->send(self::EWA, Ops::createList());

        $this->assertSame(OperationResult::UNCHANGED, $again->status());
        $this->assertSame('list_exists', $other->get('error'));
    }

    public function testPersonalListBelongsToItsCreator(): void
    {
        $created = $this->send(self::MICHAEL, Ops::createList());
        $list = $created->get('list');

        $this->assertIsArray($list);
        $this->assertSame('personal', $list['ownership']);
        $this->assertSame(self::MICHAEL, $list['ownerId']);
        $this->assertSame(1, $created->revision());

        $ewa = $this->send(self::EWA, Ops::add(self::MILK_ENTRY, Ops::MILK));

        $this->assertSame('no_access', $ewa->get('error'));
    }

    public function testHouseholdListNeedsMembership(): void
    {
        $tomasz = $this->send(self::TOMASZ, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD));
        $ewa = $this->send(self::EWA, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD));

        $this->assertSame('no_access', $tomasz->get('error'));
        $this->assertSame(OperationResult::APPLIED, $ewa->status());
        $this->assertSame(
            OperationResult::APPLIED,
            $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK))->status()
        );
    }

    public function testUnknownListIsRejected(): void
    {
        $result = $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));

        $this->assertSame(OperationResult::REJECTED, $result->status());
        $this->assertSame('list_not_found', $result->get('error'));
        $this->assertCount(0, $this->Store->changes, 'rejected operations are not recorded');
    }

    public function testDeletedListRejectsLaterOperations(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));

        $this->assertSame(OperationResult::APPLIED, $this->send(self::MICHAEL, Ops::make('list.delete'))->status());
        $this->assertTrue($this->Store->lists[Ops::LIST_ID]->isDeleted());

        $late = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [2], [1]));

        $this->assertSame('list_deleted', $late->get('error'));
    }

    public function testRevokedShareRejectsPendingOperations(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));
        $this->Store->guests[Ops::LIST_ID] = [self::TOMASZ];

        $this->assertSame(
            OperationResult::APPLIED,
            $this->send(self::TOMASZ, Ops::check(self::MILK_ENTRY, 1))->status()
        );

        // Michael entzieht die Freigabe, Tomasz' offline Änderung kommt danach an
        $this->Store->guests[Ops::LIST_ID] = [];
        $late = $this->send(self::TOMASZ, Ops::status(self::MILK_ENTRY, 'open'));

        $this->assertSame('no_access', $late->get('error'));
    }

    public function testGuestWorksOnTheShoppingButMayNotManageTheList(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->Store->guests[Ops::LIST_ID] = [self::TOMASZ];

        $allowed = [
            Ops::add(self::MILK_ENTRY, Ops::MILK),
            Ops::setQuantity(self::MILK_ENTRY, [2], [1]),
            Ops::setNote(self::MILK_ENTRY, 'groß', ''),
            Ops::move(self::MILK_ENTRY, 'k'),
            Ops::check(self::MILK_ENTRY, 2),
            Ops::entry('entry.remove', self::MILK_ENTRY),
            Ops::entry('entry.restore', self::MILK_ENTRY),
            Ops::make('list.complete'),
            Ops::make('list.reopen')
        ];

        foreach ($allowed as $Operation) {
            $this->assertSame(
                OperationResult::APPLIED,
                $this->send(self::TOMASZ, $Operation)->status(),
                $Operation->type->value
            );
        }

        $forbidden = [
            Ops::make('list.update', ['fields' => ['name' => 'Meins']]),
            Ops::make('list.delete')
        ];

        foreach ($forbidden as $Operation) {
            $this->assertSame('forbidden', $this->send(self::TOMASZ, $Operation)->get('error'), $Operation->type->value);
        }
    }

    public function testMovingIntoTheHouseholdKeepsTheId(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));

        // Schritt 9: „In den Haushalt verschieben“ (REST) ändert nur die Zugehörigkeit
        $List = $this->Store->lists[Ops::LIST_ID];
        $List->ownerUser = null;
        $List->householdId = self::HOUSEHOLD;

        $this->assertSame(ListRole::Member, $this->Store->roleOf($List, self::MICHAEL));

        // Michaels offline Änderung von vorher kommt danach an und gilt weiter
        $late = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [3], [1]));
        $ewa = $this->send(self::EWA, Ops::check(self::MILK_ENTRY, 3));

        $this->assertSame(OperationResult::APPLIED, $late->status());
        $this->assertSame(OperationResult::APPLIED, $ewa->status());
    }

    public function testEntryIdOfAnotherListIsRejected(): void
    {
        $other = '44444444-4444-4444-8444-444444444444';

        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::createList($other));
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));

        $result = $this->send(self::MICHAEL, Operation::fromArray([
            'id' => Ops::uuid(),
            'type' => 'entry.add',
            'listId' => $other,
            'entryId' => self::MILK_ENTRY,
            'productId' => Ops::MILK,
            'quantity' => ['amount' => 1],
            'sortKey' => 'i'
        ]));

        $this->assertSame('invalid_operation', $result->get('error'));
    }

    public function testUnknownEntryIsRejected(): void
    {
        $this->send(self::MICHAEL, Ops::createList());

        $result = $this->send(self::MICHAEL, Ops::move(self::MILK_ENTRY, 'k'));

        $this->assertSame('entry_not_found', $result->get('error'));
    }

    public function testChangeLogKeepsEveryProcessedOperation(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK));
        $this->send(self::MICHAEL, Ops::move(self::MILK_ENTRY, 'i'));

        $this->assertSame(3, count($this->Store->changes));

        [$create, $add, $unchanged] = $this->Store->changes;

        $this->assertSame([1, 2, null], [$create->revision, $add->revision, $unchanged->revision]);
        $this->assertSame('entry.add', $add->type);
        $this->assertSame(self::MILK_ENTRY, $add->entryId);
        $this->assertSame(self::MICHAEL, $add->userId);
        $this->assertSame('unchanged', $unchanged->result['status']);
    }

    private function send(string $userId, Operation $Operation): OperationResult
    {
        return $this->Processor->process($Operation, $userId);
    }
}
