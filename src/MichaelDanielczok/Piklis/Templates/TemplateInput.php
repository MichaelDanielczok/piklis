<?php

namespace MichaelDanielczok\Piklis\Templates;

use MichaelDanielczok\Piklis\Lists\InvalidQuantityException;
use MichaelDanielczok\Piklis\Lists\Quantity;
use MichaelDanielczok\Piklis\Support\Uuid;

use function array_is_list;
use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use function trim;

/**
 * Eingaben beim Speichern einer Vorlage prüfen: Name 1–100 Zeichen (wie
 * Listen), höchstens 300 Produkte, jedes höchstens einmal, Menge nach den
 * Mengenregeln, Notiz höchstens 500 Zeichen.
 */
final class TemplateInput
{
    public const NAME_MAX_LENGTH = 100;
    public const NOTE_MAX_LENGTH = 500;
    public const ENTRIES_MAX = 300;

    /**
     * @throws TemplateException
     */
    public static function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw self::invalid('name', 'The name must have 1 to 100 characters.');
        }

        return $name;
    }

    /**
     * Produkte in der gewünschten Reihenfolge.
     *
     * @return list<array{productId: string, quantity: Quantity, note: string}>
     * @throws TemplateException
     */
    public static function entries(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid('entries', 'Entries must be a list.');
        }

        if (count($value) > self::ENTRIES_MAX) {
            throw self::invalid('entries', 'A template holds at most 300 products.');
        }

        $entries = [];
        $seen = [];

        foreach ($value as $item) {
            if (!is_array($item) || !Uuid::isValid($item['productId'] ?? null)) {
                throw self::invalid('entries', 'Every entry needs a productId (UUID).');
            }

            /** @var string $productId */
            $productId = $item['productId'];

            if (isset($seen[$productId])) {
                throw self::invalid('entries', 'Every product may appear only once.');
            }

            $seen[$productId] = true;
            $entries[] = [
                'productId' => $productId,
                'quantity' => self::quantity($item['quantity'] ?? ['amount' => 1]),
                'note' => self::note($item['note'] ?? '')
            ];
        }

        return $entries;
    }

    /**
     * @throws TemplateException
     */
    private static function quantity(mixed $value): Quantity
    {
        try {
            return Quantity::fromArray($value);
        } catch (InvalidQuantityException $Exception) {
            throw self::invalid('entries', 'Invalid quantity: ' . $Exception->getErrorCode() . '.');
        }
    }

    /**
     * @throws TemplateException
     */
    private static function note(mixed $value): string
    {
        if (!is_string($value)) {
            throw self::invalid('entries', 'Note must be a string.');
        }

        $note = trim($value);

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw self::invalid('entries', 'Note is longer than 500 characters.');
        }

        return $note;
    }

    private static function invalid(string $field, string $message): TemplateException
    {
        return new TemplateException('invalid_template', $message, 400, $field);
    }
}
