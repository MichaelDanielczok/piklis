/**
 * Übernehmen in eine andere Liste und neue Liste aus einem früheren Einkauf
 * (Piklis Konzept „Abhaken und Nicht bekommen“, „Abschluss und Archiv“).
 *
 * - `mergeTakeOver()`: wie `Sync\TakeOverMerge` in PHP (gemeinsame Fälle in
 *   `tests/fixtures/take-over.json`). Der Server führt zusammen; die App
 *   rechnet dasselbe vorab, damit die Zielliste sofort stimmt und sie bei
 *   anderer Einheit vorher fragen kann.
 * - `planTakeOver()`: was mit jedem Eintrag in der Zielliste geschieht
 *   (neu · addieren · andere Einheit → fragen).
 * - `copyPlan()`: Einträge eines früheren Einkaufs für eine neue Liste, in
 *   Listenreihenfolge, je Produkt einmal.
 *
 * Ohne Vue und ohne Netz (`node --test`).
 */
import { formatAmount, MAX_AMOUNT } from './quantity.js';
import { compareBySortKey } from './sortKey.js';

const SCALE = 1000;
const NOTE_MAX_LENGTH = 500;
const NOTE_SEPARATOR = ' · ';

export const UNIT_MISMATCH = Object.freeze(['keep', 'replace', 'note']);

function sameUnit(a, b) {
    return (a.unit ?? null) === (b.unit ?? null);
}

function quantityOf(value) {
    return { amount: value.amount, unit: value.unit ?? null };
}

function limit(note) {
    const chars = [...note];

    return chars.length <= NOTE_MAX_LENGTH ? note : chars.slice(0, NOTE_MAX_LENGTH).join('').trimEnd();
}

/** Notizen zusammenführen: leere füllen, andere anhängen, nichts doppelt. */
export function mergeNotes(existing, incoming) {
    const current = (existing ?? '').trim();
    const added = (incoming ?? '').trim();

    if (added === '' || current.includes(added)) {
        return current;
    }

    if (current === '') {
        return limit(added);
    }

    return limit(current + NOTE_SEPARATOR + added);
}

/**
 * @param {{ quantity: object, note: string }} existing Eintrag der Zielliste
 * @param {{ quantity: object, note: string }} incoming übernommener Eintrag
 * @param {'keep'|'replace'|'note'} mode Antwort bei anderer Einheit
 * @param {string} quantityText übernommene Menge als Text („400 g“)
 * @returns {{ quantity: object, note: string, result: 'summed'|'kept'|'replaced'|'noted' }}
 */
export function mergeTakeOver(existing, incoming, mode = 'note', quantityText = '') {
    if (sameUnit(existing.quantity, incoming.quantity)) {
        const sum = Math.round(existing.quantity.amount * SCALE) + Math.round(incoming.quantity.amount * SCALE);

        return {
            quantity: { amount: Math.min(sum, MAX_AMOUNT * SCALE) / SCALE, unit: existing.quantity.unit ?? null },
            note: mergeNotes(existing.note, incoming.note),
            result: 'summed'
        };
    }

    if (mode === 'replace') {
        return { quantity: quantityOf(incoming.quantity), note: mergeNotes(existing.note, incoming.note), result: 'replaced' };
    }

    if (mode === 'keep') {
        return { quantity: quantityOf(existing.quantity), note: mergeNotes(existing.note, incoming.note), result: 'kept' };
    }

    let text = (quantityText ?? '').trim();

    if (text === '') {
        text = formatAmount(incoming.quantity.amount) + (incoming.quantity.unit ? ' ' + incoming.quantity.unit : '');
    }

    return {
        quantity: quantityOf(existing.quantity),
        note: mergeNotes(mergeNotes(existing.note, '+ ' + text), incoming.note),
        result: 'noted'
    };
}

/** Offener Eintrag eines Produkts in der Zielliste (nur der zählt). */
export function openEntryOf(entries, productId) {
    for (const entry of entries?.values() ?? []) {
        if (!entry.removed && !entry.mergedInto && entry.status === 'open' && entry.productId === productId) {
            return entry;
        }
    }

    return null;
}

/**
 * Was in der Zielliste geschieht: `add` (neu), `sum` (gleiche Einheit,
 * addieren) oder `mismatch` (andere Einheit: App fragt).
 *
 * @param {Map<string, object>|null} targetEntries
 * @param {object[]} entries zu übernehmende Einträge
 * @returns {{ entry: object, existing: object|null, kind: 'add'|'sum'|'mismatch' }[]}
 */
export function planTakeOver(targetEntries, entries) {
    return entries.map((entry) => {
        const existing = openEntryOf(targetEntries, entry.productId);

        if (!existing) {
            return { entry, existing: null, kind: 'add' };
        }

        return { entry, existing, kind: sameUnit(existing.quantity, entry.quantity) ? 'sum' : 'mismatch' };
    });
}

/**
 * Einträge eines früheren Einkaufs für eine neue Liste: alle sichtbaren
 * (offen, erledigt, nicht bekommen) in Listenreihenfolge, je Produkt einmal
 * an der ersten Stelle. Stand dasselbe Produkt mehrmals drauf („noch mal
 * Milch“), werden gleiche Einheiten addiert. Notizen bleiben im alten
 * Einkauf (wie bei Vorlagen).
 *
 * @param {Map<string, object>|null} entries
 * @returns {{ productId: string, quantity: object, status: string }[]}
 */
export function copyPlan(entries) {
    const sorted = [...(entries?.values() ?? [])]
        .filter((entry) => !entry.removed && !entry.mergedInto)
        .sort(compareBySortKey);
    const byProduct = new Map();

    for (const entry of sorted) {
        const known = byProduct.get(entry.productId);

        if (!known) {
            byProduct.set(entry.productId, {
                productId: entry.productId,
                quantity: quantityOf(entry.quantity),
                status: entry.status
            });
            continue;
        }

        if (sameUnit(known.quantity, entry.quantity)) {
            known.quantity = mergeTakeOver(known, { quantity: entry.quantity, note: '' }).quantity;
        }

        if (entry.status !== 'done') {
            known.status = entry.status;
        }
    }

    return [...byProduct.values()];
}
