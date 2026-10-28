<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\Quantity;

use const DATE_ATOM;

/**
 * Ein Listeneintrag im Speicher (Zeile von `pk_list_entries`).
 *
 * Entfernte Einträge bleiben als Zeile erhalten (`removedAt`), damit eine
 * spätere Änderung daran die Frage „wiederherstellen?“ auslösen kann. Ein
 * Eintrag mit `mergedInto` ist ein Verweis: Er wurde gleichzeitig zu einem
 * schon vorhandenen Eintrag desselben Produkts angelegt; Operationen darauf
 * gelten dem Ziel.
 */
final class EntryState
{
    public bool $isNew = false;

    public function __construct(
        public readonly string $id,
        public readonly string $listId,
        public readonly string $productId,
        public Quantity $quantity,
        public string $note,
        public string $sortKey,
        public EntryStatus $status,
        public ?Quantity $checkedQuantity,
        public ?DateTimeImmutable $statusChangedAt,
        public ?string $statusChangedBy,
        public ?string $transferredTo,
        public ?DateTimeImmutable $removedAt,
        public ?string $removedBy,
        public ?string $mergedInto,
        public bool $changedAfterCompletion,
        public int $revision,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $createdBy,
        public DateTimeImmutable $updatedAt,
        public string $updatedBy
    ) {
    }

    public static function create(
        string $id,
        string $listId,
        string $productId,
        Quantity $quantity,
        string $note,
        string $sortKey,
        string $userId,
        DateTimeImmutable $now
    ): self {
        $Entry = new self(
            $id,
            $listId,
            $productId,
            $quantity,
            $note,
            $sortKey,
            EntryStatus::Open,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            0,
            $now,
            $userId,
            $now,
            $userId
        );

        $Entry->isNew = true;

        return $Entry;
    }

    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    /**
     * Zählt als vorhandener Eintrag des Produkts (keine doppelten Einträge):
     * nicht entfernt, kein Verweis, offen. Erledigte und nicht bekommene
     * Einträge halten fest, was bei diesem Einkauf geschah; das Produkt
     * erneut hinzuzufügen legt einen neuen offenen Eintrag an.
     */
    public function isOpenFor(string $productId): bool
    {
        return $this->productId === $productId
            && $this->removedAt === null
            && $this->mergedInto === null
            && $this->status === EntryStatus::Open;
    }

    /**
     * Für die API (camelCase).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listId' => $this->listId,
            'productId' => $this->productId,
            'quantity' => $this->quantity->toArray(),
            'note' => $this->note,
            'sortKey' => $this->sortKey,
            'status' => $this->status->value,
            'checkedQuantity' => $this->checkedQuantity?->toArray(),
            'statusChangedAt' => $this->statusChangedAt?->format(DATE_ATOM),
            'statusChangedBy' => $this->statusChangedBy,
            'transferredTo' => $this->transferredTo,
            'removed' => $this->removedAt !== null,
            'removedAt' => $this->removedAt?->format(DATE_ATOM),
            'removedBy' => $this->removedBy,
            'mergedInto' => $this->mergedInto,
            'changedAfterCompletion' => $this->changedAfterCompletion,
            'revision' => $this->revision,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'createdBy' => $this->createdBy,
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'updatedBy' => $this->updatedBy
        ];
    }
}
