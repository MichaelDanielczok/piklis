<?php

namespace MichaelDanielczok\Piklis\Sync;

/**
 * Speicher für Listen, Einträge und Änderungsprotokoll. In der App
 * `Lists\ListRepository` (Datenbank), in Tests ein Speicher im Arbeitsspeicher.
 */
interface ListStore
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
     * Gespeichertes Ergebnis einer schon verarbeiteten Operation, sonst null.
     *
     * @return array<string, mixed>|null
     */
    public function findOperationResult(string $operationId): ?array;

    /**
     * Liste mit allen Einträgen, auch gelöschte Listen (`deletedAt`).
     * `$forUpdate` sperrt die Liste bis zum Ende der Transaktion.
     */
    public function loadList(string $listId, bool $forUpdate): ?ListState;

    /**
     * Rolle des Benutzers an der Liste, null ohne Zugriff.
     */
    public function roleOf(ListState $list, string $userId): ?ListRole;

    public function isHouseholdMember(string $userId, string $householdId): bool;

    /**
     * Haushalt des Benutzers, null ohne Haushalt.
     */
    public function householdIdOf(string $userId): ?string;

    /**
     * Liste, zu der eine Eintrags-ID gehört (IDs sind global eindeutig).
     */
    public function listIdOfEntry(string $entryId): ?string;

    /**
     * Gibt es das Produkt im Katalog (auch ausgeblendet)?
     */
    public function productExists(string $productId): bool;

    /**
     * Schreibt geänderte Liste, geänderte Einträge und die Protokollzeile.
     */
    public function save(ListState $list, ChangeRecord $change): void;
}
