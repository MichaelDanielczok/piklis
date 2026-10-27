/**
 * Suche im Katalog nach dem Namen, für Vorschläge beim Tippen. Gleiche Regel
 * wie `Products\SearchText` und `Products\ProductSearch` (PHP); beide prüfen
 * die Fälle aus `tests/fixtures/product-search.json`.
 *
 * - Suchtext: klein, ohne Akzente, Satzzeichen als Leerzeichen
 *   („Bio-Äpfel“ → „bio apfel“).
 * - Jedes Suchwort muss im Namen vorkommen, auch mitten im Wort
 *   („brot“ findet „Vollkornbrot“).
 * - Reihenfolge: gleicher Name · Name beginnt mit der Eingabe · jedes
 *   Suchwort beginnt ein Wort · übrige; sonst alphabetisch.
 * - Treffer sind nur Vorschläge: ähnliche Namen werden nie zusammengeführt.
 */

export const RANK = Object.freeze({
    EXACT: 0,
    PREFIX: 1,
    WORD_PREFIX: 2,
    CONTAINS: 3
});

export const DEFAULT_LIMIT = 20;

// Zeichen, die sich nicht in Buchstabe plus Akzent zerlegen lassen
const SPECIAL = { ß: 'ss', ł: 'l', ø: 'o', æ: 'ae', œ: 'oe', đ: 'd' };
const SPECIAL_PATTERN = new RegExp('[' + Object.keys(SPECIAL).join('') + ']', 'g');

/** @param {string} text */
export function normalizeSearchText(text) {
    return String(text ?? '')
        .toLowerCase()
        .replace(SPECIAL_PATTERN, (char) => SPECIAL[char])
        .normalize('NFD')
        .replace(/\p{Mn}+/gu, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

/** @param {string} query */
export function searchTokens(query) {
    const normalized = normalizeSearchText(query);

    return normalized === '' ? [] : normalized.split(/ +/);
}

/**
 * Rang eines normalisierten Namens, null ohne Treffer.
 *
 * @param {string} name normalisiert
 * @param {string[]} tokens normalisiert
 */
export function rankName(name, tokens) {
    if (!tokens.every((token) => name.includes(token))) {
        return null;
    }

    const query = tokens.join(' ');

    if (name === query) {
        return RANK.EXACT;
    }

    if (name.startsWith(query)) {
        return RANK.PREFIX;
    }

    const words = name.split(' ');

    return tokens.every((token) => words.some((word) => word.startsWith(token))) ? RANK.WORD_PREFIX : RANK.CONTAINS;
}

// Vergleich wie PHP strcmp (Bytes, nicht Sprache), damit beide gleich sortieren
function compareStrings(a, b) {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}

/**
 * @template {{ id: string, name: string, hidden?: boolean }} P
 * @param {string} query
 * @param {P[]} products
 * @param {{ limit?: number, includeHidden?: boolean }} [options]
 * @returns {P[]}
 */
export function searchProducts(query, products, { limit = DEFAULT_LIMIT, includeHidden = false } = {}) {
    const tokens = searchTokens(query);

    if (tokens.length === 0 || limit < 1) {
        return [];
    }

    const hits = [];

    for (const product of products) {
        if (product.hidden && !includeHidden) {
            continue;
        }

        const name = normalizeSearchText(product.name);
        const rank = rankName(name, tokens);

        if (rank !== null) {
            hits.push({ rank, name, product });
        }
    }

    hits.sort((a, b) => a.rank - b.rank || compareStrings(a.name, b.name) || compareStrings(a.product.id, b.product.id));

    return hits.slice(0, limit).map((hit) => hit.product);
}
