<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;

use function count;
use function max;
use function min;
use function round;
use function strcmp;
use function usort;

/**
 * Gewichtung der Vorschläge in „Produkte hinzufügen“.
 *
 * - Zählt, auf wie vielen Einkaufslisten ein Produkt in den letzten
 *   90 Tagen stand (alle Listen, auf die man Zugriff hat). Mehrere Einträge
 *   desselben Produkts auf einer Liste („noch mal Milch“) zählen einmal,
 *   mit dem jüngsten Zeitpunkt.
 * - Jede Liste zählt 1, dazu ein Bonus für kürzlich Gekauftes: linear von
 *   0,5 (heute) bis 0 (vor 90 Tagen). Häufigkeit entscheidet also, die
 *   Aktualität gibt bei ähnlicher Häufigkeit den Ausschlag: dreimal vor
 *   zwei Monaten (≈ 3,3) liegt vor zweimal gestern (≈ 3,0).
 * - Gleichstand: zuletzt benutzt zuerst, dann Produkt-ID (stabil).
 *
 * Der restliche Katalog folgt im Browser alphabetisch
 * (`frontend/utils/pickerOrder.js`).
 */
final class SuggestionRanking
{
    public const DAYS = 90;

    public const RECENCY_BONUS = 0.5;

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param iterable<array{product_id: string, list_id: string, used_at: DateTimeImmutable}> $usages
     *        Einträge mit Zeitpunkt (abgehakt: wann gekauft, sonst wann hinzugefügt)
     * @return list<array{productId: string, score: float, uses: int, lastUsedAt: DateTimeImmutable}>
     *         beste zuerst, nur Produkte mit Nutzung im Zeitraum
     */
    public static function rank(iterable $usages, DateTimeImmutable $now): array
    {
        $since = self::since($now);

        /** @var array<string, array<string, DateTimeImmutable>> $byProduct Produkt → Liste → jüngste Nutzung */
        $byProduct = [];

        foreach ($usages as $usage) {
            $usedAt = $usage['used_at'];

            if ($usedAt < $since) {
                continue;
            }

            $productId = $usage['product_id'];
            $listId = $usage['list_id'];
            $known = $byProduct[$productId][$listId] ?? null;

            if ($known === null || $usedAt > $known) {
                $byProduct[$productId][$listId] = $usedAt;
            }
        }

        $ranked = [];

        foreach ($byProduct as $productId => $lists) {
            $score = 0.0;
            $lastUsedAt = null;

            foreach ($lists as $usedAt) {
                $score += self::weight($usedAt, $now);

                if ($lastUsedAt === null || $usedAt > $lastUsedAt) {
                    $lastUsedAt = $usedAt;
                }
            }

            if ($lastUsedAt === null) {
                continue;
            }

            $ranked[] = [
                'productId' => (string)$productId,
                'score' => round($score, 4),
                'uses' => count($lists),
                'lastUsedAt' => $lastUsedAt
            ];
        }

        usort(
            $ranked,
            static function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }

                if ($a['lastUsedAt'] != $b['lastUsedAt']) {
                    return $b['lastUsedAt'] <=> $a['lastUsedAt'];
                }

                return strcmp($a['productId'], $b['productId']);
            }
        );

        return $ranked;
    }

    /**
     * Gewicht einer Liste: 1 plus Bonus, der über 90 Tage auf 0 sinkt.
     * Zeitpunkte in der Zukunft (Uhr eines Geräts) zählen wie heute.
     */
    public static function weight(DateTimeImmutable $usedAt, DateTimeImmutable $now): float
    {
        $ageDays = ($now->getTimestamp() - $usedAt->getTimestamp()) / self::SECONDS_PER_DAY;
        $ageDays = max(0.0, min((float)self::DAYS, $ageDays));

        return 1.0 + self::RECENCY_BONUS * (1.0 - $ageDays / self::DAYS);
    }

    /**
     * Beginn des Zeitraums: jetzt minus 90 Tage.
     */
    public static function since(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimestamp($now->getTimestamp() - self::DAYS * self::SECONDS_PER_DAY);
    }
}
