<?php

namespace MichaelDanielczok\Piklis\Households;

use DateTimeImmutable;

/**
 * Speicher für Haushalte und Mitglieder. In der App `HouseholdRepository`
 * (Datenbank), in Tests ein Speicher im Arbeitsspeicher.
 */
interface HouseholdStore
{
    /**
     * Führt `$callback` in einer Transaktion aus.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * Mitgliedschaft des Benutzers, null ohne Haushalt.
     */
    public function membershipOf(string $userId): ?Membership;

    /**
     * `$forUpdate` sperrt den Haushalt bis zum Ende der Transaktion (zwei
     * Administratoren, die gleichzeitig gehen, lassen sonst keinen übrig).
     */
    public function find(string $householdId, bool $forUpdate = false): ?Household;

    public function findByInviteCode(string $code): ?Household;

    /**
     * Mitglieder in Reihenfolge des Beitritts.
     *
     * @return list<Membership>
     */
    public function members(string $householdId): array;

    public function insert(Household $Household): void;

    public function updateInvite(Household $Household): void;

    public function addMember(Membership $Membership): void;

    public function setRole(string $userId, HouseholdRole $role): void;

    public function removeMember(string $userId): void;

    /**
     * Löscht Haushalt und Mitgliedschaften; seine Listen (und später
     * Vorlagen) bekommen einen Grabstein (`deleted_at`).
     */
    public function delete(string $householdId, string $userId, DateTimeImmutable $now): void;
}
