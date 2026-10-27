<?php

namespace MichaelDanielczok\Piklis\Sync;

use function in_array;

/**
 * Welche Rolle welche Operation ausführen darf.
 *
 * Besitzer und Haushaltsmitglieder dürfen alles. Außenstehende Teilnehmer
 * (Einladung zu einer Liste) arbeiten am Einkauf mit: Einträge hinzufügen,
 * ändern, sortieren, abhaken, entfernen und den Einkauf abschließen oder
 * wieder öffnen. Umbenennen, Datum und Löschen der Liste nicht; Teilen und
 * Zugehörigkeit laufen nicht über Operationen (REST, nur owner/member).
 *
 * `list.create` hat noch keine Rolle; dafür prüft der Prozessor die
 * Haushaltsmitgliedschaft.
 */
final class ListPermissions
{
    private const GUEST_LIST_OPERATIONS = [
        OperationType::ListComplete,
        OperationType::ListReopen
    ];

    public static function allows(ListRole $role, OperationType $type): bool
    {
        if ($type === OperationType::ListCreate) {
            return false;
        }

        if ($role === ListRole::Owner || $role === ListRole::Member) {
            return true;
        }

        if ($type->isEntryOperation()) {
            return true;
        }

        return in_array($type, self::GUEST_LIST_OPERATIONS, true);
    }

    /**
     * Teilen, Freigaben verwalten, verschieben/kopieren in den Haushalt
     * (REST-Aktionen der Schritte 9 und 11).
     */
    public static function canManageSharing(ListRole $role): bool
    {
        return $role === ListRole::Owner || $role === ListRole::Member;
    }
}
