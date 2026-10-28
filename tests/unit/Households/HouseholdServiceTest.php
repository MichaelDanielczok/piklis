<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Households;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Households\HouseholdException;
use MichaelDanielczok\Piklis\Households\HouseholdRole;
use MichaelDanielczok\Piklis\Households\HouseholdService;
use MichaelDanielczok\Piklis\Households\InviteCode;
use MichaelDanielczok\Piklis\Tests\Unit\Households\Fixtures\InMemoryHouseholdStore;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Regeln des Haushalts (Konzept „Haushaltsrollen“, Entscheidungen „Haushalt
 * und Teilen“): Anlegen, Code, Beitritt, Wechsel, Rollen, Austritt, Auflösen.
 */
class HouseholdServiceTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';

    private InMemoryHouseholdStore $Store;

    private HouseholdService $Service;

    private DateTimeImmutable $now;

    private int $codes = 0;

    protected function setUp(): void
    {
        $this->Store = new InMemoryHouseholdStore();
        $this->now = new DateTimeImmutable('2026-10-28 10:00:00');
        $this->Service = new HouseholdService(
            $this->Store,
            fn(): string => 'CODE' . str_pad((string)++$this->codes, 4, '0', STR_PAD_LEFT)
        );
    }

    public function testCreatorBecomesAdminWithInviteValidForSevenDays(): void
    {
        $Household = $this->Service->create(self::MICHAEL, '  Familie   D. ', $this->now);

        $this->assertSame('Familie D.', $Household->name);
        $this->assertSame(HouseholdRole::Admin, $this->Store->members[self::MICHAEL]->role);
        $this->assertSame('CODE0001', $Household->activeInviteCode($this->now));
        $this->assertSame('CODE0001', $Household->activeInviteCode($this->now->modify('+6 days 23 hours')));
        $this->assertNull($Household->activeInviteCode($this->now->modify('+7 days')));
    }

    public function testNameIsRequiredAndLimited(): void
    {
        $this->assertError('invalid_name', fn() => $this->Service->create(self::MICHAEL, '  ', $this->now));
        $this->assertError('invalid_name', fn() => $this->Service->create(self::MICHAEL, str_repeat('x', 101), $this->now));
        $this->assertError('invalid_name', fn() => $this->Service->create(self::MICHAEL, null, $this->now));
    }

    public function testAtMostOneHouseholdPerPerson(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);

        $this->assertError('already_member', fn() => $this->Service->create(self::MICHAEL, 'Zweiter', $this->now));
    }

    public function testCodeCanBeUsedSeveralTimesAndIsNormalised(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);

        $this->Service->join(self::EWA, 'code-0001', now: $this->now);
        $this->Service->join(self::TOMASZ, ' CODE 0001 ', now: $this->now->modify('+1 day'));

        $this->assertSame(HouseholdRole::Member, $this->Store->members[self::EWA]->role);
        $this->assertSame(HouseholdRole::Member, $this->Store->members[self::TOMASZ]->role);
        $this->assertSame('CODE0001', InviteCode::normalize('code-0001'));
    }

    public function testExpiredOrUnknownCodeIsRejected(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);

        $this->assertError('invalid_code', fn() => $this->Service->join(self::EWA, 'CODE0001', now: $this->now->modify('+7 days')));
        $this->assertError('invalid_code', fn() => $this->Service->join(self::EWA, 'NOPE', now: $this->now));
        $this->assertError('invalid_code', fn() => $this->Service->join(self::EWA, '', now: $this->now));
        $this->assertArrayNotHasKey(self::EWA, $this->Store->members);
    }

    public function testRenewedCodeReplacesTheOldOneAndIsForAdminsOnly(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);

        $this->assertError('forbidden', fn() => $this->Service->renewInvite(self::EWA, $this->now));

        $Household = $this->Service->renewInvite(self::MICHAEL, $this->now->modify('+6 days'));

        $this->assertSame('CODE0002', $Household->activeInviteCode($this->now->modify('+12 days')));
        $this->assertError('invalid_code', fn() => $this->Service->join(self::TOMASZ, 'CODE0001', now: $this->now));
        $this->assertError('not_member', fn() => $this->Service->renewInvite(self::TOMASZ, $this->now));
    }

    public function testJoiningAnotherHouseholdNeedsAnExplicitSwitch(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->create(self::TOMASZ, 'Tomasz', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);

        $this->assertError('same_household', fn() => $this->Service->join(self::EWA, 'CODE0001', now: $this->now));
        $this->assertError('already_member', fn() => $this->Service->join(self::EWA, 'CODE0002', now: $this->now));

        $Other = $this->Service->join(self::EWA, 'CODE0002', true, false, $this->now);

        $this->assertSame($Other->id, $this->Store->members[self::EWA]->householdId);
        $this->assertCount(1, $this->Store->members($this->Store->members[self::MICHAEL]->householdId));
    }

    public function testLastAdminMustHandOverBeforeLeaving(): void
    {
        $Household = $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);

        $this->assertError('last_admin', fn() => $this->Service->leave(self::MICHAEL, false, $this->now));
        $this->assertError('last_admin', fn() => $this->Service->setRole(self::MICHAEL, self::MICHAEL, 'member'));

        $this->Service->setRole(self::MICHAEL, self::EWA, 'admin');
        $dissolved = $this->Service->leave(self::MICHAEL, false, $this->now);

        $this->assertFalse($dissolved);
        $this->assertArrayNotHasKey(self::MICHAEL, $this->Store->members);
        $this->assertSame(HouseholdRole::Admin, $this->Store->members[self::EWA]->role);
        $this->assertArrayHasKey($Household->id, $this->Store->households);
    }

    public function testLastAdminCannotSwitchAwayEither(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);
        $this->Service->create(self::TOMASZ, 'Tomasz', $this->now);

        $this->assertError('last_admin', fn() => $this->Service->join(self::MICHAEL, 'CODE0002', true, true, $this->now));
        $this->assertSame(HouseholdRole::Admin, $this->Store->members[self::MICHAEL]->role);
    }

    public function testMemberMayLeaveAndHouseholdStays(): void
    {
        $Household = $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);

        $this->assertFalse($this->Service->leave(self::EWA, false, $this->now));
        $this->assertArrayNotHasKey(self::EWA, $this->Store->members);
        $this->assertSame([], $this->Store->deleted);
        $this->assertArrayHasKey($Household->id, $this->Store->households);
        $this->assertError('not_member', fn() => $this->Service->leave(self::EWA, false, $this->now));
    }

    public function testLastMemberLeavingDissolvesOnlyAfterConfirmation(): void
    {
        $Household = $this->Service->create(self::MICHAEL, 'Familie', $this->now);

        $this->assertError('confirm_dissolve', fn() => $this->Service->leave(self::MICHAEL, false, $this->now));
        $this->assertSame([], $this->Store->deleted);

        $this->assertTrue($this->Service->leave(self::MICHAEL, true, $this->now));
        $this->assertSame([$Household->id], $this->Store->deleted);
        $this->assertSame([], $this->Store->members);
    }

    public function testOnlyAdminsDissolve(): void
    {
        $Household = $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);

        $this->assertError('forbidden', fn() => $this->Service->dissolve(self::EWA, $this->now));
        $this->assertError('not_member', fn() => $this->Service->dissolve(self::TOMASZ, $this->now));

        $this->Service->dissolve(self::MICHAEL, $this->now);

        $this->assertSame([$Household->id], $this->Store->deleted);
        $this->assertNull($this->Store->membershipOf(self::EWA));
    }

    public function testAdminsAppointAndRemoveMembers(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->join(self::EWA, 'CODE0001', now: $this->now);
        $this->Service->join(self::TOMASZ, 'CODE0001', now: $this->now);

        $this->assertError('forbidden', fn() => $this->Service->setRole(self::EWA, self::TOMASZ, 'admin'));
        $this->assertError('forbidden', fn() => $this->Service->removeMember(self::EWA, self::TOMASZ));
        $this->assertError('invalid_request', fn() => $this->Service->setRole(self::MICHAEL, self::EWA, 'owner'));
        $this->assertError('invalid_request', fn() => $this->Service->removeMember(self::MICHAEL, self::MICHAEL));

        $this->Service->setRole(self::MICHAEL, self::EWA, 'admin');
        $this->assertSame(HouseholdRole::Admin, $this->Store->members[self::EWA]->role);

        // eine Administratorin darf auch den ersten Administrator entfernen
        $this->Service->removeMember(self::EWA, self::MICHAEL);
        $this->assertArrayNotHasKey(self::MICHAEL, $this->Store->members);

        $this->Service->setRole(self::EWA, self::TOMASZ, 'admin');
        $this->Service->setRole(self::EWA, self::EWA, 'member');
        $this->assertSame(HouseholdRole::Member, $this->Store->members[self::EWA]->role);
    }

    public function testMembersOfOtherHouseholdsAreNotFound(): void
    {
        $this->Service->create(self::MICHAEL, 'Familie', $this->now);
        $this->Service->create(self::TOMASZ, 'Tomasz', $this->now);

        $this->assertError('member_not_found', fn() => $this->Service->setRole(self::MICHAEL, self::TOMASZ, 'member'));
        $this->assertError('member_not_found', fn() => $this->Service->removeMember(self::MICHAEL, self::TOMASZ));
        $this->assertError('member_not_found', fn() => $this->Service->removeMember(self::MICHAEL, self::EWA));
        $this->assertSame(HouseholdRole::Admin, $this->Store->members[self::TOMASZ]->role);
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertError(string $code, callable $action): void
    {
        try {
            $action();
        } catch (HouseholdException $Exception) {
            $this->assertSame($code, $Exception->getErrorCode(), $Exception->getMessage());

            return;
        }

        $this->fail('Expected household error ' . $code);
    }
}
