<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\InviteToken;
use MichaelDanielczok\Piklis\Lists\ListException;
use MichaelDanielczok\Piklis\Lists\ListOwnership;
use MichaelDanielczok\Piklis\Lists\ListSharing;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Tests\Unit\Lists\Fixtures\InMemoryShareStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\Ops;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;
use function str_repeat;
use function strlen;

/**
 * Einzelne Listen mit Außenstehenden teilen: wer teilen darf, Annehmen mit
 * einmal verwendbarem Link, Rechte des Gastes, Entzug (Konzept „Einzelne
 * Listen mit Außenstehenden teilen“).
 *
 * Michael und Ewa bilden einen Haushalt, Tomasz (Bruder) und Anna haben
 * keinen Zugriff auf dessen Listen.
 */
class ListSharingTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';
    private const ANNA = 'user-anna';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';
    private const OTHER_LIST = '33333333-3333-4333-8333-333333333333';
    private const COPY_ID = '44444444-4444-4444-8444-444444444444';
    private const MILK_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const BREAD_ENTRY = 'bbbbbbbb-0000-4000-8000-000000000002';

    private InMemoryListStore $Store;

    private InMemoryShareStore $Shares;

    private OperationProcessor $Processor;

    private ListSharing $Sharing;

    private DateTimeImmutable $now;

    private int $tokens = 0;

    protected function setUp(): void
    {
        $this->Store = new InMemoryListStore();
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL, self::EWA];
        $this->Shares = new InMemoryShareStore($this->Store);
        $this->Processor = new OperationProcessor($this->Store);
        $this->now = new DateTimeImmutable('2026-10-28 10:00:00');
        $this->Sharing = new ListSharing(
            $this->Store,
            $this->Shares,
            fn(): string => str_repeat((string)++$this->tokens, 43)
        );
    }

    public function testTokenIsUrlSafeAndOnlyItsHashIsStored(): void
    {
        $token = InviteToken::generate();

        $this->assertSame(43, strlen($token));
        $this->assertTrue(InviteToken::isWellFormed($token));
        $this->assertFalse(InviteToken::isWellFormed($token . '='));
        $this->assertFalse(InviteToken::isWellFormed(null));

        $this->householdList();
        $created = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now);

        $this->assertSame([InviteToken::hash($created['token'])], array_keys($this->Shares->invites));
        $this->assertNotSame($created['token'], $created['invite']->tokenHash);
        $this->assertEquals($this->now->modify('+7 days'), $created['invite']->expiresAt);
    }

    public function testOwnerAndMembersMayShareButGuestsAndOthersMayNot(): void
    {
        $this->personalListOfMichael();
        $this->assertSame(self::MICHAEL, $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL)['invite']->createdBy);
        // Ewa ist im Haushalt, sieht Michaels persönliche Liste aber nicht
        $this->assertError('no_access', fn() => $this->Sharing->createInvite(Ops::LIST_ID, self::EWA));

        $this->send(self::EWA, Ops::createList(self::OTHER_LIST, self::HOUSEHOLD));
        $this->assertSame(self::EWA, $this->Sharing->createInvite(self::OTHER_LIST, self::EWA)['invite']->createdBy);
        $this->assertSame(self::MICHAEL, $this->Sharing->createInvite(self::OTHER_LIST, self::MICHAEL)['invite']->createdBy);
        $this->assertError('no_access', fn() => $this->Sharing->createInvite(self::OTHER_LIST, self::TOMASZ));
        $this->assertError('list_not_found', fn() => $this->Sharing->createInvite(self::COPY_ID, self::MICHAEL));
    }

    public function testGuestJoinsTheHouseholdListAndShopsAlong(): void
    {
        $this->householdList();
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now)['token'];

        $seen = $this->Sharing->inspect($token, self::TOMASZ, $this->now);
        $this->assertNull($seen['role']);
        $this->assertSame('Lidl', $seen['list']->name);
        $this->assertSame(self::MICHAEL, $seen['invite']->createdBy);

        $accepted = $this->Sharing->accept($token, self::TOMASZ, $this->now);

        $this->assertSame(ListSharing::STATUS_ACCEPTED, $accepted['status']);
        $this->assertSame(ListRole::Guest, $accepted['role']);
        $this->assertSame(ListRole::Guest, $this->role(self::TOMASZ));
        $this->assertSame(self::TOMASZ, $this->Shares->invites[InviteToken::hash($token)]->usedBy);

        // mitkaufen: hinzufügen, Menge, Notiz, sortieren, abhaken, entfernen
        $operations = [
            Ops::add(self::BREAD_ENTRY, Ops::BREAD),
            Ops::setQuantity(self::MILK_ENTRY, [3], [2]),
            Ops::setNote(self::MILK_ENTRY, 'laktosefrei', ''),
            Ops::move(self::BREAD_ENTRY, 'a'),
            Ops::check(self::MILK_ENTRY, 3),
            Ops::entry('entry.remove', self::BREAD_ENTRY)
        ];

        foreach ($operations as $Operation) {
            $this->assertSame(
                OperationResult::APPLIED,
                $this->send(self::TOMASZ, $Operation)->status(),
                $Operation->type->value
            );
        }

        // Michael sieht die Änderungen in derselben Liste
        $milk = $this->Store->lists[Ops::LIST_ID]->entry(self::MILK_ENTRY);
        $this->assertSame('laktosefrei', $milk?->note);
        $this->assertSame(self::TOMASZ, $milk->statusChangedBy);
    }

    public function testGuestMayNotShareRenameDeleteOrChangeWhereTheListBelongs(): void
    {
        $this->householdList();
        $this->acceptAs(self::TOMASZ);

        $this->assertError('forbidden', fn() => $this->Sharing->createInvite(Ops::LIST_ID, self::TOMASZ));
        $this->assertError('forbidden', fn() => $this->Sharing->participants(Ops::LIST_ID, self::TOMASZ));
        $this->assertError('forbidden', fn() => $this->Sharing->removeShare(Ops::LIST_ID, self::ANNA, self::TOMASZ));

        $this->assertSame('forbidden', $this->send(self::TOMASZ, Ops::make('list.delete'))->get('error'));
        $this->assertSame(
            'forbidden',
            $this->send(self::TOMASZ, Ops::make('list.update', ['fields' => ['name' => 'Meins']]))->get('error')
        );

        $Ownership = new ListOwnership($this->Store);
        $this->assertError('forbidden', fn() => $Ownership->copy(Ops::LIST_ID, self::COPY_ID, 'personal', self::TOMASZ));
        $this->assertFalse($this->Store->lists[Ops::LIST_ID]->isDeleted());
        $this->assertSame('Lidl', $this->Store->lists[Ops::LIST_ID]->name);
    }

    public function testLinkWorksOnlyOnce(): void
    {
        $this->householdList();
        $token = $this->acceptAs(self::TOMASZ);

        $this->assertInvalid('used', fn() => $this->Sharing->accept($token, self::ANNA, $this->now));
        $this->assertInvalid('used', fn() => $this->Sharing->inspect($token, self::ANNA, $this->now));
        $this->assertNull($this->role(self::ANNA));

        // Tomasz selbst kommt über den alten Link einfach zur Liste
        $again = $this->Sharing->accept($token, self::TOMASZ, $this->now);
        $this->assertSame(ListSharing::STATUS_ALREADY, $again['status']);
        $this->assertCount(1, $this->Shares->shares);
    }

    public function testExpiredUnknownAndWithdrawnLinksAreInvalid(): void
    {
        $this->householdList();
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now)['token'];

        $this->assertInvalid('expired', fn() => $this->Sharing->accept($token, self::TOMASZ, $this->now->modify('+7 days')));
        $this->assertInvalid('unknown', fn() => $this->Sharing->accept(str_repeat('x', 43), self::TOMASZ));
        $this->assertInvalid('unknown', fn() => $this->Sharing->accept('kein-token', self::TOMASZ));

        // knapp vor Ablauf geht es noch; ein zurückgezogener Link nicht mehr
        $second = $this->Sharing->createInvite(Ops::LIST_ID, self::EWA, $this->now)['token'];
        $this->Sharing->withdrawInvite(Ops::LIST_ID, InviteToken::hash($second), self::MICHAEL);
        $this->assertInvalid('unknown', fn() => $this->Sharing->accept($second, self::TOMASZ));

        $accepted = $this->Sharing->accept($token, self::TOMASZ, $this->now->modify('+7 days -1 minute'));
        $this->assertSame(ListSharing::STATUS_ACCEPTED, $accepted['status']);
    }

    public function testMemberOpeningTheLinkDoesNotUseItUp(): void
    {
        $this->householdList();
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now)['token'];

        $this->assertSame(ListRole::Member, $this->Sharing->inspect($token, self::EWA, $this->now)['role']);
        $result = $this->Sharing->accept($token, self::EWA, $this->now);

        $this->assertSame(ListSharing::STATUS_ALREADY, $result['status']);
        $this->assertSame(ListRole::Member, $result['role']);
        $this->assertCount(0, $this->Shares->shares);
        $this->assertSame(ListSharing::STATUS_ACCEPTED, $this->Sharing->accept($token, self::TOMASZ, $this->now)['status']);
    }

    public function testLinkOfSomebodyWhoLeftTheHouseholdNoLongerWorks(): void
    {
        $this->householdList();
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::EWA, $this->now)['token'];

        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL];

        $this->assertInvalid('revoked', fn() => $this->Sharing->accept($token, self::TOMASZ, $this->now));
    }

    public function testDeletedListInvalidatesTheLink(): void
    {
        $this->householdList();
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now)['token'];
        $this->send(self::EWA, Ops::make('list.delete'));

        $this->assertInvalid('list_deleted', fn() => $this->Sharing->accept($token, self::TOMASZ, $this->now));
        $this->assertError('list_deleted', fn() => $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL));
    }

    public function testMembersSeeParticipantsAndOpenLinks(): void
    {
        $this->householdList();
        $this->acceptAs(self::TOMASZ);
        $open = $this->Sharing->createInvite(Ops::LIST_ID, self::EWA, $this->now);
        $this->Sharing->createInvite(Ops::LIST_ID, self::EWA, $this->now->modify('-8 days'));

        $participants = $this->Sharing->participants(Ops::LIST_ID, self::EWA, $this->now);

        $this->assertCount(1, $participants['shares']);
        $this->assertSame(self::TOMASZ, $participants['shares'][0]->userId);
        $this->assertSame(self::MICHAEL, $participants['shares'][0]->invitedBy);
        // nur der offene Link; der benutzte und der abgelaufene nicht
        $this->assertCount(1, $participants['invites']);
        $this->assertSame($open['invite']->tokenHash, $participants['invites'][0]->tokenHash);
    }

    public function testRevokingRemovesTheListAndRejectsLateChanges(): void
    {
        $this->householdList();
        $this->acceptAs(self::TOMASZ);

        $this->Sharing->removeShare(Ops::LIST_ID, self::TOMASZ, self::EWA);

        $this->assertNull($this->role(self::TOMASZ));
        $this->assertSame('no_access', $this->send(self::TOMASZ, Ops::check(self::MILK_ENTRY, 2))->get('error'));
        $this->assertError('share_not_found', fn() => $this->Sharing->removeShare(Ops::LIST_ID, self::TOMASZ, self::EWA));
    }

    public function testGuestMayLeaveTheList(): void
    {
        $this->householdList();
        $this->acceptAs(self::TOMASZ);
        $this->acceptAs(self::ANNA);

        $this->Sharing->removeShare(Ops::LIST_ID, self::TOMASZ, self::TOMASZ);

        $this->assertNull($this->role(self::TOMASZ));
        $this->assertSame(ListRole::Guest, $this->role(self::ANNA));
        $this->assertSame(1, count($this->Shares->shares));
    }

    public function testShareGivesAccessToThisListOnly(): void
    {
        $this->householdList();
        $this->send(self::EWA, Ops::createList(self::OTHER_LIST, self::HOUSEHOLD));
        $this->acceptAs(self::TOMASZ);

        $this->assertSame(ListRole::Guest, $this->role(self::TOMASZ));
        $this->assertNull($this->Store->roleOf($this->Store->lists[self::OTHER_LIST], self::TOMASZ));
        $this->assertSame(
            'no_access',
            $this->send(self::TOMASZ, Ops::make('entry.add', [
                'entryId' => self::BREAD_ENTRY,
                'productId' => Ops::BREAD,
                'quantity' => ['amount' => 1, 'unit' => null],
                'note' => '',
                'sortKey' => 'i'
            ], self::OTHER_LIST))->get('error')
        );
    }

    public function testGuestWhoJoinsTheHouseholdBecomesMember(): void
    {
        $this->householdList();
        $this->acceptAs(self::TOMASZ);

        $this->Store->householdMembers[self::HOUSEHOLD][] = self::TOMASZ;

        $this->assertSame(ListRole::Member, $this->role(self::TOMASZ));
    }

    private function householdList(): void
    {
        $this->send(self::MICHAEL, Ops::createList(Ops::LIST_ID, self::HOUSEHOLD));
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK, 2));
    }

    private function personalListOfMichael(): void
    {
        $this->send(self::MICHAEL, Ops::createList());
        $this->send(self::MICHAEL, Ops::add(self::MILK_ENTRY, Ops::MILK, 2));
    }

    /**
     * Michael teilt die Liste, `$userId` nimmt an. Gibt das Token zurück.
     */
    private function acceptAs(string $userId): string
    {
        $token = $this->Sharing->createInvite(Ops::LIST_ID, self::MICHAEL, $this->now)['token'];
        $this->Sharing->accept($token, $userId, $this->now);

        return $token;
    }

    private function role(string $userId): ?ListRole
    {
        return $this->Store->roleOf($this->Store->lists[Ops::LIST_ID], $userId);
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

    /**
     * @param callable(): mixed $action
     */
    private function assertInvalid(string $reason, callable $action): void
    {
        try {
            $action();
        } catch (ListException $Exception) {
            $this->assertSame('invite_invalid', $Exception->getErrorCode());
            $this->assertSame($reason, $Exception->getReason());
            $this->assertSame($reason === 'unknown' ? 404 : 410, $Exception->getHttpStatus());

            return;
        }

        $this->fail('Expected invalid invite ' . $reason);
    }
}
