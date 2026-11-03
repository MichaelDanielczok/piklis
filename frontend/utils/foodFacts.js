/**
 * Auswahl und Anzeige der Angaben aus Open Food Facts, ohne Vue (getestet in
 * tests/js/foodFacts.test.js).
 *
 * Grundsatz: Eigenes wird nie ungefragt ersetzt. Vorausgewählt ist nur, was
 * am Produkt noch fehlt oder gleich ist; Zutaten und Nährwerte gibt es nur
 * aus Open Food Facts, sie sind deshalb immer vorausgewählt. Der Name ist
 * nie vorausgewählt (ein Produkt hat immer schon einen).
 */

/** Reihenfolge in der Auswahl */
export const FOOD_FACTS_FIELDS = ['name', 'brand', 'packageSize', 'image', 'ingredients', 'nutrition'];

function same(a, b) {
    const normalize = (value) => String(value ?? '').toLocaleLowerCase('de').replace(/\s+/g, ' ').trim();

    return normalize(a) === normalize(b);
}

/**
 * Was die Übernahme an einem Feld bewirkt:
 * - `new`: Feld ist leer, wird gefüllt
 * - `same`: schon gleich, ändert nichts
 * - `replace`: ersetzt einen eigenen bzw. bisherigen Wert
 *
 * @param {object} product Produkt der App
 * @param {object} result Treffer (`GET /openfoodfacts/{barcode}`)
 * @param {string} field
 * @returns {'new'|'same'|'replace'}
 */
export function fieldEffect(product, result, field) {
    switch (field) {
        case 'name':
        case 'brand':
        case 'packageSize': {
            const current = product?.[field] ?? null;

            if (current === null || current === '') {
                return 'new';
            }

            return same(current, result[field]) ? 'same' : 'replace';
        }

        case 'image':
            return product?.image ? 'replace' : 'new';

        case 'ingredients': {
            const current = product?.foodFacts?.ingredients ?? null;

            if (!current) {
                return 'new';
            }

            return current === result.ingredients ? 'same' : 'replace';
        }

        case 'nutrition': {
            const current = product?.foodFacts?.nutrition ?? null;

            if (!current) {
                return 'new';
            }

            return JSON.stringify(current) === JSON.stringify(result.nutrition) ? 'same' : 'replace';
        }

        default:
            return 'new';
    }
}

/**
 * Vorausgewählte Felder, in der Reihenfolge von `FOOD_FACTS_FIELDS`; nur
 * Felder, die der Treffer hat.
 *
 * @returns {string[]}
 */
export function defaultSelection(product, result) {
    const available = new Set(result?.fields ?? []);

    return FOOD_FACTS_FIELDS.filter((field) => {
        if (!available.has(field) || field === 'name') {
            return false;
        }

        if (field === 'ingredients' || field === 'nutrition') {
            return true;
        }

        return fieldEffect(product, result, field) !== 'replace';
    });
}

/**
 * Felder, die Open Food Facts nicht kennt (bleiben am Produkt, wie sie sind).
 *
 * @returns {string[]}
 */
export function missingFields(result) {
    const available = new Set(result?.fields ?? []);

    return FOOD_FACTS_FIELDS.filter((field) => !available.has(field));
}

/**
 * Optionen für Intl.NumberFormat je Nährwert: Energie ganz, kleine Werte
 * mit zwei, sonst eine Nachkommastelle („0,13 g“, „3,5 g“, „267 kJ“).
 *
 * @param {number} value
 * @param {string} unit
 */
export function nutrientFormat(value, unit) {
    if (unit === 'kJ' || unit === 'kcal') {
        return { maximumFractionDigits: 0 };
    }

    return { maximumFractionDigits: Math.abs(value) < 1 ? 2 : 1 };
}

/**
 * Ob die Tabelle eine Spalte „je Portion“ braucht.
 */
export function hasServingColumn(nutrition) {
    return Boolean(nutrition?.rows?.some((row) => row.perServing !== null && row.perServing !== undefined));
}

/**
 * Kurzfassung für die Auswahl: Energie je 100 g/ml, sonst der erste Wert.
 *
 * @returns {{ key: string, value: number, unit: string }|null}
 */
export function nutritionSummary(nutrition) {
    const rows = nutrition?.rows ?? [];
    const row = rows.find((item) => item.key === 'energy-kcal' && item.per100 !== null)
        ?? rows.find((item) => item.per100 !== null);

    return row ? { key: row.key, value: row.per100, unit: row.unit } : null;
}
