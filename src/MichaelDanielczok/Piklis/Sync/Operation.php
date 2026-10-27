<?php

namespace MichaelDanielczok\Piklis\Sync;

use DateTimeImmutable;
use Exception;
use MichaelDanielczok\Piklis\Lists\InvalidQuantityException;
use MichaelDanielczok\Piklis\Lists\Quantity;
use MichaelDanielczok\Piklis\Lists\SortKey;

use function array_key_exists;
use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Eine Operation der App, geprüft und in Typen umgewandelt.
 *
 * Grundform (JSON): `{ "id": "<uuid>", "type": "entry.set", "listId": "<uuid>",
 * "entryId": "<uuid>", … }`. Die ID der Operation macht sie idempotent:
 * doppelt gesendet = einmal ausgeführt. Felder je Art siehe
 * docs/architecture.md, „Sync“.
 */
final class Operation
{
    public const NAME_MAX_LENGTH = 100;

    public const NOTE_MAX_LENGTH = 500;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private ?string $name = null;

    private bool $hasName = false;

    private ?string $shopDate = null;

    private bool $hasShopDate = false;

    private ?string $householdId = null;

    private ?string $productId = null;

    private ?string $sortKey = null;

    private ?Quantity $quantity = null;

    private ?Quantity $baseQuantity = null;

    private ?string $note = null;

    private ?string $baseNote = null;

    private ?EntryStatus $status = null;

    private ?Quantity $seen = null;

    private ?string $transferredTo = null;

    /**
     * @param array<string, mixed> $payload die Operation wie gesendet (für das Änderungsprotokoll)
     */
    private function __construct(
        public readonly string $id,
        public readonly OperationType $type,
        public readonly string $listId,
        public readonly ?string $entryId,
        public readonly array $payload
    ) {
    }

    /**
     * @throws InvalidOperationException
     */
    public static function fromArray(mixed $input): self
    {
        if (!is_array($input)) {
            throw new InvalidOperationException('Operation must be an object.');
        }

        /** @var array<string, mixed> $input */
        $id = self::uuid($input, 'id');
        $typeName = $input['type'] ?? null;
        $type = is_string($typeName) ? OperationType::tryFrom($typeName) : null;

        if ($type === null) {
            throw new InvalidOperationException('Unknown operation type.');
        }

        $listId = self::uuid($input, 'listId');
        $entryId = $type->isEntryOperation() ? self::uuid($input, 'entryId') : null;

        $Operation = new self($id, $type, $listId, $entryId, $input);
        $Operation->readPayload($input);

        return $Operation;
    }

    /**
     * ID der Operation aus einer (womöglich ungültigen) Eingabe, für die Antwort.
     */
    public static function idOf(mixed $input): ?string
    {
        if (!is_array($input) || !isset($input['id']) || !is_string($input['id'])) {
            return null;
        }

        return $input['id'];
    }

    public function name(): string
    {
        return (string)$this->name;
    }

    public function hasName(): bool
    {
        return $this->hasName;
    }

    public function shopDate(): ?string
    {
        return $this->shopDate;
    }

    public function hasShopDate(): bool
    {
        return $this->hasShopDate;
    }

    public function householdId(): ?string
    {
        return $this->householdId;
    }

    public function productId(): string
    {
        return (string)$this->productId;
    }

    public function sortKey(): string
    {
        return (string)$this->sortKey;
    }

    /**
     * Menge bei `entry.add`, neue Menge bei `entry.set` (null: nicht geändert).
     */
    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    /**
     * Menge, die die App vor ihrer Änderung kannte (`entry.set`).
     */
    public function baseQuantity(): ?Quantity
    {
        return $this->baseQuantity;
    }

    /**
     * Notiz bei `entry.add`, neue Notiz bei `entry.set` (null: nicht geändert).
     */
    public function note(): ?string
    {
        return $this->note;
    }

    public function baseNote(): ?string
    {
        return $this->baseNote;
    }

    public function status(): EntryStatus
    {
        return $this->status ?? EntryStatus::Open;
    }

    /**
     * Menge, die beim Abhaken zu sehen war (`entry.setStatus` mit `done`).
     */
    public function seen(): ?Quantity
    {
        return $this->seen;
    }

    public function transferredTo(): ?string
    {
        return $this->transferredTo;
    }

    /**
     * Zeitpunkt in der App (nur zur Information; die Reihenfolge bestimmt der
     * Eingang beim Server).
     */
    public function createdAt(): ?DateTimeImmutable
    {
        $value = $this->payload['createdAt'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @throws InvalidOperationException
     */
    private function readPayload(array $input): void
    {
        switch ($this->type) {
            case OperationType::ListCreate:
                $this->name = self::listName($input['name'] ?? null);
                $this->hasName = true;
                $this->shopDate = self::shopDateOf($input['shopDate'] ?? null);
                $this->hasShopDate = true;
                $this->householdId = isset($input['householdId']) ? self::uuid($input, 'householdId') : null;
                break;

            case OperationType::ListUpdate:
                $fields = self::object($input, 'fields');

                if (array_key_exists('name', $fields)) {
                    $this->name = self::listName($fields['name']);
                    $this->hasName = true;
                }

                if (array_key_exists('shopDate', $fields)) {
                    $this->shopDate = self::shopDateOf($fields['shopDate']);
                    $this->hasShopDate = true;
                }

                if (!$this->hasName && !$this->hasShopDate) {
                    throw new InvalidOperationException('list.update needs fields.name or fields.shopDate.');
                }
                break;

            case OperationType::EntryAdd:
                $this->productId = self::uuid($input, 'productId');
                $this->quantity = self::quantityOf($input['quantity'] ?? null);
                $this->note = self::noteOf($input['note'] ?? '');
                $this->sortKey = self::sortKeyOf($input['sortKey'] ?? null);
                break;

            case OperationType::EntrySet:
                $this->readEntrySet($input);
                break;

            case OperationType::EntryMove:
                $this->sortKey = self::sortKeyOf($input['sortKey'] ?? null);
                break;

            case OperationType::EntrySetStatus:
                $status = $input['status'] ?? null;
                $this->status = is_string($status) ? EntryStatus::tryFrom($status) : null;

                if ($this->status === null) {
                    throw new InvalidOperationException('Unknown entry status.');
                }

                if ($this->status === EntryStatus::Done) {
                    $this->seen = self::quantityOf($input['seen'] ?? null);
                }
                break;

            case OperationType::EntryTransfer:
                $target = $input['transferredTo'] ?? null;
                $this->transferredTo = $target === null ? null : self::uuid($input, 'transferredTo');
                break;

            case OperationType::ListComplete:
            case OperationType::ListReopen:
            case OperationType::ListDelete:
            case OperationType::EntryRemove:
            case OperationType::EntryRestore:
                break;
        }
    }

    /**
     * `entry.set`: `{ "set": { "quantity": {…}, "note": "…" }, "base": { … } }`,
     * `base` enthält dieselben Felder mit den Werten vor der Änderung.
     *
     * @param array<string, mixed> $input
     * @throws InvalidOperationException
     */
    private function readEntrySet(array $input): void
    {
        $set = self::object($input, 'set');
        $base = self::object($input, 'base');

        if (array_key_exists('quantity', $set)) {
            if (!array_key_exists('quantity', $base)) {
                throw new InvalidOperationException('entry.set needs base.quantity.');
            }

            $this->quantity = self::quantityOf($set['quantity']);
            $this->baseQuantity = self::quantityOf($base['quantity']);
        }

        if (array_key_exists('note', $set)) {
            if (!array_key_exists('note', $base)) {
                throw new InvalidOperationException('entry.set needs base.note.');
            }

            $this->note = self::noteOf($set['note']);
            $this->baseNote = self::noteOf($base['note']);
        }

        if ($this->quantity === null && $this->note === null) {
            throw new InvalidOperationException('entry.set needs set.quantity or set.note.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @throws InvalidOperationException
     */
    private static function uuid(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidOperationException('Missing ' . $key . '.');
        }

        $value = strtolower($value);

        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new InvalidOperationException('Invalid ' . $key . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws InvalidOperationException
     */
    private static function object(array $input, string $key): array
    {
        $value = $input[$key] ?? null;

        if (!is_array($value)) {
            throw new InvalidOperationException('Missing object ' . $key . '.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @throws InvalidOperationException
     */
    private static function listName(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidOperationException('List name must have 1 to 100 characters.');
        }

        return $name;
    }

    /**
     * @throws InvalidOperationException
     */
    private static function shopDateOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $Date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($Date === false || $Date->format('Y-m-d') !== $value) {
            throw new InvalidOperationException('shopDate must be YYYY-MM-DD or null.');
        }

        return $value;
    }

    /**
     * @throws InvalidOperationException
     */
    private static function quantityOf(mixed $value): Quantity
    {
        try {
            return Quantity::fromArray($value);
        } catch (InvalidQuantityException $Exception) {
            throw new InvalidOperationException('Invalid quantity: ' . $Exception->getErrorCode() . '.');
        }
    }

    /**
     * @throws InvalidOperationException
     */
    private static function noteOf(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidOperationException('Note must be a string.');
        }

        $note = trim($value);

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new InvalidOperationException('Note is longer than 500 characters.');
        }

        return $note;
    }

    /**
     * @throws InvalidOperationException
     */
    private static function sortKeyOf(mixed $value): string
    {
        if (!is_string($value) || !SortKey::isValid($value)) {
            throw new InvalidOperationException('Invalid sortKey.');
        }

        return $value;
    }
}
