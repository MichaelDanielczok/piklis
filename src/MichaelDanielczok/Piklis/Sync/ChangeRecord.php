<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;

/**
 * Eine Zeile des Änderungsprotokolls (`pk_list_changes`): jede verarbeitete
 * Operation mit ihrem Ergebnis, auch Konflikte (für die Idempotenz).
 * `revision` ist null, wenn nichts geändert wurde.
 */
final class ChangeRecord
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $listId,
        public readonly string $type,
        public readonly ?string $entryId,
        public readonly string $userId,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $revision,
        public readonly array $payload,
        public readonly array $result
    ) {
    }
}
