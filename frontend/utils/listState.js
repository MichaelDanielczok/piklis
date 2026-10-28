/**
 * Lokaler Stand einer Liste: letzter Serverstand plus eigene, noch nicht
 * bestätigte Operationen darüber (docs/architecture.md, „Client side“).
 * So flackern eigene Änderungen nie zurück, und ein abgelehnter Vorgang
 * verschwindet von selbst, sobald er nicht mehr in der Warteschlange steht.
 *
 * Ohne Vue und ohne Netz, damit `node --test` es direkt prüft. Zustände
 * werden nie verändert, jede Funktion gibt einen neuen zurück.
 *
 * Stand: `{ list: object|null, entries: Map<id, entry>|null }`; `entries`
 * ist null, solange die Einträge nicht geladen sind (Übersicht). Entfernte
 * Einträge bleiben mit `removed: true` darin, damit „Rückgängig“ sie sofort
 * wieder zeigen kann; sichtbar ist nur, was weder entfernt noch Verweis ist.
 */
import { OPERATION_TYPES as T } from '@/api/operations.js';
import { compareBySortKey, keyBetween, keyForIndex } from './sortKey.js';
import { mergeTakeOver, openEntryOf } from './takeOver.js';

export const EMPTY_COUNTS = Object.freeze({ open: 0, done: 0, notGot: 0, total: 0 });

export function isVisible(entry) {
    return !entry.removed && !entry.mergedInto;
}

/**
 * Antwort von `GET /lists/{id}` (ganz oder „seit N“) in die Einträge
 * übernehmen. Verweise (`mergedInto`) fallen heraus, entfernte bleiben
 * markiert.
 *
 * @param {Map<string, object>|null} entries
 * @param {{ full: boolean, entries: object[] }} response
 * @returns {Map<string, object>}
 */
export function mergeEntries(entries, response) {
    const result = response.full || !entries ? new Map() : new Map(entries);

    for (const entry of response.entries) {
        if (entry.mergedInto) {
            result.delete(entry.id);
            continue;
        }

        result.set(entry.id, entry);
    }

    return result;
}

/** Ein Eintrag aus einer Antwort auf eine Operation. */
export function upsertEntry(entries, entry) {
    return mergeEntries(entries, { full: false, entries: [entry] });
}

function newList(op) {
    return {
        id: op.listId,
        name: op.name,
        shopDate: op.shopDate ?? null,
        ownership: op.householdId ? 'household' : 'personal',
        householdId: op.householdId ?? null,
        status: 'active',
        role: 'owner',
        revision: 0,
        createdAt: op.createdAt ?? null,
        updatedAt: op.createdAt ?? null,
        deleted: false,
        local: true
    };
}

function newEntry(op) {
    return {
        id: op.entryId,
        listId: op.listId,
        productId: op.productId,
        quantity: { amount: op.quantity.amount, unit: op.quantity.unit ?? null },
        note: op.note ?? '',
        sortKey: op.sortKey,
        status: 'open',
        checkedQuantity: null,
        removed: false,
        mergedInto: null,
        createdAt: op.createdAt ?? null
    };
}

function changeList(state, change) {
    if (!state.list) {
        return state;
    }

    return { ...state, list: { ...state.list, ...change } };
}

function changeEntry(state, entryId, change) {
    const entry = state.entries?.get(entryId);

    if (!entry) {
        return state;
    }

    const entries = new Map(state.entries);
    entries.set(entryId, { ...entry, ...change });

    return { ...state, entries };
}

/**
 * Eine Operation auf den Stand anwenden, so wie der Server sie im
 * Normalfall ausführt (ohne Konfliktregeln: die entscheidet der Server).
 */
export function applyOperation(state, op) {
    switch (op.type) {
        case T.LIST_CREATE:
            return state.list ? state : { ...state, list: newList(op) };

        case T.LIST_UPDATE: {
            const change = {};

            if (op.fields.name !== undefined) {
                change.name = op.fields.name;
            }

            if (op.fields.shopDate !== undefined) {
                change.shopDate = op.fields.shopDate;
            }

            return changeList(state, change);
        }

        case T.LIST_COMPLETE:
            return state.list?.status === 'completed'
                ? state
                : changeList(state, { status: 'completed', completedAt: op.createdAt ?? null });

        case T.LIST_REOPEN:
            return changeList(state, { status: 'active', completedAt: null, changedAfterCompletion: false });

        case T.LIST_DELETE:
            return changeList(state, { deleted: true });

        case T.ENTRY_ADD: {
            if (!state.entries || state.entries.has(op.entryId)) {
                return state;
            }

            const entries = new Map(state.entries);
            entries.set(op.entryId, newEntry(op));

            return { ...state, entries };
        }

        case T.ENTRY_SET: {
            const change = {};

            if (op.set.quantity !== undefined) {
                change.quantity = { amount: op.set.quantity.amount, unit: op.set.quantity.unit ?? null };
            }

            if (op.set.note !== undefined) {
                change.note = op.set.note;
            }

            return changeEntry(state, op.entryId, change);
        }

        case T.ENTRY_MOVE:
            return changeEntry(state, op.entryId, { sortKey: op.sortKey });

        case T.ENTRY_SET_STATUS:
            return changeEntry(state, op.entryId, {
                status: op.status,
                checkedQuantity: op.status === 'done' ? (op.seen ?? null) : null
            });

        case T.ENTRY_REMOVE:
            return changeEntry(state, op.entryId, { removed: true });

        case T.ENTRY_RESTORE:
            return changeEntry(state, op.entryId, { removed: false });

        case T.ENTRY_TRANSFER:
            return changeEntry(state, op.entryId, { transferredTo: op.transferredTo });

        case T.ENTRY_TAKE_OVER: {
            if (!state.entries || state.entries.has(op.entryId)) {
                return state;
            }

            const existing = openEntryOf(state.entries, op.productId);

            if (!existing) {
                const entries = new Map(state.entries);
                entries.set(op.entryId, newEntry(op));

                return { ...state, entries };
            }

            const merged = mergeTakeOver(existing, op, op.onUnitMismatch, op.quantityText);

            return changeEntry(state, existing.id, { quantity: merged.quantity, note: merged.note });
        }

        default:
            return state;
    }
}

/** Alle Operationen dieser Liste der Reihe nach anwenden. */
export function applyOperations(state, ops, listId) {
    return ops.reduce((current, op) => (op.listId === listId ? applyOperation(current, op) : current), state);
}

/**
 * Sichtbare Einträge eines Zustands in Listenreihenfolge.
 *
 * @param {Map<string, object>|null} entries
 * @param {'open'|'done'|'notGot'|null} [status] null = alle
 */
export function sortedEntries(entries, status = null) {
    if (!entries) {
        return [];
    }

    return [...entries.values()]
        .filter((entry) => isVisible(entry) && (status === null || entry.status === status))
        .sort(compareBySortKey);
}

/** Zähler wie `counts` in `GET /lists`. */
export function countEntries(entries) {
    const counts = { ...EMPTY_COUNTS };

    for (const entry of entries?.values() ?? []) {
        if (!isVisible(entry)) {
            continue;
        }

        counts[entry.status] = (counts[entry.status] ?? 0) + 1;
        counts.total++;
    }

    return counts;
}

/**
 * Schlüssel für einen neuen Eintrag am Ende: hinter allen Einträgen, auch
 * erledigten und entfernten, damit neue Produkte in der Reihenfolge des
 * Hinzufügens stehen und Zurückgeholtes seinen Platz davor behält.
 */
export function appendKey(entries) {
    let last = null;

    for (const entry of entries?.values() ?? []) {
        if (last === null || entry.sortKey > last) {
            last = entry.sortKey;
        }
    }

    return keyBetween(last, null);
}

/**
 * Offener Eintrag eines Produkts: kein zweiter Eintrag. Erledigte und nicht
 * bekommene zählen nicht (wie beim Server, `EntryState::isOpenFor`).
 */
export function activeEntryOf(entries, productId) {
    return openEntryOf(entries, productId);
}

/**
 * Neue Schlüssel, um `sorted[from]` an die Stelle `to` zu bringen (Indizes
 * in `sorted`, der Liste mit dem Eintrag). Meist eine Verschiebung; haben die
 * Nachbarn am Ziel denselben Schlüssel, zuerst der rechte Nachbar dahinter.
 *
 * @param {{ id: string, sortKey: string }[]} sorted
 * @returns {{ entry: object, sortKey: string }[]}
 */
export function planMove(sorted, from, to) {
    if (from === to || from < 0 || from >= sorted.length) {
        return [];
    }

    const moved = sorted[from];
    const others = sorted.filter((entry, index) => index !== from);
    const index = Math.max(0, Math.min(to, others.length));
    const key = keyForIndex(others, index);

    if (key !== null) {
        return [{ entry: moved, sortKey: key }];
    }

    const right = others[index];
    const next = others.slice(index + 1).find((entry) => entry.sortKey !== right.sortKey) ?? null;
    const rightKey = keyBetween(right.sortKey, next ? next.sortKey : null);

    return [
        { entry: right, sortKey: rightKey },
        { entry: moved, sortKey: keyBetween(others[index - 1].sortKey, rightKey) }
    ];
}
