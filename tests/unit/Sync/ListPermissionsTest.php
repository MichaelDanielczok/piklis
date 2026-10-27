<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Sync\ListPermissions;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\OperationType;
use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * Rechte je Operation und Rolle (Konzept „Einzelne Listen mit Außenstehenden teilen“).
 */
class ListPermissionsTest extends TestCase
{
    public function testOwnerAndMemberMayDoEverything(): void
    {
        foreach ([ListRole::Owner, ListRole::Member] as $Role) {
            foreach (OperationType::cases() as $Type) {
                $expected = $Type !== OperationType::ListCreate;

                $this->assertSame($expected, ListPermissions::allows($Role, $Type), $Role->value . ' ' . $Type->value);
            }

            $this->assertTrue(ListPermissions::canManageSharing($Role));
        }
    }

    public function testGuestWorksOnEntriesAndTheShopping(): void
    {
        $allowed = [
            OperationType::EntryAdd,
            OperationType::EntrySet,
            OperationType::EntryMove,
            OperationType::EntrySetStatus,
            OperationType::EntryRemove,
            OperationType::EntryRestore,
            OperationType::EntryTransfer,
            OperationType::ListComplete,
            OperationType::ListReopen
        ];

        foreach (OperationType::cases() as $Type) {
            $this->assertSame(
                in_array($Type, $allowed, true),
                ListPermissions::allows(ListRole::Guest, $Type),
                $Type->value
            );
        }

        $this->assertFalse(ListPermissions::canManageSharing(ListRole::Guest));
    }
}
