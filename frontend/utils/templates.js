/**
 * Vorlagen ohne Vue und ohne Netz (`node --test`): Produkte einer Liste für
 * eine neue Vorlage und der Entwurf beim Bearbeiten.
 *
 * Eine Vorlage hält jedes Produkt höchstens einmal mit Menge, Reihenfolge
 * und (nur auf Wunsch) Notiz. Gespeichert wird immer der ganze Entwurf.
 */
import { compareBySortKey } from './sortKey.js';
import { MAX_AMOUNT } from './quantity.js';
import { copyPlan, mergeNotes } from './takeOver.js';

/**
 * Produkte einer Liste für „Als Vorlage speichern“: alle sichtbaren (offen,
 * erledigt, nicht bekommen) in Listenreihenfolge, je Produkt einmal an der
 * ersten Stelle, gleiche Einheiten addiert (wie `copyPlan`). `note` sind die
 * Einkaufsnotizen des Produkts zusammengeführt; gespeichert werden sie nur
 * auf Wunsch.
 *
 * @param {Map<string, object>|null} entries
 * @returns {{ productId: string, quantity: object, status: string, note: string }[]}
 */
export function templatePlan(entries) {
    const notes = new Map();
    const sorted = [...(entries?.values() ?? [])]
        .filter((entry) => !entry.removed && !entry.mergedInto)
        .sort(compareBySortKey);

    for (const entry of sorted) {
        notes.set(entry.productId, mergeNotes(notes.get(entry.productId) ?? '', entry.note ?? ''));
    }

    return copyPlan(entries).map((item) => ({ ...item, note: notes.get(item.productId) ?? '' }));
}

/**
 * Einträge zum Senden: Produkt, Menge und Notiz (ohne Notizen: leer).
 *
 * @param {{ productId: string, quantity: object, note?: string }[]} items
 * @param {{ withNotes?: boolean }} [options]
 */
export function templateEntries(items, { withNotes = true } = {}) {
    return items.map((item) => ({
        productId: item.productId,
        quantity: { amount: item.quantity.amount, unit: item.quantity.unit ?? null },
        note: withNotes ? (item.note ?? '') : ''
    }));
}

/**
 * Entwurf aus einer geladenen Vorlage (Einträge schon in Reihenfolge).
 *
 * @param {{ name: string, revision: number, entries: object[] }} template
 */
export function draftOf(template) {
    return {
        name: template.name,
        revision: template.revision,
        items: templateEntries(template.entries)
    };
}

/** Hat sich der Entwurf gegenüber der Vorlage geändert? */
export function isDraftChanged(draft, template) {
    if (!draft || !template) {
        return false;
    }

    return draft.name.trim() !== template.name
        || JSON.stringify(draft.items) !== JSON.stringify(templateEntries(template.entries));
}

/**
 * Menge eines Produkts im Entwurf setzen („Produkte hinzufügen“): 0 nimmt es
 * heraus, sonst Menge setzen (Einheit bleibt) oder am Ende anfügen.
 *
 * @returns {object[]} neue Einträge
 */
export function withQuantity(items, productId, amount) {
    const target = Math.min(MAX_AMOUNT, Math.max(0, amount));
    const index = items.findIndex((item) => item.productId === productId);

    if (index === -1) {
        return target > 0 ? [...items, { productId, quantity: { amount: target, unit: null }, note: '' }] : items;
    }

    if (target <= 0) {
        return items.filter((item) => item.productId !== productId);
    }

    return items.map((item, position) => (position === index
        ? { ...item, quantity: { ...item.quantity, amount: target } }
        : item));
}

/** Eintrag von Position `from` nach `to` verschieben. */
export function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }

    const result = [...items];
    const [moved] = result.splice(from, 1);

    result.splice(to, 0, moved);

    return result;
}
