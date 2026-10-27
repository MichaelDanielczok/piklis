<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Sync\EntryStatus;
use MichaelDanielczok\Piklis\Sync\InvalidOperationException;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationType;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const DATE_ATOM;

/**
 * Form der Operationen: Pflichtfelder, IDs, Mengen, Schlüssel.
 */
class OperationTest extends TestCase
{
    private const ENTRY = 'bbbbbbbb-0000-4000-8000-000000000001';

    public function testEntryAddIsParsed(): void
    {
        $Operation = Operation::fromArray([
            'id' => 'AAAAAAAA-0000-4000-8000-0000000000AA',
            'type' => 'entry.add',
            'listId' => Ops::LIST_ID,
            'entryId' => self::ENTRY,
            'productId' => Ops::MILK,
            'quantity' => ['amount' => 1.5, 'unit' => 'kg'],
            'note' => '  reif  ',
            'sortKey' => 'i',
            'createdAt' => '2026-10-27T10:00:00+01:00'
        ]);

        $this->assertSame('aaaaaaaa-0000-4000-8000-0000000000aa', $Operation->id, 'ids are lower case');
        $this->assertSame(OperationType::EntryAdd, $Operation->type);
        $this->assertSame(self::ENTRY, $Operation->entryId);
        $this->assertSame(Ops::MILK, $Operation->productId());
        $this->assertSame(['amount' => 1.5, 'unit' => 'kg'], $Operation->quantity()?->toArray());
        $this->assertSame('reif', $Operation->note());
        $this->assertSame('i', $Operation->sortKey());
        $this->assertSame('2026-10-27T10:00:00+01:00', $Operation->createdAt()?->format(DATE_ATOM));
        $this->assertSame(
            '2026-10-27T09:00:00+00:00',
            Ops::make('list.complete', ['createdAt' => '2026-10-27T09:00:00.000Z'])->createdAt()?->format(DATE_ATOM),
            'toISOString() of the browser'
        );
        $this->assertNull(Ops::make('list.complete', ['createdAt' => 'gestern'])->createdAt());
    }

    public function testEntrySetKeepsBaseValues(): void
    {
        $Operation = Ops::make('entry.set', [
            'entryId' => self::ENTRY,
            'set' => ['quantity' => ['amount' => 3], 'note' => 'neu'],
            'base' => ['quantity' => ['amount' => 2], 'note' => '']
        ]);

        $this->assertSame(3, $Operation->quantity()?->amount());
        $this->assertSame(2, $Operation->baseQuantity()?->amount());
        $this->assertSame('neu', $Operation->note());
        $this->assertSame('', $Operation->baseNote());
    }

    public function testCheckNeedsTheSeenQuantity(): void
    {
        $Operation = Ops::check(self::ENTRY, 2);

        $this->assertSame(EntryStatus::Done, $Operation->status());
        $this->assertSame(2, $Operation->seen()?->amount());

        $this->assertNull(Ops::status(self::ENTRY, 'notGot')->seen());
    }

    public function testListCreateAndUpdate(): void
    {
        $Create = Ops::make('list.create', ['name' => ' Lidl ', 'shopDate' => '2026-10-31']);

        $this->assertSame('Lidl', $Create->name());
        $this->assertSame('2026-10-31', $Create->shopDate());
        $this->assertNull($Create->householdId());

        $Update = Ops::make('list.update', ['fields' => ['shopDate' => null]]);

        $this->assertFalse($Update->hasName());
        $this->assertTrue($Update->hasShopDate());
        $this->assertNull($Update->shopDate());
    }

    public function testIdOfReadsInvalidInput(): void
    {
        $this->assertSame('x', Operation::idOf(['id' => 'x']));
        $this->assertNull(Operation::idOf('nope'));
        $this->assertNull(Operation::idOf(['id' => 5]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOperations(): iterable
    {
        $base = ['id' => Ops::uuid(), 'listId' => Ops::LIST_ID];
        $entry = [...$base, 'entryId' => self::ENTRY];

        yield 'no object' => [[]];
        yield 'unknown type' => [[...$base, 'type' => 'entry.explode']];
        yield 'invalid id' => [['id' => 'abc', 'type' => 'list.complete', 'listId' => Ops::LIST_ID]];
        yield 'missing list' => [['id' => Ops::uuid(), 'type' => 'list.complete']];
        yield 'missing entry' => [[...$base, 'type' => 'entry.remove']];
        yield 'empty name' => [[...$base, 'type' => 'list.create', 'name' => '  ']];
        yield 'long name' => [[...$base, 'type' => 'list.create', 'name' => str_repeat('x', 101)]];
        yield 'bad date' => [[...$base, 'type' => 'list.create', 'name' => 'A', 'shopDate' => '2026-02-30']];
        yield 'update without fields' => [[...$base, 'type' => 'list.update', 'fields' => []]];
        yield 'add without quantity' => [[...$entry, 'type' => 'entry.add', 'productId' => Ops::MILK, 'sortKey' => 'i']];
        yield 'add with decimal pieces' => [[
            ...$entry,
            'type' => 'entry.add',
            'productId' => Ops::MILK,
            'quantity' => ['amount' => 1.5],
            'sortKey' => 'i'
        ]];
        yield 'add with bad sort key' => [[
            ...$entry,
            'type' => 'entry.add',
            'productId' => Ops::MILK,
            'quantity' => ['amount' => 1],
            'sortKey' => 'i0'
        ]];
        yield 'set without base' => [[...$entry, 'type' => 'entry.set', 'set' => ['note' => 'x'], 'base' => []]];
        yield 'set nothing' => [[...$entry, 'type' => 'entry.set', 'set' => [], 'base' => []]];
        yield 'long note' => [[
            ...$entry,
            'type' => 'entry.set',
            'set' => ['note' => str_repeat('x', 501)],
            'base' => ['note' => '']
        ]];
        yield 'unknown status' => [[...$entry, 'type' => 'entry.setStatus', 'status' => 'bought']];
        yield 'done without seen' => [[...$entry, 'type' => 'entry.setStatus', 'status' => 'done']];
        yield 'move without key' => [[...$entry, 'type' => 'entry.move']];
        yield 'transfer to bad id' => [[...$entry, 'type' => 'entry.transfer', 'transferredTo' => 'lidl']];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidOperations')]
    public function testInvalidOperationsAreRejected(array $input): void
    {
        $this->expectException(InvalidOperationException::class);

        Operation::fromArray($input);
    }
}
