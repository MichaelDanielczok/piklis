<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\ListAccess;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;
use PHPUnit\Framework\TestCase;

/**
 * Wer welche Liste sieht: Besitzer, Haushaltsmitglieder, sonst niemand
 * (auch nicht Mitglieder bei persönlichen Listen anderer).
 */
class ListAccessTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';
    private const OTHER_HOUSEHOLD = '33333333-3333-4333-8333-333333333333';

    public function testPersonalListOnlyForItsOwner(): void
    {
        $List = $this->list(self::MICHAEL, null);

        $this->assertSame(ListRole::Owner, ListAccess::role($List, self::MICHAEL, self::HOUSEHOLD));
        // Ewa im selben Haushalt sieht Michaels persönliche Liste nicht
        $this->assertNull(ListAccess::role($List, self::EWA, self::HOUSEHOLD));
        $this->assertNull(ListAccess::role($List, self::TOMASZ, null));
    }

    public function testHouseholdListForAllMembers(): void
    {
        $List = $this->list(null, self::HOUSEHOLD);

        $this->assertSame(ListRole::Member, ListAccess::role($List, self::MICHAEL, self::HOUSEHOLD));
        $this->assertSame(ListRole::Member, ListAccess::role($List, self::EWA, self::HOUSEHOLD));
        $this->assertNull(ListAccess::role($List, self::TOMASZ, null));
        $this->assertNull(ListAccess::role($List, self::TOMASZ, self::OTHER_HOUSEHOLD));
    }

    public function testStrongestRoleCounts(): void
    {
        $Household = $this->list(null, self::HOUSEHOLD);
        $Personal = $this->list(self::MICHAEL, null);

        $this->assertSame(ListRole::Member, ListAccess::role($Household, self::EWA, self::HOUSEHOLD, true));
        $this->assertSame(ListRole::Owner, ListAccess::role($Personal, self::MICHAEL, null, true));
        $this->assertSame(ListRole::Guest, ListAccess::role($Household, self::TOMASZ, null, true));
    }

    private function list(?string $owner, ?string $householdId): ListState
    {
        $List = ListState::create(
            '11111111-1111-4111-8111-111111111111',
            'Lidl',
            null,
            $householdId,
            $owner ?? self::EWA,
            new DateTimeImmutable('2026-10-28 10:00:00')
        );

        $List->ownerUser = $owner;

        return $List;
    }
}
