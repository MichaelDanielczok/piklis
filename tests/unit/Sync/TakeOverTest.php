<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\EntryStatus;
use MichaelDanielczok\Piklis\Sync\InvalidOperationException;
use MichaelDanielczok\Piklis\Sync\ListState;
use MichaelDanielczok\Piklis\Sync\ListStatus;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function count;
use function json_encode;

/**
 * „Nicht bekommen“ in eine andere Liste übernehmen (Piklis Konzept
 * „Abhaken und Nicht bekommen“, Entscheidungen „Übernehmen in andere
 * Liste“): Lidl wird eingekauft, Gouda und Sahne gibt es nicht, beides geht
 * nach REWE, wo Sahne schon steht. Im Lidl-Einkauf bleibt erkennbar, was
 * nicht gekauft wurde.
 */
class TakeOverTest extends TestCase
{
    private const MICHAEL = 'user-michael';

    private const LIDL = Ops::LIST_ID;
    private const REWE = '33333333-3333-4333-8333-333333333333';

    private const GOUDA = 'aaaaaaaa-0000-4000-8000-00000000000a';
    private const CREAM = 'aaaaaaaa-0000-4000-8000-00000000000b';

    private const LIDL_MILK = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const LIDL_GOUDA = 'bbbbbbbb-0000-4000-8000-000000000002';
    private const LIDL_CREAM = 'bbbbbbbb-0000-4000-8000-000000000003';
    private const REWE_CREAM = 'cccccccc-0000-4000-8000-000000000001';

    private InMemoryListStore $Store;

    private OperationProcessor $Processor;

    protected function setUp(): void
    {
        $this->Store = new InMemoryListStore();
        $this->Processor = new OperationProcessor($this->Store);

        $this->assertApplied($this->send(Ops::createList(self::LIDL)));
        $this->assertApplied($this->send(Ops::make('list.create', ['name' => 'REWE'], self::REWE)));

        $this->assertApplied($this->send(Ops::add(self::LIDL_MILK, Ops::MILK, 2, 'pack', 'i')));
        $this->assertApplied($this->send(Ops::add(self::LIDL_GOUDA, self::GOUDA, 400, 'g', 'j', 'jung')));
        $this->assertApplied($this->send(Ops::add(self::LIDL_CREAM, self::CREAM, 2, 'cup', 'k', 'für den Kuchen')));
        $this->assertApplied($this->send(Ops::make('entry.add', [
            'entryId' => self::REWE_CREAM,
            'productId' => self::CREAM,
            'quantity' => ['amount' => 1, 'unit' => 'cup'],
            'note' => '',
            'sortKey' => 'i'
        ], self::REWE)));
    }

    public function testNotGotEntriesMoveToAnotherListAndStayVisibleInTheOrigin(): void
    {
        $this->assertApplied($this->send(Ops::check(self::LIDL_MILK, 2, 'pack')));
        $this->assertApplied($this->send(Ops::status(self::LIDL_GOUDA, 'notGot')));
        $this->assertApplied($this->send(Ops::status(self::LIDL_CREAM, 'notGot')));

        $gouda = $this->send($this->takeOver(self::LIDL_GOUDA, self::GOUDA, 400, 'g', 'jung', 'j'));
        $cream = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 2, 'cup', 'für den Kuchen', 'k'));

        $this->assertApplied($gouda);
        $this->assertSame('added', $gouda->get('takeOver'));
        $this->assertApplied($cream);
        $this->assertSame('summed', $cream->get('takeOver'));
        $this->assertSame(self::REWE_CREAM, $cream->get('mergedInto'));

        // Ursprung: nicht bekommen, mit Ziel
        $this->assertApplied($this->send(Ops::make('entry.transfer', ['entryId' => self::LIDL_GOUDA, 'transferredTo' => self::REWE])));
        $this->assertApplied($this->send(Ops::make('entry.transfer', ['entryId' => self::LIDL_CREAM, 'transferredTo' => self::REWE])));
        $this->assertApplied($this->send(Ops::make('list.complete')));

        $this->assertSame(ListStatus::Completed, $this->list(self::LIDL)->status);
        $this->assertSame(EntryStatus::NotGot, $this->entry(self::LIDL, self::LIDL_GOUDA)->status);
        $this->assertSame(self::REWE, $this->entry(self::LIDL, self::LIDL_GOUDA)->transferredTo);
        $this->assertSame(self::REWE, $this->entry(self::LIDL, self::LIDL_CREAM)->transferredTo);
        $this->assertFalse($this->list(self::LIDL)->changedAfterCompletion);

        // Ziel: Gouda neu und offen, Sahne addiert und Notiz gefüllt
        $visible = $this->list(self::REWE)->visibleEntries();
        $this->assertCount(2, $visible);

        $reweGouda = $this->openEntryFor(self::REWE, self::GOUDA);
        $this->assertSame(['amount' => 400, 'unit' => 'g'], $reweGouda->quantity->toArray());
        $this->assertSame('jung', $reweGouda->note);
        $this->assertSame(EntryStatus::Open, $reweGouda->status);

        $reweCream = $this->entry(self::REWE, self::REWE_CREAM);
        $this->assertSame(['amount' => 3, 'unit' => 'cup'], $reweCream->quantity->toArray());
        $this->assertSame('für den Kuchen', $reweCream->note);
    }

    public function testSameUnitAddsUp(): void
    {
        $Result = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 2, 'cup', '', 'k'));

        $this->assertApplied($Result);
        $this->assertSame(['amount' => 3, 'unit' => 'cup'], $Result->get('entry')['quantity']);
        $this->assertSame(1, count($this->list(self::REWE)->visibleEntries()), 'no second entry');
    }

    public function testOtherUnitKeepsReplacesOrNotes(): void
    {
        $kept = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 200, 'ml', '', 'k', 'keep', '200 ml'));
        $this->assertSame('kept', $kept->get('takeOver'));
        $this->assertSame(['amount' => 1, 'unit' => 'cup'], $this->creamInRewe()->quantity->toArray());

        $noted = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 200, 'ml', '', 'k', 'note', '200 ml'));
        $this->assertSame('noted', $noted->get('takeOver'));
        $this->assertSame(['amount' => 1, 'unit' => 'cup'], $this->creamInRewe()->quantity->toArray());
        $this->assertSame('+ 200 ml', $this->creamInRewe()->note);

        $replaced = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 200, 'ml', '', 'k', 'replace', '200 ml'));
        $this->assertSame('replaced', $replaced->get('takeOver'));
        $this->assertSame(['amount' => 200, 'unit' => 'ml'], $this->creamInRewe()->quantity->toArray());
    }

    public function testOtherUnitWithoutAnswerKeepsBothInTheNote(): void
    {
        // die App sah keinen Unterschied, inzwischen hat jemand die Einheit geändert
        $Result = $this->send(Ops::make('entry.takeOver', [
            'entryId' => Ops::uuid(),
            'productId' => self::CREAM,
            'quantity' => ['amount' => 400, 'unit' => 'g'],
            'note' => '',
            'sortKey' => 'k',
            'quantityText' => '400 g'
        ], self::REWE));

        $this->assertSame('noted', $Result->get('takeOver'));
        $this->assertSame('+ 400 g', $this->creamInRewe()->note);
    }

    public function testRepeatedTakeOverDoesNotAddTwice(): void
    {
        $op = $this->takeOver(self::LIDL_CREAM, self::CREAM, 2, 'cup', '', 'k');
        $this->assertApplied($this->send($op));

        $again = $this->send($op);
        $this->assertTrue($again->get('duplicate'));

        // neue Operations-ID, aber dieselbe Eintrags-ID (App hat neu gebaut)
        $rebuilt = Ops::make('entry.takeOver', [...$op->payload, 'id' => Ops::uuid()], self::REWE);
        $Result = $this->send($rebuilt);

        $this->assertSame(OperationResult::UNCHANGED, $Result->status());
        $this->assertSame(['amount' => 3, 'unit' => 'cup'], $this->creamInRewe()->quantity->toArray());
    }

    public function testDoneOrNotGotEntriesInTheTargetGetANewEntry(): void
    {
        $this->assertApplied($this->send(Ops::make('entry.setStatus', [
            'entryId' => self::REWE_CREAM,
            'status' => 'notGot'
        ], self::REWE)));

        $Result = $this->send($this->takeOver(self::LIDL_CREAM, self::CREAM, 2, 'cup', '', 'k'));

        $this->assertSame('added', $Result->get('takeOver'));
        $this->assertCount(2, $this->list(self::REWE)->visibleEntries());
        $this->assertSame(['amount' => 1, 'unit' => 'cup'], $this->entry(self::REWE, self::REWE_CREAM)->quantity->toArray());
    }

    public function testAddingAProductWithANotGotEntryAddsAnOpenOne(): void
    {
        // Entscheidung Schritt 7: „nicht bekommen“ zählt wie „erledigt“ nicht als drauf
        $this->assertApplied($this->send(Ops::status(self::LIDL_GOUDA, 'notGot')));

        $entryId = Ops::uuid();
        $Result = $this->send(Ops::add($entryId, self::GOUDA, 1, null, 'z'));

        $this->assertApplied($Result);
        $this->assertNull($Result->get('mergedInto'));
        $this->assertSame(EntryStatus::Open, $this->entry(self::LIDL, $entryId)->status);
        $this->assertSame(EntryStatus::NotGot, $this->entry(self::LIDL, self::LIDL_GOUDA)->status);
    }

    public function testTakeOverIntoACompletedListIsMarked(): void
    {
        $this->assertApplied($this->send(Ops::make('list.complete', [], self::REWE)));

        $Result = $this->send($this->takeOver(self::LIDL_GOUDA, self::GOUDA, 400, 'g', '', 'j'));

        $this->assertTrue($Result->get('afterCompletion'));
        $this->assertTrue($this->list(self::REWE)->changedAfterCompletion);
    }

    public function testUnknownProductIsRejected(): void
    {
        $this->Store->unknownProducts[] = self::GOUDA;

        $Result = $this->send($this->takeOver(self::LIDL_GOUDA, self::GOUDA, 400, 'g', '', 'j'));

        $this->assertSame('product_not_found', $Result->get('error'));
    }

    public function testUnknownModeIsInvalid(): void
    {
        $this->expectException(InvalidOperationException::class);
        $this->takeOver(self::LIDL_GOUDA, self::GOUDA, 400, 'g', '', 'j', 'merge');
    }

    private function takeOver(
        string $fromEntryId,
        string $productId,
        int|float $amount,
        ?string $unit,
        string $note,
        string $sortKey,
        ?string $mode = null,
        string $quantityText = ''
    ): Operation {
        $data = [
            'entryId' => Ops::uuid(),
            'productId' => $productId,
            'quantity' => ['amount' => $amount, 'unit' => $unit],
            'note' => $note,
            'sortKey' => $sortKey,
            'fromListId' => self::LIDL,
            'fromEntryId' => $fromEntryId,
            'quantityText' => $quantityText
        ];

        if ($mode !== null) {
            $data['onUnitMismatch'] = $mode;
        }

        return Ops::make('entry.takeOver', $data, self::REWE);
    }

    private function send(Operation $Operation): OperationResult
    {
        return $this->Processor->process($Operation, self::MICHAEL);
    }

    private function assertApplied(OperationResult $Result): void
    {
        $this->assertSame(OperationResult::APPLIED, $Result->status(), (string)json_encode($Result->toArray()));
    }

    private function list(string $listId): ListState
    {
        $List = $this->Store->lists[$listId] ?? null;
        $this->assertNotNull($List);

        return $List;
    }

    private function entry(string $listId, string $entryId): EntryState
    {
        $Entry = $this->list($listId)->entry($entryId);
        $this->assertNotNull($Entry);

        return $Entry;
    }

    private function creamInRewe(): EntryState
    {
        return $this->entry(self::REWE, self::REWE_CREAM);
    }

    private function openEntryFor(string $listId, string $productId): EntryState
    {
        $entries = array_values(array_filter(
            $this->list($listId)->visibleEntries(),
            static fn(EntryState $Entry): bool => $Entry->productId === $productId
        ));

        $this->assertCount(1, $entries);

        return $entries[0];
    }
}
