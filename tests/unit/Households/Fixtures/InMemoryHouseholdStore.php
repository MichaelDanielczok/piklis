<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Households\Fixtures;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Households\Household;
use MichaelDanielczok\Piklis\Households\HouseholdRole;
use MichaelDanielczok\Piklis\Households\HouseholdStore;
use MichaelDanielczok\Piklis\Households\Membership;

use function array_values;

/**
 * Haushalte im Arbeitsspeicher für Tests ohne Datenbank. `deleted` merkt
 * sich, welche Haushalte samt Listen gelöscht wurden.
 */
final class InMemoryHouseholdStore implements HouseholdStore
{
    /** @var array<string, Household> */
    public array $households = [];

    /** @var array<string, Membership> Benutzer => Mitgliedschaft */
    public array $members = [];

    /** @var list<string> gelöschte Haushalte (mit ihren Listen) */
    public array $deleted = [];

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }

    public function membershipOf(string $userId): ?Membership
    {
        return $this->members[$userId] ?? null;
    }

    public function find(string $householdId, bool $forUpdate = false): ?Household
    {
        return $this->households[$householdId] ?? null;
    }

    public function findByInviteCode(string $code): ?Household
    {
        foreach ($this->households as $Household) {
            if ($Household->inviteCode === $code) {
                return $Household;
            }
        }

        return null;
    }

    public function members(string $householdId): array
    {
        $members = [];

        foreach ($this->members as $Membership) {
            if ($Membership->householdId === $householdId) {
                $members[] = $Membership;
            }
        }

        return array_values($members);
    }

    public function insert(Household $Household): void
    {
        $this->households[$Household->id] = $Household;
    }

    public function updateInvite(Household $Household): void
    {
        $this->households[$Household->id] = $Household;
    }

    public function addMember(Membership $Membership): void
    {
        $this->members[$Membership->userId] = $Membership;
    }

    public function setRole(string $userId, HouseholdRole $role): void
    {
        $this->members[$userId]->role = $role;
    }

    public function removeMember(string $userId): void
    {
        unset($this->members[$userId]);
    }

    public function delete(string $householdId, string $userId, DateTimeImmutable $now): void
    {
        foreach ($this->members($householdId) as $Membership) {
            unset($this->members[$Membership->userId]);
        }

        unset($this->households[$householdId]);
        $this->deleted[] = $householdId;
    }
}
