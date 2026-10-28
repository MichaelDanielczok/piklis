/**
 * Vorschlag von Kategorie und Ersatzicon aus dem Produktnamen, gleiche Regel
 * wie `Products\ProductSuggestion` (PHP); beide prüfen
 * `tests/fixtures/product-suggestions.json`. Stichwörter in
 * `data/keywords.json`, keine KI, kein externer Dienst.
 *
 * - Ein Wort passt, wenn es auf das Stichwort endet, auch mit Endung
 *   („Vollkornbrot“ → brot, „Bananen“ → banane); im Wort gewinnt das
 *   längste Stichwort, bei Gleichstand der frühere Eintrag.
 * - Es zählt das erste Wort mit Treffer: Die Sorte steht meist dahinter
 *   („Joghurt Erdbeere“ → Joghurt).
 * - `=` vor einem Stichwort: nur das ganze Wort („=ei“ passt nicht auf „Brei“).
 * - Sonst zählt ein Wortanfang ab 4 Buchstaben („Hähnchenbrustfilet“).
 * - Nichts passt: null (allgemeines Icon, ohne Kategorie).
 */
import data from '../../data/keywords.json' with { type: 'json' };
import { normalizeSearchText, searchTokens } from '../utils/productSearch.js';

export const SUFFIXES = Object.freeze(['', 'n', 'en', 'e', 's', 'es', 'er', 'nen', 'ern']);

export const PREFIX_MIN_LENGTH = 4;

const KEYWORDS = data.keywords.flatMap(({ icon, category, words }) => words
    .map((word) => {
        const exact = word.startsWith('=');

        return { word: normalizeSearchText(exact ? word.slice(1) : word), exact, icon, category };
    })
    .filter((keyword) => keyword.word !== ''));

/** Stichwörter je Icon, normalisiert und mit Leerzeichen verbunden (Suche in der Icon-Auswahl) */
export const ICON_KEYWORDS = Object.freeze(KEYWORDS.reduce((result, { icon, word }) => {
    result[icon] = result[icon] ? result[icon] + ' ' + word : word;

    return result;
}, {}));

function matchesEnd(word, keyword) {
    return SUFFIXES.some((suffix) => {
        const form = keyword.word + suffix;

        return keyword.exact ? word === form : word.endsWith(form);
    });
}

function matchesStart(word, keyword) {
    return !keyword.exact && keyword.word.length >= PREFIX_MIN_LENGTH && word.startsWith(keyword.word);
}

function best(words, matches) {
    for (const word of words) {
        let found = null;

        for (const keyword of KEYWORDS) {
            if (keyword.word.length > (found?.word.length ?? 0) && matches(word, keyword)) {
                found = keyword;
            }
        }

        if (found) {
            return found;
        }
    }

    return null;
}

/**
 * @param {string} name
 * @returns {{ category: string, icon: string } | null}
 */
export function suggestForName(name) {
    const words = searchTokens(name);
    const match = best(words, matchesEnd) ?? best(words, matchesStart);

    return match ? { category: match.category, icon: match.icon } : null;
}
