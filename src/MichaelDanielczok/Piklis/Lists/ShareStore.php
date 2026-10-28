<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;

/**
 * Speicher für Freigaben und Einladungslinks einzelner Listen. In der App
 * `ShareRepository` (Datenbank), in Tests ein Speicher im Arbeitsspeicher.
 * Transaktionen kommen vom `Sync\ListStore` (dieselbe Verbindung).
 */
interface ShareStore
{
    /**
     * `$forUpdate` sperrt die Einladung bis zum Ende der Transaktion (zwei
     * Personen, die denselben Link gleichzeitig öffnen).
     */
    public function findInvite(string $tokenHash, bool $forUpdate = false): ?ListInvite;

    public function insertInvite(ListInvite $Invite): void;

    public function markInviteUsed(ListInvite $Invite): void;

    public function deleteInvite(string $tokenHash): void;

    /**
     * Nicht benutzte, nicht abgelaufene Links einer Liste, älteste zuerst.
     *
     * @return list<ListInvite>
     */
    public function openInvites(string $listId, DateTimeImmutable $now): array;

    /**
     * Teilnehmer einer Liste in Reihenfolge der Annahme.
     *
     * @return list<ListShare>
     */
    public function shares(string $listId): array;

    public function findShare(string $listId, string $userId): ?ListShare;

    public function insertShare(ListShare $Share): void;

    public function deleteShare(string $listId, string $userId): void;
}
