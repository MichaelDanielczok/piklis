<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;

/**
 * Verarbeitet Operationen eines Benutzers: Idempotenz, Liste sperren und
 * laden, Rechte prüfen, anwenden, speichern. Jede Operation in einer eigenen
 * Transaktion, damit eine abgelehnte die anderen nicht aufhält.
 *
 * Reihenfolge der Prüfungen:
 *
 * 1. Schon verarbeitet (ID im Änderungsprotokoll): gespeichertes Ergebnis
 *    mit `duplicate: true`, nichts erneut ausgeführt.
 * 2. `list.create`: Liste neu (bei Haushalt nur als Mitglied); existiert die
 *    ID schon, `unchanged` für denselben Ersteller, sonst `list_exists`.
 * 3. Liste unbekannt `list_not_found`, gelöscht `list_deleted`, ohne Rolle
 *    `no_access` (z. B. Freigabe entzogen), Rolle reicht nicht `forbidden`.
 * 4. `entry.add` mit einer Eintrags-ID aus einer anderen Liste:
 *    `invalid_operation`.
 * 5. Anwenden (`ListOperationApplier`) und mit Protokollzeile speichern.
 *
 * Abgelehnte Operationen werden nicht protokolliert.
 */
final class OperationProcessor
{
    public function __construct(
        private readonly ListStore $Store,
        private readonly ListOperationApplier $Applier = new ListOperationApplier()
    ) {
    }

    public function process(Operation $op, string $userId, ?DateTimeImmutable $now = null): OperationResult
    {
        $now ??= new DateTimeImmutable();

        return $this->Store->transactional(
            fn(): OperationResult => $this->processInTransaction($op, $userId, $now)
        );
    }

    private function processInTransaction(Operation $op, string $userId, DateTimeImmutable $now): OperationResult
    {
        $stored = $this->Store->findOperationResult($op->id);

        if ($stored !== null) {
            return OperationResult::duplicate($stored);
        }

        $List = $this->Store->loadList($op->listId, true);

        if ($op->type === OperationType::ListCreate) {
            return $this->createList($List, $op, $userId, $now);
        }

        if ($List === null) {
            return OperationResult::rejected($op->id, 'list_not_found', 'The list does not exist.');
        }

        if ($List->isDeleted()) {
            return OperationResult::rejected($op->id, 'list_deleted', 'The list has been deleted.');
        }

        $Role = $this->Store->roleOf($List, $userId);

        if ($Role === null) {
            return OperationResult::rejected($op->id, 'no_access', 'No access to this list.');
        }

        if (!ListPermissions::allows($Role, $op->type)) {
            return OperationResult::rejected($op->id, 'forbidden', 'This role may not do that.');
        }

        if ($op->type === OperationType::EntryAdd) {
            $otherListId = $this->Store->listIdOfEntry((string)$op->entryId);

            if ($otherListId !== null && $otherListId !== $List->id) {
                return OperationResult::rejected(
                    $op->id,
                    'invalid_operation',
                    'The entry id belongs to another list.'
                );
            }
        }

        $Result = $this->Applier->apply($List, $op, $userId, $now);

        if ($Result->isRejected()) {
            return $Result;
        }

        $this->Store->save($List, $this->changeRecord($op, $userId, $now, $Result));

        return $Result;
    }

    private function createList(?ListState $Existing, Operation $op, string $userId, DateTimeImmutable $now): OperationResult
    {
        if ($Existing !== null) {
            if ($Existing->createdBy === $userId && !$Existing->isDeleted()) {
                return OperationResult::of($op->id, null, [], ['list' => $Existing->toArray()]);
            }

            return OperationResult::rejected($op->id, 'list_exists', 'A list with this id already exists.');
        }

        $householdId = $op->householdId();

        if ($householdId !== null && !$this->Store->isHouseholdMember($userId, $householdId)) {
            return OperationResult::rejected($op->id, 'no_access', 'Not a member of this household.');
        }

        [$List, $Result] = $this->Applier->create($op, $userId, $now);

        $this->Store->save($List, $this->changeRecord($op, $userId, $now, $Result));

        return $Result;
    }

    private function changeRecord(
        Operation $op,
        string $userId,
        DateTimeImmutable $now,
        OperationResult $Result
    ): ChangeRecord {
        return new ChangeRecord(
            $op->id,
            $op->listId,
            $op->type->value,
            $op->entryId,
            $userId,
            $now,
            $Result->revision(),
            $op->payload,
            $Result->toArray()
        );
    }
}
