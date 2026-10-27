/**
 * Sortierschlüssel zwischen zwei Nachbarn („fractional index“).
 *
 * Ein Schlüssel ist eine Ziffernfolge zur Basis 36 (`0-9a-z`) und steht für
 * einen Bruch zwischen 0 und 1 („i“ = 0,5). Verglichen wird als Zeichenkette,
 * bei gleichem Schlüssel entscheidet die ID des Eintrags. Schlüssel enden nie
 * auf „0“, damit unterhalb immer Platz bleibt.
 *
 * Gleiche Logik und gleiche Testfälle wie `Lists\SortKey` in PHP
 * (`tests/fixtures/sort-keys.json`). Ohne Abhängigkeiten, damit `node --test`
 * das Modul direkt lädt.
 */
export const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

export const MAX_LENGTH = 255;

const BASE = 36;

const VALID_KEY = /^[0-9a-z]*[1-9a-z]$/;

export function isValidSortKey(key) {
    return typeof key === 'string' && key.length <= MAX_LENGTH && VALID_KEY.test(key);
}

function assertValid(key) {
    if (!isValidSortKey(key)) {
        throw new RangeError(`Invalid sort key "${key}".`);
    }
}

function digitValue(digit) {
    const value = DIGITS.indexOf(digit);

    if (value === -1) {
        throw new RangeError(`Invalid sort key digit "${digit}".`);
    }

    return value;
}

/** Mitte zwischen `low` ('' = 0) und `high` (null = 1), `low` < `high`. */
function midpoint(low, high) {
    if (high !== null) {
        let prefixLength = 0;

        while (prefixLength < high.length && (low[prefixLength] ?? '0') === high[prefixLength]) {
            prefixLength++;
        }

        if (prefixLength > 0) {
            return high.slice(0, prefixLength) + midpoint(low.slice(prefixLength), high.slice(prefixLength));
        }
    }

    const lowDigit = low === '' ? 0 : digitValue(low[0]);
    const highDigit = high === null ? BASE : digitValue(high[0]);

    if (highDigit - lowDigit > 1) {
        return DIGITS[Math.floor((lowDigit + highDigit) / 2)];
    }

    if (high !== null && high.length > 1) {
        return high[0];
    }

    return DIGITS[lowDigit] + midpoint(low.slice(1), null);
}

/**
 * Kürzester einfacher Schlüssel hinter `key`: erste Stelle, die nicht „z“
 * ist, um eins erhöhen (i … z, zi … zz, zzi …).
 */
function increment(key) {
    for (let i = 0; i < key.length; i++) {
        if (key[i] !== 'z') {
            return key.slice(0, i) + DIGITS[digitValue(key[i]) + 1];
        }
    }

    return key + midpoint('', null);
}

/**
 * Schlüssel zwischen `before` und `after`; null heißt Anfang bzw. Ende der
 * Liste. Beide null: erster Schlüssel einer Liste.
 *
 * @param {string|null} before
 * @param {string|null} after
 * @returns {string}
 */
export function keyBetween(before, after) {
    if (before !== null) {
        assertValid(before);
    }

    if (after !== null) {
        assertValid(after);
    }

    if (before !== null && after !== null && before >= after) {
        throw new RangeError(`Sort key "${before}" must be lower than "${after}".`);
    }

    if (before === null && after === null) {
        return midpoint('', null);
    }

    if (after === null) {
        return increment(before);
    }

    return midpoint(before ?? '', after);
}

/**
 * `count` aufsteigende Schlüssel zwischen `before` und `after`, z. B. für eine
 * Liste aus einer Vorlage.
 *
 * @returns {string[]}
 */
export function keysBetween(before, after, count) {
    if (count <= 0) {
        return [];
    }

    if (count === 1) {
        return [keyBetween(before, after)];
    }

    if (after === null) {
        const keys = [];
        let key = before;

        for (let i = 0; i < count; i++) {
            key = keyBetween(key, null);
            keys.push(key);
        }

        return keys;
    }

    if (before === null) {
        const keys = [];
        let key = after;

        for (let i = 0; i < count; i++) {
            key = keyBetween(null, key);
            keys.push(key);
        }

        return keys.reverse();
    }

    const half = Math.floor(count / 2);
    const middle = keyBetween(before, after);

    return [
        ...keysBetween(before, middle, half),
        middle,
        ...keysBetween(middle, after, count - half - 1)
    ];
}

/** Reihenfolge zweier Einträge `{ id, sortKey }`: Schlüssel, bei Gleichstand die ID. */
export function compareBySortKey(a, b) {
    if (a.sortKey !== b.sortKey) {
        return a.sortKey < b.sortKey ? -1 : 1;
    }

    if (a.id === b.id) {
        return 0;
    }

    return a.id < b.id ? -1 : 1;
}

/**
 * Schlüssel für die Position `index` in einer sortierten Liste (ohne den
 * bewegten Eintrag). Haben die Nachbarn denselben Schlüssel (gleichzeitig
 * vergeben), gibt es keinen Platz dazwischen: dann `null`, und der Aufrufer
 * verschiebt zuerst den rechten Nachbarn (siehe docs/architecture.md, „Sync“).
 *
 * @param {{ sortKey: string }[]} sorted
 * @param {number} index 0 = an den Anfang, sorted.length = ans Ende
 * @returns {string|null}
 */
export function keyForIndex(sorted, index) {
    const before = index > 0 ? sorted[index - 1].sortKey : null;
    const after = index < sorted.length ? sorted[index].sortKey : null;

    if (before !== null && after !== null && before >= after) {
        return null;
    }

    return keyBetween(before, after);
}
