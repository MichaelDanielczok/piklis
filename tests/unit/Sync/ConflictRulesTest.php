<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\EntryStatus;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStatus;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_encode;

/**
 * Konfliktregeln aus „Piklis Entscheidungen“ › „Daten und Synchronisierung“,
 * gespielt mit zwei Personen im selben Haushalt (Michael kauft ein, Ewa
 * bereitet vor). Jede Operation kommt in der Reihenfolge beim Server an, in
 * der sie hier steht; `base` und `seen` sind der Stand, den das jeweilige
 * Gerät kannte.
 */
class ConflictRulesTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';

    private const MILK_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const BREAD_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000002';

    private InMemoryListStore $Store;

    private OperationProcessor $Processor;

    protected function setUp(): void
    {
        $this->Store = new InMemoryListStore();
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL, self::EWA];
        $this->Processor = new OperationProcessor($this->Store);

        $this->assertApplied($this->send(self::EWA, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD)));
        $this->assertApplied($this->send(self::EWA, Ops::add(self::MILK_ENTRY, Ops::MILK, 2, null, 'i')));
        $this->assertApplied($this->send(self::EWA, Ops::add(self::BREAD_ENTRY, Ops::BREAD, 1, null, 'j')));
    }

    // Zusammenführen je Feld

    public function testDifferentFieldsMergeAutomatically(): void
    {
        $note = $this->send(self::MICHAEL, Ops::setNote(self::MILK_ENTRY, 'die blaue', ''));
        $quantity = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));

        $this->assertApplied($note);
        $this->assertApplied($quantity);
        $this->assertSame('die blaue', $this->milk()->note);
        $this->assertSame(3, $this->milk()->quantity->amount());
    }

    public function testDifferentEntriesMergeAutomatically(): void
    {
        // offline Brot abhaken, gleichzeitig online Eier ergänzen
        $eggs = $this->send(self::EWA, Ops::add(Ops::uuid(), Ops::EGGS, 10, null, 'k'));
        $bread = $this->send(self::MICHAEL, Ops::check(self::BREAD_ENTRY, 1));

        $this->assertApplied($eggs);
        $this->assertApplied($bread);
        $this->assertSame(EntryStatus::Done, $this->entry(self::BREAD_ENTRY)->status);
    }

    public function testConcurrentQuantitiesAskWhichOne(): void
    {
        $ewa = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));
        $michael = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [4], [2]));

        $this->assertApplied($ewa);
        $this->assertSame(OperationResult::CONFLICT, $michael->status());
        $this->assertNull($michael->revision());
        $this->assertSame(
            [['type' => 'quantity', 'yours' => ['amount' => 4, 'unit' => null], 'theirs' => ['amount' => 3, 'unit' => null]]],
            $michael->conflicts()
        );
        $this->assertSame(3, $this->milk()->quantity->amount(), 'nothing is overwritten silently');

        // „deine“: Michael schickt seine Menge mit dem Stand, den er jetzt kennt
        $mine = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [4], [3]));

        $this->assertApplied($mine);
        $this->assertSame(4, $this->milk()->quantity->amount());
    }

    public function testSameNewQuantityIsNoConflict(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2])));

        $michael = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));

        $this->assertSame(OperationResult::UNCHANGED, $michael->status());
    }

    public function testUnitCountsAsPartOfTheQuantity(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [2, 'pack'], [2])));

        $michael = $this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));

        $this->assertSame(OperationResult::CONFLICT, $michael->status());
        $this->assertSame('quantity', $michael->conflicts()[0]['type']);
        $this->assertSame('pack', $this->milk()->quantity->unit());
    }

    public function testConcurrentNotesAskWhichOne(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setNote(self::MILK_ENTRY, 'für den Kuchen', '')));

        $michael = $this->send(self::MICHAEL, Ops::setNote(self::MILK_ENTRY, 'laktosefrei', ''));

        $this->assertSame(OperationResult::CONFLICT, $michael->status());
        $this->assertSame(
            [['type' => 'note', 'yours' => 'laktosefrei', 'theirs' => 'für den Kuchen']],
            $michael->conflicts()
        );
        $this->assertSame('für den Kuchen', $this->milk()->note);
    }

    public function testPartOfAChangeIsAppliedBesideAConflict(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2])));

        $michael = $this->send(self::MICHAEL, Ops::make('entry.set', [
            'entryId' => self::MILK_ENTRY,
            'set' => ['quantity' => ['amount' => 4], 'note' => 'große'],
            'base' => ['quantity' => ['amount' => 2], 'note' => '']
        ]));

        $this->assertSame(OperationResult::CONFLICT, $michael->status());
        $this->assertNotNull($michael->revision(), 'the note was applied');
        $this->assertSame('große', $this->milk()->note);
        $this->assertSame(3, $this->milk()->quantity->amount());
    }

    public function testOrderLastChangeWins(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::move(self::MILK_ENTRY, 'k')));
        $this->assertApplied($this->send(self::MICHAEL, Ops::move(self::MILK_ENTRY, 'h')));

        $this->assertSame('h', $this->milk()->sortKey);
        $this->assertSame(
            [self::MILK_ENTRY, self::BREAD_ENTRY],
            array_map(static fn(EntryState $Entry): string => $Entry->id, $this->list()->visibleEntries())
        );
    }

    public function testCheckedEntryKeepsItsSortKey(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2)));
        $this->assertApplied($this->send(self::MICHAEL, Ops::status(self::MILK_ENTRY, 'open')));

        $this->assertSame('i', $this->milk()->sortKey);
        $this->assertNull($this->milk()->checkedQuantity);
    }

    // Alter Offline-Haken

    public function testOldCheckWithHigherQuantityAsks(): void
    {
        // Michael hakt offline 2 ab, Ewa erhöht inzwischen online auf 3
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2])));

        $check = $this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2));

        $this->assertSame(OperationResult::CONFLICT, $check->status());
        $this->assertSame(
            [[
                'type' => 'checkedLess',
                'checked' => ['amount' => 2, 'unit' => null],
                'current' => ['amount' => 3, 'unit' => null]
            ]],
            $check->conflicts()
        );
        $this->assertSame(EntryStatus::Open, $this->milk()->status, 'not silently done');

        // „erledigt“: abhaken mit der jetzt bekannten Menge
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 3)));
        $this->assertSame(EntryStatus::Done, $this->milk()->status);
    }

    public function testOldCheckWithLowerQuantityIsDone(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [1], [2])));
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2)));

        $this->assertSame(EntryStatus::Done, $this->milk()->status);
        $this->assertSame(2, $this->milk()->checkedQuantity?->amount());
    }

    public function testOldCheckWithOtherUnitAsks(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [1, 'pack'], [2])));

        $check = $this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2));

        $this->assertSame('checkedLess', $check->conflicts()[0]['type']);
    }

    public function testRaisingADoneEntryReopensIt(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2)));

        $raise = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));

        $this->assertApplied($raise);
        $this->assertTrue($raise->get('reopened'));
        $this->assertSame(EntryStatus::Open, $this->milk()->status);
        $this->assertSame(2, $this->milk()->checkedQuantity?->amount(), 'keeps what was bought');
    }

    public function testLoweringADoneEntryKeepsItDone(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2)));
        $this->assertApplied($this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [1], [2])));

        $this->assertSame(EntryStatus::Done, $this->milk()->status);
    }

    // Änderung an einem entfernten Eintrag

    public function testChangeOnRemovedEntryAsksToRestore(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::entry('entry.remove', self::MILK_ENTRY)));

        foreach (
            [
                Ops::setQuantity(self::MILK_ENTRY, [3], [2]),
                Ops::setNote(self::MILK_ENTRY, 'x', ''),
                Ops::move(self::MILK_ENTRY, 'z'),
                Ops::check(self::MILK_ENTRY, 2),
                Ops::make('entry.transfer', [
                    'entryId' => self::MILK_ENTRY,
                    'transferredTo' => '33333333-3333-4333-8333-333333333333'
                ])
            ] as $Operation
        ) {
            $Result = $this->send(self::MICHAEL, $Operation);

            $this->assertSame(OperationResult::CONFLICT, $Result->status(), $Operation->type->value);
            $this->assertSame('removed', $Result->conflicts()[0]['type']);
            $this->assertSame(self::EWA, $Result->conflicts()[0]['removedBy']);
            $this->assertNull($Result->revision());
        }

        $this->assertTrue($this->milk()->isRemoved());

        // „wiederherstellen?“ ja: wiederherstellen, dann die Änderung erneut
        $this->assertApplied($this->send(self::MICHAEL, Ops::entry('entry.restore', self::MILK_ENTRY)));
        $this->assertApplied($this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [3], [2])));

        $this->assertFalse($this->milk()->isRemoved());
        $this->assertSame('i', $this->milk()->sortKey, 'back at the old position');
        $this->assertSame(3, $this->milk()->quantity->amount());
    }

    public function testRemoveTwiceIsUnchanged(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::entry('entry.remove', self::MILK_ENTRY)));

        $again = $this->send(self::MICHAEL, Ops::entry('entry.remove', self::MILK_ENTRY));

        $this->assertSame(OperationResult::UNCHANGED, $again->status());
    }

    public function testRestoreWhenProductWasAddedAgainPointsToTheNewEntry(): void
    {
        $this->assertApplied($this->send(self::EWA, Ops::entry('entry.remove', self::MILK_ENTRY)));

        $newMilk = Ops::uuid();
        $this->assertApplied($this->send(self::EWA, Ops::add($newMilk, Ops::MILK, 1, null, 'z')));

        $restore = $this->send(self::MICHAEL, Ops::entry('entry.restore', self::MILK_ENTRY));

        $this->assertSame($newMilk, $restore->get('mergedInto'));
        $this->assertCount(2, $this->list()->visibleEntries(), 'milk only once');

        // Michaels Änderung am alten Eintrag gilt jetzt dem neuen
        $this->assertApplied($this->send(self::MICHAEL, Ops::setQuantity(self::MILK_ENTRY, [2], [1])));
        $this->assertSame(2, $this->entry($newMilk)->quantity->amount());
    }

    // Keine doppelten Einträge

    public function testProductAddedTwiceAtTheSameTimeBecomesOneEntry(): void
    {
        $offline = Ops::uuid();
        $result = $this->send(self::MICHAEL, Ops::add($offline, Ops::MILK, 2, null, 'z'));

        $this->assertSame(OperationResult::APPLIED, $result->status());
        $this->assertSame(self::MILK_ENTRY, $result->get('mergedInto'));
        $this->assertCount(2, $this->list()->visibleEntries());

        // spätere Operationen auf den Verweis gelten dem vorhandenen Eintrag
        $this->assertApplied($this->send(self::MICHAEL, Ops::setNote($offline, 'die blaue', '')));
        $this->assertSame('die blaue', $this->milk()->note);
    }

    public function testProductAddedTwiceWithOtherQuantityAsks(): void
    {
        $result = $this->send(self::MICHAEL, Ops::add(Ops::uuid(), Ops::MILK, 3, null, 'z', 'frisch'));

        $this->assertSame(OperationResult::CONFLICT, $result->status());
        $this->assertSame('quantity', $result->conflicts()[0]['type']);
        $this->assertSame('frisch', $this->milk()->note, 'empty note is filled');
        $this->assertSame(2, $this->milk()->quantity->amount());
    }

    public function testDoneProductMayBeAddedAgain(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::check(self::MILK_ENTRY, 2)));

        $again = Ops::uuid();
        $result = $this->send(self::EWA, Ops::add($again, Ops::MILK, 1, null, 'z'));

        $this->assertApplied($result);
        $this->assertNull($result->get('mergedInto'));
        $this->assertCount(3, $this->list()->visibleEntries());
    }

    // Abgeschlossene Liste

    public function testChangesAfterCompletionAreAppliedAndMarked(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::make('list.complete')));
        $this->assertSame(ListStatus::Completed, $this->list()->status);
        $this->assertFalse($this->list()->changedAfterCompletion);

        $change = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));
        $added = $this->send(self::EWA, Ops::add(Ops::uuid(), Ops::EGGS, 6, null, 'z'));

        $this->assertApplied($change);
        $this->assertTrue($change->get('afterCompletion'));
        $this->assertApplied($added);
        $this->assertSame(3, $this->milk()->quantity->amount());
        $this->assertTrue($this->milk()->changedAfterCompletion);
        $this->assertFalse($this->entry(self::BREAD_ENTRY)->changedAfterCompletion);
        $this->assertTrue($this->list()->changedAfterCompletion);
        $this->assertSame(ListStatus::Completed, $this->list()->status, 'stays in the archive');

        // wieder öffnen löscht die Markierung
        $this->assertApplied($this->send(self::MICHAEL, Ops::make('list.reopen')));
        $this->assertFalse($this->list()->changedAfterCompletion);
        $this->assertFalse($this->milk()->changedAfterCompletion);
    }

    // Revisionen

    public function testEveryEffectiveOperationIsOneRevision(): void
    {
        $this->assertSame(3, $this->list()->revision);

        $first = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [2]));
        $same = $this->send(self::EWA, Ops::setQuantity(self::MILK_ENTRY, [3], [3]));
        $second = $this->send(self::EWA, Ops::move(self::BREAD_ENTRY, 'h'));

        $this->assertSame(4, $first->revision());
        $this->assertNull($same->revision());
        $this->assertSame(5, $second->revision());
        $this->assertSame(5, $this->list()->revision);
        $this->assertSame(4, $this->milk()->revision);
        $this->assertSame(5, $this->entry(self::BREAD_ENTRY)->revision);
    }

    public function testStatusLastChangeWins(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::status(self::MILK_ENTRY, 'notGot')));
        $this->assertApplied($this->send(self::EWA, Ops::status(self::MILK_ENTRY, 'open')));

        $this->assertSame(EntryStatus::Open, $this->milk()->status);
    }

    public function testListNameLastChangeWins(): void
    {
        $this->assertApplied($this->send(self::MICHAEL, Ops::make('list.update', ['fields' => ['name' => 'REWE']])));
        $this->assertApplied($this->send(self::EWA, Ops::make('list.update', ['fields' => ['name' => 'Rewe City']])));

        $this->assertSame('Rewe City', $this->list()->name);
    }

    private function send(string $userId, Operation $Operation): OperationResult
    {
        return $this->Processor->process($Operation, $userId);
    }

    private function assertApplied(OperationResult $Result): void
    {
        $this->assertSame(OperationResult::APPLIED, $Result->status(), (string)json_encode($Result->toArray()));
    }

    private function list(): ListState
    {
        $List = $this->Store->lists[Ops::LIST_ID] ?? null;
        $this->assertNotNull($List);

        return $List;
    }

    private function entry(string $entryId): EntryState
    {
        $Entry = $this->list()->entry($entryId);
        $this->assertNotNull($Entry);

        return $Entry;
    }

    private function milk(): EntryState
    {
        return $this->entry(self::MILK_ENTRY);
    }
}
