<?php

namespace MichaelDanielczok\Piklis\Lists;

use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Sync\ListState;

/**
 * Rolle eines Benutzers an einer Liste, die stärkste zählt:
 *
 * - `owner`: Besitzer einer persönlichen Liste,
 * - `member`: Mitglied des Haushalts, dem die Liste gehört,
 * - `guest`: angenommene Einladung zu dieser Liste (Schritt 11).
 *
 * Ohne Rolle gibt es die Liste für den Benutzer nicht (`no_access`), auch
 * nicht für Haushaltsmitglieder bei persönlichen Listen anderer.
 * Genutzt von `ListRepository` (Datenbank) und dem Testspeicher.
 */
final class ListAccess
{
    /**
     * @param string|null $userHouseholdId Haushalt des Benutzers, null ohne
     */
    public static function role(
        ListState $List,
        string $userId,
        ?string $userHouseholdId,
        bool $isGuest = false
    ): ?ListRole {
        if ($List->ownerUser !== null && $List->ownerUser === $userId) {
            return ListRole::Owner;
        }

        if ($List->householdId !== null && $userHouseholdId !== null && $List->householdId === $userHouseholdId) {
            return ListRole::Member;
        }

        return $isGuest ? ListRole::Guest : null;
    }
}
