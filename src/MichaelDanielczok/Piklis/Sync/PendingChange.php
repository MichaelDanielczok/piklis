<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;

/**
 * Was eine Operation an einer Liste ändert: vergibt beim ersten Ändern genau
 * eine neue Revision, stempelt geänderte Einträge damit und markiert
 * Änderungen an abgeschlossenen Listen.
 */
final class PendingChange
{
    private ?int $revision = null;

    private bool $afterCompletion = false;

    public function __construct(
        private readonly ListState $List,
        public readonly string $userId,
        public readonly DateTimeImmutable $now
    ) {
    }

    public function touchEntry(EntryState $Entry, bool $markAfterCompletion = true): void
    {
        $revision = $this->revision();

        $Entry->revision = $revision;
        $Entry->updatedAt = $this->now;
        $Entry->updatedBy = $this->userId;

        if ($markAfterCompletion && $this->List->status === ListStatus::Completed) {
            $Entry->changedAfterCompletion = true;
            $this->List->changedAfterCompletion = true;
            $this->afterCompletion = true;
        }

        $this->List->markEntryDirty($Entry);
    }

    public function touchList(bool $markAfterCompletion = true): void
    {
        $this->revision();

        $this->List->updatedAt = $this->now;
        $this->List->updatedBy = $this->userId;

        if ($markAfterCompletion && $this->List->status === ListStatus::Completed) {
            $this->List->changedAfterCompletion = true;
            $this->afterCompletion = true;
        }
    }

    /**
     * @param list<array<string, mixed>> $conflicts
     * @param array<string, mixed> $extra
     */
    public function result(Operation $op, array $conflicts = [], array $extra = []): OperationResult
    {
        if ($this->afterCompletion) {
            $extra['afterCompletion'] = true;
        }

        return OperationResult::of($op->id, $this->revision, $conflicts, $extra);
    }

    private function revision(): int
    {
        if ($this->revision === null) {
            $this->revision = $this->List->nextRevision();
            $this->List->updatedAt = $this->now;
            $this->List->updatedBy = $this->userId;
        }

        return $this->revision;
    }
}
