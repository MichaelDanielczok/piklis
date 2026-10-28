<?php

namespace MichaelDanielczok\Piklis\Sync;

use MichaelDanielczok\Piklis\Lists\Quantity;

use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function rtrim;
use function trim;

/**
 * Zusammenführen beim Übernehmen in eine andere Liste, wenn das Produkt dort
 * schon offen steht (Piklis Entscheidungen, „Übernehmen in andere Liste“):
 *
 * - gleiche Einheit (auch beide ohne): Mengen addieren, höchstens 9999;
 * - andere Einheit: nach der Antwort der App (`UnitMismatch`) behalten,
 *   ersetzen oder die übernommene Menge in die Notiz schreiben;
 * - Notizen: leere Notiz wird gefüllt, eine andere angehängt („ · “), eine
 *   schon enthaltene nicht doppelt; höchstens 500 Zeichen.
 *
 * Ohne Datenbank; dieselben Fälle prüft `utils/takeOver.js`
 * (`tests/fixtures/take-over.json`).
 */
final class TakeOverMerge
{
    public const SUMMED = 'summed';
    public const KEPT = 'kept';
    public const REPLACED = 'replaced';
    public const NOTED = 'noted';

    private const NOTE_SEPARATOR = ' · ';

    /**
     * @return array{quantity: Quantity, note: string, result: string}
     */
    public static function merge(
        Quantity $existing,
        string $existingNote,
        Quantity $incoming,
        string $incomingNote,
        UnitMismatch $onUnitMismatch,
        string $quantityText
    ): array {
        $sum = $existing->plus($incoming);

        if ($sum !== null) {
            return [
                'quantity' => $sum,
                'note' => self::mergeNotes($existingNote, $incomingNote),
                'result' => self::SUMMED
            ];
        }

        if ($onUnitMismatch === UnitMismatch::Replace) {
            return [
                'quantity' => $incoming,
                'note' => self::mergeNotes($existingNote, $incomingNote),
                'result' => self::REPLACED
            ];
        }

        if ($onUnitMismatch === UnitMismatch::Keep) {
            return [
                'quantity' => $existing,
                'note' => self::mergeNotes($existingNote, $incomingNote),
                'result' => self::KEPT
            ];
        }

        $text = trim($quantityText);

        if ($text === '') {
            $text = Quantity::formatAmount($incoming->amount());

            if ($incoming->unit() !== null) {
                $text .= ' ' . $incoming->unit();
            }
        }

        $note = self::mergeNotes($existingNote, '+ ' . $text);

        return [
            'quantity' => $existing,
            'note' => self::mergeNotes($note, $incomingNote),
            'result' => self::NOTED
        ];
    }

    public static function mergeNotes(string $existing, string $incoming): string
    {
        $existing = trim($existing);
        $incoming = trim($incoming);

        if ($incoming === '' || mb_strpos($existing, $incoming) !== false) {
            return $existing;
        }

        if ($existing === '') {
            return self::limit($incoming);
        }

        return self::limit($existing . self::NOTE_SEPARATOR . $incoming);
    }

    private static function limit(string $note): string
    {
        if (mb_strlen($note) <= Operation::NOTE_MAX_LENGTH) {
            return $note;
        }

        return rtrim(mb_substr($note, 0, Operation::NOTE_MAX_LENGTH));
    }
}
