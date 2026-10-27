<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;

/**
 * Wendet eine Operation auf eine Liste im Speicher an; ohne Datenbank, ohne
 * Rechteprüfung (die macht `OperationProcessor`).
 *
 * Regeln (docs/architecture.md, „Sync“):
 *
 * - Zusammenführen je Feld. Menge (Zahl und Einheit zusammen) und Notiz
 *   tragen den Wert, den die App vor ihrer Änderung kannte (`base`). Ist der
 *   Wert beim Server inzwischen ein anderer und nicht schon der neue:
 *   Konflikt, nichts überschrieben.
 * - Sortierschlüssel, Zustand, Name und Datum der Liste: letzte Änderung
 *   (Eingang beim Server) gewinnt.
 * - Alter Haken: Abhaken merkt sich die gesehene Menge. Wird inzwischen mehr
 *   (oder eine andere Einheit) gebraucht: Konflikt `checkedLess`.
 * - Erledigter Eintrag, dessen Menge über die abgehakte steigt: wieder offen
 *   (`reopened`).
 * - Änderung an einem entfernten Eintrag: Konflikt `removed`.
 * - Produkt schon offen auf der Liste: kein zweiter Eintrag; der neue wird
 *   Verweis auf den vorhandenen (`mergedInto`), abweichende Menge oder Notiz
 *   sind Konflikte wie bei gleichzeitiger Änderung.
 * - Abgeschlossene Liste: Änderungen werden übernommen und als „nach
 *   Abschluss geändert“ markiert.
 */
final class ListOperationApplier
{
    /**
     * `list.create`: neue Liste mit Revision 1.
     *
     * @return array{ListState, OperationResult}
     */
    public function create(Operation $op, string $userId, DateTimeImmutable $now): array
    {
        $List = ListState::create(
            $op->listId,
            $op->name(),
            $op->shopDate(),
            $op->householdId(),
            $userId,
            $now
        );

        $revision = $List->nextRevision();

        return [
            $List,
            OperationResult::of($op->id, $revision, [], ['list' => $List->toArray()])
        ];
    }

    public function apply(ListState $List, Operation $op, string $userId, DateTimeImmutable $now): OperationResult
    {
        $Change = new PendingChange($List, $userId, $now);

        return match ($op->type) {
            OperationType::ListCreate => OperationResult::rejected(
                $op->id,
                'list_exists',
                'The list already exists.'
            ),
            OperationType::ListUpdate => $this->updateList($List, $op, $Change),
            OperationType::ListComplete => $this->completeList($List, $op, $Change),
            OperationType::ListReopen => $this->reopenList($List, $op, $Change),
            OperationType::ListDelete => $this->deleteList($List, $op, $Change),
            OperationType::EntryAdd => $this->addEntry($List, $op, $Change),
            default => $this->changeEntry($List, $op, $Change)
        };
    }

    private function updateList(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        if ($op->hasName() && $op->name() !== $List->name) {
            $List->name = $op->name();
            $Change->touchList();
        }

        if ($op->hasShopDate() && $op->shopDate() !== $List->shopDate) {
            $List->shopDate = $op->shopDate();
            $Change->touchList();
        }

        return $Change->result($op, [], ['list' => $List->toArray()]);
    }

    private function completeList(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        if ($List->status === ListStatus::Completed) {
            return $Change->result($op, [], ['list' => $List->toArray()]);
        }

        $Change->touchList();

        $List->status = ListStatus::Completed;
        $List->completedAt = $Change->now;
        $List->completedBy = $Change->userId;
        $List->changedAfterCompletion = false;

        return $Change->result($op, [], ['list' => $List->toArray()]);
    }

    /**
     * Wieder öffnen löscht die Markierung „nach Abschluss geändert“ an Liste
     * und Einträgen; sie gilt nur für den abgeschlossenen Stand.
     */
    private function reopenList(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        if ($List->status === ListStatus::Active) {
            return $Change->result($op, [], ['list' => $List->toArray()]);
        }

        $Change->touchList();

        $List->status = ListStatus::Active;
        $List->completedAt = null;
        $List->completedBy = null;
        $List->changedAfterCompletion = false;

        foreach ($List->entries() as $Entry) {
            if ($Entry->changedAfterCompletion) {
                $Entry->changedAfterCompletion = false;
                $Change->touchEntry($Entry, false);
            }
        }

        return $Change->result($op, [], ['list' => $List->toArray()]);
    }

    private function deleteList(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        if ($List->isDeleted()) {
            return $Change->result($op);
        }

        $Change->touchList(false);

        $List->deletedAt = $Change->now;
        $List->deletedBy = $Change->userId;

        return $Change->result($op, [], ['list' => $List->toArray()]);
    }

    private function addEntry(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        $entryId = (string)$op->entryId;
        $quantity = $op->quantity();

        if ($quantity === null) {
            return OperationResult::rejected($op->id, 'invalid_operation', 'entry.add needs a quantity.');
        }

        if ($List->entry($entryId) !== null) {
            $Existing = $List->resolveEntry($entryId);

            return $Change->result($op, [], ['entry' => $Existing?->toArray()]);
        }

        $Entry = EntryState::create(
            $entryId,
            $List->id,
            $op->productId(),
            $quantity,
            (string)$op->note(),
            $op->sortKey(),
            $Change->userId,
            $Change->now
        );

        $Duplicate = $List->openEntryForProduct($op->productId());
        $List->addEntry($Entry);

        if ($Duplicate === null) {
            $Change->touchEntry($Entry);

            return $Change->result($op, [], ['entry' => $Entry->toArray()]);
        }

        // Gleichzeitig hinzugefügt: Verweis statt zweitem Eintrag
        $Entry->mergedInto = $Duplicate->id;
        $Entry->removedAt = $Change->now;
        $Entry->removedBy = $Change->userId;
        $Change->touchEntry($Entry);

        $conflicts = [];

        if (!$Duplicate->quantity->equals($quantity)) {
            $conflicts[] = Conflict::quantity($quantity, $Duplicate->quantity);
        }

        $note = (string)$op->note();

        if ($note !== '' && $note !== $Duplicate->note) {
            if ($Duplicate->note === '') {
                $Duplicate->note = $note;
                $Change->touchEntry($Duplicate);
            } else {
                $conflicts[] = Conflict::note($note, $Duplicate->note);
            }
        }

        return $Change->result($op, $conflicts, [
            'entry' => $Duplicate->toArray(),
            'mergedInto' => $Duplicate->id
        ]);
    }

    /**
     * Alle Eintrags-Operationen außer `entry.add`.
     */
    private function changeEntry(ListState $List, Operation $op, PendingChange $Change): OperationResult
    {
        $Entry = $List->resolveEntry((string)$op->entryId);

        if ($Entry === null) {
            return OperationResult::rejected($op->id, 'entry_not_found', 'The entry does not exist in this list.');
        }

        $extra = [];

        if ($Entry->id !== $op->entryId) {
            $extra['mergedInto'] = $Entry->id;
        }

        if ($op->type === OperationType::EntryRemove) {
            return $this->removeEntry($Entry, $op, $Change, $extra);
        }

        if ($op->type === OperationType::EntryRestore) {
            return $this->restoreEntry($List, $Entry, $op, $Change, $extra);
        }

        if ($Entry->isRemoved()) {
            return $Change->result(
                $op,
                [Conflict::removed($Entry->removedAt, $Entry->removedBy)],
                ['entry' => $Entry->toArray(), ...$extra]
            );
        }

        return match ($op->type) {
            OperationType::EntrySet => $this->setEntry($Entry, $op, $Change, $extra),
            OperationType::EntryMove => $this->moveEntry($Entry, $op, $Change, $extra),
            OperationType::EntrySetStatus => $this->setStatus($Entry, $op, $Change, $extra),
            OperationType::EntryTransfer => $this->transferEntry($Entry, $op, $Change, $extra),
            default => OperationResult::rejected($op->id, 'invalid_operation', 'Unsupported operation.')
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function setEntry(EntryState $Entry, Operation $op, PendingChange $Change, array $extra): OperationResult
    {
        $conflicts = [];
        $quantity = $op->quantity();
        $baseQuantity = $op->baseQuantity();
        $note = $op->note();
        $baseNote = $op->baseNote();

        if ($quantity !== null && $baseQuantity !== null && !$Entry->quantity->equals($quantity)) {
            if ($Entry->quantity->equals($baseQuantity)) {
                $Entry->quantity = $quantity;
                $Change->touchEntry($Entry);

                if (
                    $Entry->status === EntryStatus::Done
                    && $Entry->checkedQuantity !== null
                    && $quantity->exceeds($Entry->checkedQuantity)
                ) {
                    $Entry->status = EntryStatus::Open;
                    $Entry->statusChangedAt = $Change->now;
                    $Entry->statusChangedBy = $Change->userId;
                    $extra['reopened'] = true;
                }
            } else {
                $conflicts[] = Conflict::quantity($quantity, $Entry->quantity);
            }
        }

        if ($note !== null && $baseNote !== null && $Entry->note !== $note) {
            if ($Entry->note === $baseNote) {
                $Entry->note = $note;
                $Change->touchEntry($Entry);
            } else {
                $conflicts[] = Conflict::note($note, $Entry->note);
            }
        }

        return $Change->result($op, $conflicts, ['entry' => $Entry->toArray(), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function moveEntry(EntryState $Entry, Operation $op, PendingChange $Change, array $extra): OperationResult
    {
        if ($Entry->sortKey !== $op->sortKey()) {
            $Entry->sortKey = $op->sortKey();
            $Change->touchEntry($Entry);
        }

        return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function setStatus(EntryState $Entry, Operation $op, PendingChange $Change, array $extra): OperationResult
    {
        $status = $op->status();

        if ($Entry->status === $status) {
            return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
        }

        if ($status === EntryStatus::Done) {
            $seen = $op->seen() ?? $Entry->quantity;

            if ($Entry->quantity->exceeds($seen)) {
                return $Change->result(
                    $op,
                    [Conflict::checkedLess($seen, $Entry->quantity)],
                    ['entry' => $Entry->toArray(), ...$extra]
                );
            }

            $Entry->checkedQuantity = $seen;
        } else {
            $Entry->checkedQuantity = null;
        }

        $Entry->status = $status;
        $Entry->statusChangedAt = $Change->now;
        $Entry->statusChangedBy = $Change->userId;
        $Change->touchEntry($Entry);

        return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function transferEntry(
        EntryState $Entry,
        Operation $op,
        PendingChange $Change,
        array $extra
    ): OperationResult {
        if ($Entry->transferredTo !== $op->transferredTo()) {
            $Entry->transferredTo = $op->transferredTo();
            $Change->touchEntry($Entry);
        }

        return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function removeEntry(EntryState $Entry, Operation $op, PendingChange $Change, array $extra): OperationResult
    {
        if (!$Entry->isRemoved()) {
            $Entry->removedAt = $Change->now;
            $Entry->removedBy = $Change->userId;
            $Change->touchEntry($Entry);
        }

        return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
    }

    /**
     * Steht das Produkt inzwischen wieder offen auf der Liste, wird der alte
     * Eintrag Verweis auf den neuen, statt doppelt aufzutauchen.
     *
     * @param array<string, mixed> $extra
     */
    private function restoreEntry(
        ListState $List,
        EntryState $Entry,
        Operation $op,
        PendingChange $Change,
        array $extra
    ): OperationResult {
        if (!$Entry->isRemoved()) {
            return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
        }

        $Other = $List->openEntryForProduct($Entry->productId, $Entry->id);

        if ($Other !== null) {
            $Entry->mergedInto = $Other->id;
            $Change->touchEntry($Entry);

            return $Change->result($op, [], ['entry' => $Other->toArray(), 'mergedInto' => $Other->id]);
        }

        $Entry->removedAt = null;
        $Entry->removedBy = null;
        $Change->touchEntry($Entry);

        return $Change->result($op, [], ['entry' => $Entry->toArray(), ...$extra]);
    }
}
