<?php

namespace MichaelDanielczok\Piklis\Templates;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\SortKey;

use function array_map;
use function usort;

use const DATE_ATOM;

/**
 * Eine Vorlage mit ihren Produkten (Zeile von `pk_templates` und ihre Zeilen
 * in `pk_template_entries`). Gespeichert wird immer ganz: Name und alle
 * Einträge zusammen, die Revision zählt jedes Speichern.
 */
final class TemplateState
{
    /** @var list<TemplateEntry> in Reihenfolge der Sortierschlüssel */
    private array $entries = [];

    public function __construct(
        public readonly string $id,
        public string $name,
        public ?string $ownerUser,
        public ?string $householdId,
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
     * Neue Vorlage: persönlich (`$householdId` null) oder für einen Haushalt.
     */
    public static function create(
        string $id,
        string $name,
        ?string $householdId,
        string $userId,
        DateTimeImmutable $now
    ): self {
        return new self(
            $id,
            $name,
            $householdId === null ? $userId : null,
            $householdId,
            1,
            $now,
            $userId,
            $now,
            $userId
        );
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @param list<TemplateEntry> $entries
     */
    public function setEntries(array $entries): void
    {
        usort(
            $entries,
            static fn(TemplateEntry $A, TemplateEntry $B): int => SortKey::compare(
                $A->sortKey,
                $A->id,
                $B->sortKey,
                $B->id
            )
        );

        $this->entries = $entries;
    }

    /**
     * @return list<TemplateEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Eintrags-ID je Produkt, damit wiederholtes Speichern die IDs behält.
     *
     * @return array<string, string>
     */
    public function entryIdsByProduct(): array
    {
        $ids = [];

        foreach ($this->entries as $Entry) {
            $ids[$Entry->productId] = $Entry->id;
        }

        return $ids;
    }

    /**
     * Kopfdaten ohne Einträge.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ownerUser' => $this->ownerUser,
            'householdId' => $this->householdId,
            'revision' => $this->revision,
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedBy' => $this->updatedBy,
            'updatedAt' => $this->updatedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Mit allen Einträgen in ihrer Reihenfolge.
     *
     * @return array<string, mixed>
     */
    public function toArrayWithEntries(): array
    {
        return [
            ...$this->toArray(),
            'entries' => array_map(static fn(TemplateEntry $Entry): array => $Entry->toArray(), $this->entries)
        ];
    }
}
