<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\SortKey;

use function array_keys;
use function array_values;
use function usort;

use const DATE_ATOM;

/**
 * Eine Liste mit allen Einträgen im Speicher (Zeile von `pk_lists` und ihre
 * Zeilen in `pk_list_entries`). Merkt sich, was sich geändert hat, damit der
 * Speicher nur das schreibt.
 *
 * Die Revision zählt jede wirksame Operation: +1 je Operation, die etwas
 * ändert. Jeder geänderte Eintrag trägt die Revision seiner letzten Änderung.
 */
final class ListState
{
    public bool $isNew = false;

    private bool $dirty = false;

    /** @var array<string, EntryState> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $dirtyEntries = [];

    public function __construct(
        public readonly string $id,
        public string $name,
        public ?string $shopDate,
        public ?string $ownerUser,
        public ?string $householdId,
        public ListStatus $status,
        public ?DateTimeImmutable $completedAt,
        public ?string $completedBy,
        public bool $changedAfterCompletion,
        public int $revision,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $createdBy,
        public DateTimeImmutable $updatedAt,
        public string $updatedBy,
        public ?DateTimeImmutable $deletedAt = null,
        public ?string $deletedBy = null
    ) {
    }

    /**
     * Neue Liste: persönlich (`$householdId` null) oder für einen Haushalt.
     */
    public static function create(
        string $id,
        string $name,
        ?string $shopDate,
        ?string $householdId,
        string $userId,
        DateTimeImmutable $now
    ): self {
        $List = new self(
            $id,
            $name,
            $shopDate,
            $householdId === null ? $userId : null,
            $householdId,
            ListStatus::Active,
            null,
            null,
            false,
            0,
            $now,
            $userId,
            $now,
            $userId
        );

        $List->isNew = true;
        $List->dirty = true;

        return $List;
    }

    /**
     * Beim Laden aus dem Speicher, ohne Änderung zu markieren.
     */
    public function loadEntry(EntryState $Entry): void
    {
        $this->entries[$Entry->id] = $Entry;
    }

    public function addEntry(EntryState $Entry): void
    {
        $this->entries[$Entry->id] = $Entry;
        $this->dirtyEntries[$Entry->id] = true;
    }

    /**
     * Eintrag mit genau dieser ID, auch entfernte und Verweise.
     */
    public function entry(string $entryId): ?EntryState
    {
        return $this->entries[$entryId] ?? null;
    }

    /**
     * Eintrag, dem eine Operation gilt: folgt Verweisen (`mergedInto`).
     */
    public function resolveEntry(string $entryId): ?EntryState
    {
        $Entry = $this->entries[$entryId] ?? null;
        $steps = 0;

        while ($Entry !== null && $Entry->mergedInto !== null && $steps < 10) {
            $Entry = $this->entries[$Entry->mergedInto] ?? null;
            $steps++;
        }

        return $Entry;
    }

    /**
     * Vorhandener, nicht erledigter Eintrag desselben Produkts.
     */
    public function openEntryForProduct(string $productId, ?string $exceptId = null): ?EntryState
    {
        foreach ($this->entries as $Entry) {
            if ($Entry->id !== $exceptId && $Entry->isOpenFor($productId)) {
                return $Entry;
            }
        }

        return null;
    }

    /**
     * @return list<EntryState>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    /**
     * Sichtbare Einträge (nicht entfernt, keine Verweise), nach Sortierschlüssel.
     *
     * @return list<EntryState>
     */
    public function visibleEntries(): array
    {
        $visible = [];

        foreach ($this->entries as $Entry) {
            if ($Entry->removedAt === null && $Entry->mergedInto === null) {
                $visible[] = $Entry;
            }
        }

        usort(
            $visible,
            static fn(EntryState $A, EntryState $B): int => SortKey::compare(
                $A->sortKey,
                $A->id,
                $B->sortKey,
                $B->id
            )
        );

        return $visible;
    }

    /**
     * Nächste Revision; die Liste gilt danach als geändert.
     */
    public function nextRevision(): int
    {
        $this->revision++;
        $this->dirty = true;

        return $this->revision;
    }

    public function markDirty(): void
    {
        $this->dirty = true;
    }

    public function markEntryDirty(EntryState $Entry): void
    {
        $this->dirtyEntries[$Entry->id] = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * @return list<EntryState>
     */
    public function dirtyEntries(): array
    {
        $dirty = [];

        foreach (array_keys($this->dirtyEntries) as $entryId) {
            if (isset($this->entries[$entryId])) {
                $dirty[] = $this->entries[$entryId];
            }
        }

        return $dirty;
    }

    /**
     * Nach dem Speichern: nichts mehr offen.
     */
    public function markSaved(): void
    {
        $this->isNew = false;
        $this->dirty = false;

        foreach ($this->dirtyEntries() as $Entry) {
            $Entry->isNew = false;
        }

        $this->dirtyEntries = [];
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Für die API (camelCase), ohne Einträge.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shopDate' => $this->shopDate,
            'ownership' => $this->householdId === null ? 'personal' : 'household',
            'ownerId' => $this->ownerUser,
            'householdId' => $this->householdId,
            'status' => $this->status->value,
            'completedAt' => $this->completedAt?->format(DATE_ATOM),
            'completedBy' => $this->completedBy,
            'changedAfterCompletion' => $this->changedAfterCompletion,
            'revision' => $this->revision,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'createdBy' => $this->createdBy,
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'updatedBy' => $this->updatedBy,
            'deleted' => $this->deletedAt !== null
        ];
    }
}
