/**
 * Operationen: der einzige Weg, Listen und Einträge zu ändern, online wie
 * offline (docs/architecture.md, „Sync“).
 *
 * - `operations.*` baut Operationen in der Form, die der Server erwartet
 *   (mit `base` bzw. `seen` aus dem lokalen Stand des Eintrags).
 * - `createOperationQueue()` hält Operationen in einer Warteschlange, sendet
 *   sie der Reihe nach in Paketen (höchstens 200), wiederholt bei
 *   Netzfehlern mit Pause und meldet jedes Ergebnis an die Abonnenten.
 *   Doppelt gesendete Operationen führt der Server nur einmal aus (ID).
 * - Speicher der Warteschlange: vorerst im Arbeitsspeicher
 *   (`createMemoryStorage`). Schritt 13 ersetzt ihn durch IndexedDB mit
 *   derselben Schnittstelle `{ load(): Promise<op[]>, save(ops): Promise }`.
 *
 * Wer die Warteschlange anstößt (Start, `online`, App wieder sichtbar,
 * nach dem Anmelden), entscheidet die App; das Modul hängt sich an keine
 * Browser-Ereignisse und ist so ohne Browser testbar.
 */
import { api, ApiError } from './index.js';

export const MAX_BATCH = 200;

/** Pausen nach Netz- oder Serverfehlern, danach bleibt es beim letzten Wert. */
export const RETRY_DELAYS = [1000, 3000, 10000, 30000];

export const OPERATION_TYPES = Object.freeze({
    LIST_CREATE: 'list.create',
    LIST_UPDATE: 'list.update',
    LIST_COMPLETE: 'list.complete',
    LIST_REOPEN: 'list.reopen',
    LIST_DELETE: 'list.delete',
    ENTRY_ADD: 'entry.add',
    ENTRY_SET: 'entry.set',
    ENTRY_MOVE: 'entry.move',
    ENTRY_SET_STATUS: 'entry.setStatus',
    ENTRY_REMOVE: 'entry.remove',
    ENTRY_RESTORE: 'entry.restore',
    ENTRY_TRANSFER: 'entry.transfer',
    PRODUCT_CREATE: 'product.create'
});

const T = OPERATION_TYPES;

function uuid() {
    return crypto.randomUUID();
}

function quantityOf(value) {
    return { amount: value.amount, unit: value.unit ?? null };
}

/**
 * Operationen bauen. `entry` ist der lokale Stand des Eintrags
 * (`{ id, listId, quantity, note, ... }`) einschließlich eigener, noch nicht
 * übertragener Änderungen: Er ist der `base`-Wert, gegen den der Server
 * gleichzeitige Änderungen erkennt.
 */
export const operations = {
    createList({ listId = uuid(), name, shopDate = null, householdId = null }) {
        return { type: T.LIST_CREATE, listId, name, shopDate, householdId };
    },

    /** @param {{ name?: string, shopDate?: string|null }} fields */
    updateList(listId, fields) {
        return { type: T.LIST_UPDATE, listId, fields };
    },

    completeList(listId) {
        return { type: T.LIST_COMPLETE, listId };
    },

    reopenList(listId) {
        return { type: T.LIST_REOPEN, listId };
    },

    deleteList(listId) {
        return { type: T.LIST_DELETE, listId };
    },

    addEntry({ listId, entryId = uuid(), productId, quantity = { amount: 1, unit: null }, note = '', sortKey }) {
        return { type: T.ENTRY_ADD, listId, entryId, productId, quantity: quantityOf(quantity), note, sortKey };
    },

    /** @param {{ quantity?: { amount: number, unit?: string|null }, note?: string }} changes */
    setEntry(entry, changes) {
        const set = {};
        const base = {};

        if (changes.quantity !== undefined) {
            set.quantity = quantityOf(changes.quantity);
            base.quantity = quantityOf(entry.quantity);
        }

        if (changes.note !== undefined) {
            set.note = changes.note;
            base.note = entry.note ?? '';
        }

        return { type: T.ENTRY_SET, listId: entry.listId, entryId: entry.id, set, base };
    },

    moveEntry(entry, sortKey) {
        return { type: T.ENTRY_MOVE, listId: entry.listId, entryId: entry.id, sortKey };
    },

    /** Abhaken merkt sich die gerade sichtbare Menge (Regel „alter Haken“). */
    checkEntry(entry) {
        return {
            type: T.ENTRY_SET_STATUS,
            listId: entry.listId,
            entryId: entry.id,
            status: 'done',
            seen: quantityOf(entry.quantity)
        };
    },

    /** @param {'open'|'notGot'} status */
    setEntryStatus(entry, status) {
        if (status === 'done') {
            return operations.checkEntry(entry);
        }

        return { type: T.ENTRY_SET_STATUS, listId: entry.listId, entryId: entry.id, status };
    },

    removeEntry(entry) {
        return { type: T.ENTRY_REMOVE, listId: entry.listId, entryId: entry.id };
    },

    restoreEntry(entry) {
        return { type: T.ENTRY_RESTORE, listId: entry.listId, entryId: entry.id };
    },

    transferEntry(entry, targetListId) {
        return { type: T.ENTRY_TRANSFER, listId: entry.listId, entryId: entry.id, transferredTo: targetListId };
    },

    /**
     * Offline angelegtes Produkt (ohne Liste): vor dem `entry.add` einreihen,
     * das es benutzt. Idempotent über die Produkt-ID.
     *
     * @param {{ id?: string, name: string, category?: string|null, icon?: string|null,
     *   description?: string|null, packageSize?: string|null }} product
     */
    createProduct({ id = uuid(), ...fields }) {
        return { type: T.PRODUCT_CREATE, productId: id, product: fields };
    }
};

/** Warteschlange nur im Arbeitsspeicher (bis Schritt 13). */
export function createMemoryStorage() {
    let stored = [];

    return {
        async load() {
            return stored.map((op) => structuredClone(op));
        },

        async save(ops) {
            stored = ops.map((op) => structuredClone(op));
        }
    };
}

function sameKeys(a, b) {
    const keysA = Object.keys(a).sort();
    const keysB = Object.keys(b).sort();

    return keysA.length === keysB.length && keysA.every((key, index) => key === keysB[index]);
}

function sameValue(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function isRetryable(error) {
    return error instanceof ApiError && (error.status === 0 || error.status >= 500 || error.status === 429);
}

/**
 * @param {object} [options]
 * @param {{ sendOperations: Function, getList: Function }} [options.transport] Standard: `api`
 * @param {{ load: Function, save: Function }} [options.storage] Standard: im Arbeitsspeicher
 * @param {boolean} [options.autoFlush] nach `enqueue` sofort senden (Standard true)
 */
export function createOperationQueue({
    transport = api,
    storage = createMemoryStorage(),
    autoFlush = true,
    batchSize = MAX_BATCH,
    retryDelays = RETRY_DELAYS,
    setTimer = (callback, delay) => setTimeout(callback, delay),
    clearTimer = (timer) => clearTimeout(timer),
    now = () => new Date()
} = {}) {
    /** @type {{ op: object, sending: boolean }[]} */
    let items = [];
    let flushing = null;
    let retryTimer = null;
    let failures = 0;
    let lastError = null;
    let stopped = false;

    const resultListeners = new Set();
    const changeListeners = new Set();

    const ready = storage.load().then((ops) => {
        items = ops.map((op) => ({ op, sending: false }));
        notifyChange();
    });

    function notifyChange() {
        for (const listener of changeListeners) {
            listener(queue.pending());
        }
    }

    function emitResult(result, op) {
        for (const listener of resultListeners) {
            listener(result, op);
        }
    }

    function persist() {
        return storage.save(items.map((item) => item.op));
    }

    /**
     * Unverschickte Änderung desselben Eintrags zusammenfassen: mehrere
     * Plus-Tipps werden eine Mengenänderung mit dem ersten `base`; ergibt
     * sie wieder den Ausgangswert, fällt sie ganz weg.
     */
    function coalesce(op) {
        if (op.type !== T.ENTRY_SET && op.type !== T.ENTRY_MOVE) {
            return false;
        }

        const index = items.findLastIndex((item) => item.op.entryId === op.entryId);
        const last = index === -1 ? null : items[index];

        if (last === null || last.sending || last.op.type !== op.type) {
            return false;
        }

        if (op.type === T.ENTRY_MOVE) {
            last.op.sortKey = op.sortKey;
            return true;
        }

        if (!sameKeys(last.op.set, op.set)) {
            return false;
        }

        last.op.set = op.set;

        if (sameValue(last.op.set, last.op.base)) {
            items.splice(index, 1);
        }

        return true;
    }

    function scheduleRetry() {
        if (retryTimer !== null || stopped) {
            return;
        }

        const delay = retryDelays[Math.min(failures - 1, retryDelays.length - 1)];

        retryTimer = setTimer(() => {
            retryTimer = null;
            queue.flush();
        }, delay);
    }

    async function sendBatch() {
        const batch = items.filter((item) => !item.sending).slice(0, batchSize);

        if (batch.length === 0) {
            return false;
        }

        for (const item of batch) {
            item.sending = true;
        }

        let response;

        try {
            response = await transport.sendOperations(batch.map((item) => item.op));
        } catch (error) {
            for (const item of batch) {
                item.sending = false;
            }

            lastError = error;

            if (isRetryable(error)) {
                failures++;
                scheduleRetry();
            }

            notifyChange();

            return false;
        }

        failures = 0;
        lastError = null;

        const byId = new Map((response?.results ?? []).map((result) => [result.id, result]));
        const done = [];

        for (const item of batch) {
            const result = byId.get(item.op.id);

            if (result === undefined) {
                item.sending = false;
                continue;
            }

            done.push([result, item.op]);
        }

        items = items.filter((item) => !byId.has(item.op.id) || !item.sending);

        for (const item of items) {
            item.sending = false;
        }

        await persist();
        notifyChange();

        for (const [result, op] of done) {
            emitResult(result, op);
        }

        return done.length > 0;
    }

    const queue = {
        /** Geladen aus dem Speicher (nach Neustart: offene Operationen von vorher). */
        ready,

        /**
         * Operation einreihen; vergibt `id` und `createdAt`. Gibt die
         * eingereihte (oder mit einer vorigen zusammengefasste) Operation zurück.
         */
        async enqueue(op) {
            await ready;

            const full = { id: uuid(), createdAt: now().toISOString(), ...op };

            if (!coalesce(full)) {
                items.push({ op: full, sending: false });
            }

            await persist();
            notifyChange();

            if (autoFlush) {
                queue.flush();
            }

            return full;
        },

        /**
         * Sendet alles, was wartet, Paket für Paket. Läuft schon ein Senden,
         * kommt dessen Promise zurück.
         */
        flush() {
            if (flushing !== null) {
                return flushing;
            }

            stopped = false;

            if (retryTimer !== null) {
                clearTimer(retryTimer);
                retryTimer = null;
            }

            flushing = (async () => {
                await ready;

                while (await sendBatch()) {
                    // weiter, solange Pakete durchgehen
                }
            })().finally(() => {
                flushing = null;
            });

            return flushing;
        },

        /** Noch nicht bestätigte Operationen, optional nur einer Liste. */
        pending(listId = null) {
            return items
                .map((item) => item.op)
                .filter((op) => listId === null || op.listId === listId);
        },

        /** Für die Kennzeichnung „noch nicht übertragen“ am Eintrag. */
        isEntryPending(entryId) {
            return items.some((item) => item.op.entryId === entryId);
        },

        /** Letzter Fehler beim Senden (null nach Erfolg). */
        get lastError() {
            return lastError;
        },

        /**
         * Ergebnis jeder Operation: `listener(result, op)`; `result.status`
         * ist `applied`, `unchanged`, `conflict` oder `rejected`.
         * Gibt eine Funktion zum Abmelden zurück.
         */
        onResult(listener) {
            resultListeners.add(listener);
            return () => resultListeners.delete(listener);
        },

        /** Warteschlange geändert: `listener(pendingOps)`. */
        onChange(listener) {
            changeListeners.add(listener);
            return () => changeListeners.delete(listener);
        },

        /**
         * Abruf seit Revision: `{ list, revision, full, entries }`. Ohne
         * `since` der ganze Stand.
         */
        pull(listId, since = null) {
            return transport.getList(listId, since);
        },

        /** Abmelden: alles verwerfen, keine Wiederholung mehr. */
        async clear() {
            stopped = true;

            if (retryTimer !== null) {
                clearTimer(retryTimer);
                retryTimer = null;
            }

            items = [];
            await persist();
            notifyChange();
        }
    };

    return queue;
}

/**
 * Wendet Einträge aus `pull()` auf einen lokalen Stand an (Map id → Eintrag).
 * Entfernte Einträge und Verweise (`mergedInto`) fallen heraus; bei
 * `full: true` ersetzt die Antwort den Stand ganz.
 *
 * @param {Map<string, object>} entries
 * @param {{ full: boolean, entries: object[] }} response
 * @returns {Map<string, object>}
 */
export function applyPulledEntries(entries, response) {
    const result = response.full ? new Map() : new Map(entries);

    for (const entry of response.entries) {
        if (entry.removed || entry.mergedInto) {
            result.delete(entry.id);
            continue;
        }

        result.set(entry.id, entry);
    }

    return result;
}
