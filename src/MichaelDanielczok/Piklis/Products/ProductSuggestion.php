<?php

namespace MichaelDanielczok\Piklis\Products;

use RuntimeException;

use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function str_ends_with;
use function str_starts_with;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Vorschlag von Kategorie und Ersatzicon aus dem Produktnamen (Obsidian
 * „Piklis Entscheidungen“ › „Zuordnung“): Stichwortliste mit Wortstämmen in
 * `data/keywords.json`, keine KI, kein externer Dienst.
 *
 * - Namen und Stichwörter werden wie die Suche normalisiert (`SearchText`).
 * - Ein Wort passt, wenn es auf das Stichwort endet, auch mit einer Endung aus
 *   `SUFFIXES` („Vollkornbrot“ → brot, „Bananen“ → banane). Im Wort gewinnt
 *   das längste passende Stichwort, bei Gleichstand der frühere Eintrag.
 * - Es zählt das erste Wort mit Treffer: Die Sorte steht meist dahinter
 *   („Joghurt Erdbeere“ → Joghurt, „Eistee Pfirsich“ → Eistee).
 * - `=` vor einem Stichwort: nur das ganze Wort (mit Endung), sonst würde
 *   „ei“ auch „Brei“ treffen.
 * - Passt kein Wortende, zählt ein Wortanfang ab `PREFIX_MIN_LENGTH`
 *   Buchstaben („Hähnchenbrustfilet“ → hähnchen).
 * - Nichts passt: null, also allgemeines Icon ohne Kategorie.
 *
 * Dieselbe Regel steht in `frontend/products/suggest.js`; beide prüfen
 * `tests/fixtures/product-suggestions.json`.
 */
final class ProductSuggestion
{
    public const SUFFIXES = ['', 'n', 'en', 'e', 's', 'es', 'er', 'nen', 'ern'];

    public const PREFIX_MIN_LENGTH = 4;

    private static ?self $Default = null;

    /**
     * @param list<array{word: string, exact: bool, icon: string, category: string}> $keywords
     */
    public function __construct(private readonly array $keywords)
    {
    }

    /**
     * Stichwörter aus `data/keywords.json` des Pakets.
     */
    public static function default(): self
    {
        return self::$Default ??= self::fromFile(dirname(__DIR__, 4) . '/data/keywords.json');
    }

    public static function fromFile(string $file): self
    {
        $json = file_get_contents($file);

        if ($json === false) {
            throw new RuntimeException('Keyword file not readable: ' . $file);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $entries = is_array($data) && is_array($data['keywords'] ?? null) ? $data['keywords'] : [];
        $keywords = [];

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['icon'] ?? null)
                || !is_string($entry['category'] ?? null)
                || !is_array($entry['words'] ?? null)
            ) {
                continue;
            }

            foreach ($entry['words'] as $word) {
                if (!is_string($word)) {
                    continue;
                }

                $exact = str_starts_with($word, '=');
                $normalized = SearchText::normalize($exact ? substr($word, 1) : $word);

                if ($normalized === '') {
                    continue;
                }

                $keywords[] = [
                    'word' => $normalized,
                    'exact' => $exact,
                    'icon' => $entry['icon'],
                    'category' => $entry['category']
                ];
            }
        }

        return new self($keywords);
    }

    /**
     * @return array{category: string, icon: string}|null
     */
    public function suggest(string $name): ?array
    {
        $words = SearchText::tokens($name);

        $match = $this->best($words, self::matchesEnd(...))
            ?? $this->best($words, self::matchesStart(...));

        if ($match === null) {
            return null;
        }

        return [
            'category' => $match['category'],
            'icon' => $match['icon']
        ];
    }

    /**
     * @param list<string> $words
     * @param callable(string, array{word: string, exact: bool, icon: string, category: string}): bool $matches
     * @return array{word: string, exact: bool, icon: string, category: string}|null
     */
    private function best(array $words, callable $matches): ?array
    {
        foreach ($words as $word) {
            $best = null;
            $bestLength = 0;

            foreach ($this->keywords as $keyword) {
                $length = mb_strlen($keyword['word']);

                if ($length > $bestLength && $matches($word, $keyword)) {
                    $best = $keyword;
                    $bestLength = $length;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @param array{word: string, exact: bool, icon: string, category: string} $keyword
     */
    private static function matchesEnd(string $word, array $keyword): bool
    {
        foreach (self::SUFFIXES as $suffix) {
            $form = $keyword['word'] . $suffix;

            if ($keyword['exact'] ? $word === $form : str_ends_with($word, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{word: string, exact: bool, icon: string, category: string} $keyword
     */
    private static function matchesStart(string $word, array $keyword): bool
    {
        return !$keyword['exact']
            && mb_strlen($keyword['word']) >= self::PREFIX_MIN_LENGTH
            && str_starts_with($word, $keyword['word']);
    }
}
