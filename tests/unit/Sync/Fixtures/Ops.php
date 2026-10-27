<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures;

use MichaelDanielczok\Piklis\Sync\Operation;

use function bin2hex;
use function random_bytes;
use function sprintf;
use function substr;

/**
 * Baut Operationen für Tests, wie die App sie schickt.
 */
final class Ops
{
    public const LIST_ID = '11111111-1111-4111-8111-111111111111';
    public const MILK = 'aaaaaaaa-0000-4000-8000-000000000001';
    public const BREAD = 'aaaaaaaa-0000-4000-8000-000000000002';
    public const EGGS = 'aaaaaaaa-0000-4000-8000-000000000003';

    public static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            substr($hex, 17, 3),
            substr($hex, 20, 12)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function make(string $type, array $data = [], string $listId = self::LIST_ID): Operation
    {
        return Operation::fromArray([
            'id' => self::uuid(),
            'type' => $type,
            'listId' => $listId,
            ...$data
        ]);
    }

    public static function createList(string $listId = self::LIST_ID, ?string $householdId = null): Operation
    {
        return self::make('list.create', ['name' => 'Lidl', 'householdId' => $householdId], $listId);
    }

    public static function add(
        string $entryId,
        string $productId,
        int|float $amount = 1,
        ?string $unit = null,
        string $sortKey = 'i',
        string $note = ''
    ): Operation {
        return self::make('entry.add', [
            'entryId' => $entryId,
            'productId' => $productId,
            'quantity' => ['amount' => $amount, 'unit' => $unit],
            'note' => $note,
            'sortKey' => $sortKey
        ]);
    }

    /**
     * Menge ändern; `$base` ist die Menge, die die App vorher kannte.
     *
     * @param array{0: int|float, 1?: string|null} $to
     * @param array{0: int|float, 1?: string|null} $base
     */
    public static function setQuantity(string $entryId, array $to, array $base): Operation
    {
        return self::make('entry.set', [
            'entryId' => $entryId,
            'set' => ['quantity' => ['amount' => $to[0], 'unit' => $to[1] ?? null]],
            'base' => ['quantity' => ['amount' => $base[0], 'unit' => $base[1] ?? null]]
        ]);
    }

    public static function setNote(string $entryId, string $note, string $base): Operation
    {
        return self::make('entry.set', [
            'entryId' => $entryId,
            'set' => ['note' => $note],
            'base' => ['note' => $base]
        ]);
    }

    public static function check(string $entryId, int|float $seenAmount, ?string $seenUnit = null): Operation
    {
        return self::make('entry.setStatus', [
            'entryId' => $entryId,
            'status' => 'done',
            'seen' => ['amount' => $seenAmount, 'unit' => $seenUnit]
        ]);
    }

    public static function status(string $entryId, string $status): Operation
    {
        return self::make('entry.setStatus', ['entryId' => $entryId, 'status' => $status]);
    }

    public static function move(string $entryId, string $sortKey): Operation
    {
        return self::make('entry.move', ['entryId' => $entryId, 'sortKey' => $sortKey]);
    }

    public static function entry(string $type, string $entryId): Operation
    {
        return self::make($type, ['entryId' => $entryId]);
    }
}
