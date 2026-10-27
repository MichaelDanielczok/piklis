<?php

namespace MichaelDanielczok\Piklis\Lists;

use function array_key_exists;
use function count;
use function strcmp;
use function usort;

/**
 * Vorschau einer Liste in der Übersicht: die Produkte der ersten offenen
 * Einträge in Listenreihenfolge (Sortierschlüssel, bei Gleichstand
 * Eintrags-ID; verglichen als Zeichenkette wie `SortKey`, nicht nach der
 * Collation der Datenbank).
 */
final class ListPreview
{
    /** Bilder je Liste in der Übersicht. */
    public const SIZE = 4;

    /**
     * @param list<array{list_id: string, id: string, product_id: string, sort_key: string}> $rows
     *        offene, sichtbare Einträge beliebiger Listen in beliebiger Reihenfolge
     * @return array<string, list<string>> Liste → Produkt-IDs, höchstens `$limit`
     */
    public static function firstProducts(array $rows, int $limit = self::SIZE): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $byKey = strcmp($a['sort_key'], $b['sort_key']);

                if ($byKey !== 0) {
                    return $byKey;
                }

                return strcmp($a['id'], $b['id']);
            }
        );

        $previews = [];

        foreach ($rows as $row) {
            $listId = $row['list_id'];

            if (!array_key_exists($listId, $previews)) {
                $previews[$listId] = [];
            }

            if (count($previews[$listId]) >= $limit) {
                continue;
            }

            $previews[$listId][] = $row['product_id'];
        }

        return $previews;
    }
}
