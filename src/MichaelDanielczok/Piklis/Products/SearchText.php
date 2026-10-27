<?php

namespace MichaelDanielczok\Piklis\Products;

use Normalizer;

use function class_exists;
use function mb_strtolower;
use function preg_replace;
use function preg_split;
use function strtr;
use function trim;

/**
 * Text für die Produktsuche: klein, ohne Akzente, Satzzeichen als Leerzeichen.
 * „Käse“ und „kase“ finden dasselbe, ebenso „Bio-Äpfel“ und „bio apfel“.
 *
 * Dieselbe Regel steht in `frontend/utils/productSearch.js`; beide prüfen die
 * Fälle aus `tests/fixtures/product-search.json`.
 */
final class SearchText
{
    /**
     * Zeichen, die sich nicht in Buchstabe plus Akzent zerlegen lassen.
     */
    private const SPECIAL = [
        'ß' => 'ss',
        'ł' => 'l',
        'ø' => 'o',
        'æ' => 'ae',
        'œ' => 'oe',
        'đ' => 'd'
    ];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::SPECIAL);

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

            if ($decomposed !== false) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        // alles außer Buchstaben und Ziffern trennt Wörter („1,5 l“ bleibt „1 5 l“)
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Suchwörter einer Eingabe, normalisiert, ohne leere.
     *
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        $normalized = self::normalize($query);

        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/ +/', $normalized);

        return $tokens === false ? [] : $tokens;
    }
}
