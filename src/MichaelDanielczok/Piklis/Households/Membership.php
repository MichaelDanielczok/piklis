<?php

namespace MichaelDanielczok\Piklis\Households;

use DateTimeImmutable;

/**
 * Mitgliedschaft eines Benutzers (Zeile von `pk_household_members`);
 * höchstens eine je Benutzer.
 */
final class Membership
{
    public function __construct(
        public readonly string $userId,
        public readonly string $householdId,
        public HouseholdRole $role,
        public readonly DateTimeImmutable $joinedAt
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === HouseholdRole::Admin;
    }
}
