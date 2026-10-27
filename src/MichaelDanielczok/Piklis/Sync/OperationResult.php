<?php

namespace MichaelDanielczok\Piklis\Sync;

use function array_values;
use function is_array;
use function is_int;

/**
 * Antwort auf eine Operation, eine je Operation in derselben Reihenfolge.
 *
 * - `applied`: ausgeführt, `revision` ist die neue Revision der Liste.
 * - `unchanged`: nichts zu tun (Wert schon so), keine neue Revision.
 * - `conflict`: mindestens ein Teil nicht ausgeführt, siehe `conflicts`;
 *   andere Teile können ausgeführt sein (dann mit `revision`).
 * - `rejected`: nicht ausgeführt, `error` sagt warum (`no_access`,
 *   `list_deleted`, …). Wird nicht gespeichert.
 *
 * Dazu je nach Fall `entry`, `list` (Stand nach der Operation),
 * `mergedInto`, `reopened`, `afterCompletion` und bei Wiederholung
 * `duplicate: true`.
 */
final class OperationResult
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param list<array<string, mixed>> $conflicts
     * @param array<string, mixed> $extra z. B. entry, list, mergedInto, reopened, afterCompletion
     */
    public static function of(string $operationId, ?int $revision, array $conflicts = [], array $extra = []): self
    {
        if ($conflicts !== []) {
            $status = self::CONFLICT;
        } elseif ($revision !== null) {
            $status = self::APPLIED;
        } else {
            $status = self::UNCHANGED;
        }

        $data = [
            'id' => $operationId,
            'status' => $status,
            'revision' => $revision
        ];

        if ($conflicts !== []) {
            $data['conflicts'] = $conflicts;
        }

        return new self([...$data, ...$extra]);
    }

    public static function rejected(string $operationId, string $error, string $message): self
    {
        return new self([
            'id' => $operationId,
            'status' => self::REJECTED,
            'error' => $error,
            'message' => $message
        ]);
    }

    /**
     * Gespeichertes Ergebnis einer schon ausgeführten Operation.
     *
     * @param array<string, mixed> $stored
     */
    public static function duplicate(array $stored): self
    {
        return new self([...$stored, 'duplicate' => true]);
    }

    public function status(): string
    {
        return (string)$this->data['status'];
    }

    public function revision(): ?int
    {
        $revision = $this->data['revision'] ?? null;

        return is_int($revision) ? $revision : null;
    }

    public function isRejected(): bool
    {
        return $this->status() === self::REJECTED;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conflicts(): array
    {
        $conflicts = $this->data['conflicts'] ?? [];

        return is_array($conflicts) ? array_values($conflicts) : [];
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
