/**
 * Einkaufslisten: Übersicht, geladene Listen und alle Änderungen.
 *
 * Geschrieben wird ausschließlich über Operationen (`api/operations.js`):
 * eine Warteschlange für die ganze App, die sofort sendet und bei
 * Netzfehlern wiederholt. Angezeigt wird der letzte Serverstand mit den
 * eigenen, noch nicht bestätigten Operationen darüber (`utils/listState.js`),
 * deshalb erscheint jede Änderung sofort und bleibt stehen.
 *
 * - Übersicht: `GET /lists` (Zähler, Vorschau); für geladene Listen rechnet
 *   die App Zähler und Vorschau selbst aus dem lokalen Stand.
 * - Eine Liste: `GET /lists/{id}`, danach „seit Revision N“, sobald eine
 *   Antwort eine Lücke in den Revisionen zeigt (andere haben geändert).
 * - Live-Abgleich: Die offene Liste fragt alle 5 s „seit N“ ab
 *   (`refreshList`), die Übersicht alle 30 s `GET /lists` (`loadLists`), nur
 *   bei sichtbarer App (`useLivePoll`). Die Übersicht vergleicht dabei die
 *   Revision jeder geladenen Liste und holt geänderte nach; nicht mehr
 *   zugängliche Listen (entfernt, Haushalt aufgelöst) fallen heraus.
 * - Abgelehnte Operationen meldet ein Toast; ihr Effekt verschwindet, weil
 *   sie nicht mehr in der Warteschlange stehen.
 * - Konflikte (gleichzeitig verschieden geändert, alter Haken, inzwischen
 *   entfernt) sammelt `conflicts`; die App fragt nach (`ConflictSheet`) und
 *   schickt die Antwort mit `resolveConflict`. Nichts wird still
 *   überschrieben.
 *
 * IndexedDB (Schritt 13) setzt hier an.
 */
import { computed, reactive, ref, shallowRef } from 'vue';
import { api } from '@/api/index.js';
import { createOperationQueue, operations, OPERATION_TYPES as T } from '@/api/operations.js';
import { showToast } from './useToast.js';
import { onLogout, setSessionHousehold, useSession } from './useSession.js';
import { addLocalProduct, confirmProduct, dropLocalProduct } from './useProducts.js';
import { suggestForName } from '@/products/suggest.js';
import {
    activeEntryOf,
    appendKey,
    applyOperations,
    changesState,
    countEntries,
    EMPTY_COUNTS,
    isVisible,
    mergeEntries,
    planMove,
    sortedEntries,
    translateMove,
    upsertEntry
} from '@/utils/listState.js';
import { formatQuantity, MAX_AMOUNT } from '@/utils/quantity.js';
import { keyBetween, keysBetween } from '@/utils/sortKey.js';
import { planTakeOver } from '@/utils/takeOver.js';
import { t } from '@/i18n/index.js';

const PREVIEW_SIZE = 4;

const queue = createOperationQueue();

/** Noch nicht bestätigte Operationen (alle Listen). */
const pendingOps = shallowRef([]);

/** Senden klappt gerade nicht (offline, Server weg): „noch nicht übertragen“ zeigen. */
const syncTrouble = ref(false);

/** Übersicht vom Server: Listen mit `role`, `counts`, `preview`. */
const overview = reactive({
    status: 'idle', // idle · loading · ready · error
    lists: []
});

/**
 * Geladene Listen: id → { status, list, revision, entries, reason }.
 * status: loading · ready · error · missing (gelöscht oder kein Zugriff);
 * `reason` bei missing: `no_access` (Freigabe entzogen, Haushalt verlassen),
 * `list_deleted`, `list_not_found`, `left` (als Gast verlassen) oder null
 * (fehlt in der Übersicht).
 */
const details = shallowRef({});

const pullsWanted = new Set();

/** Laufende Abrufe je Liste: nie zwei gleichzeitig für dieselbe Liste. */
const pulling = new Map();

/**
 * Offene Konflikte, ältester zuerst: { key, listId, entryId, productId, op,
 * conflict }. `entryId` ist der Eintrag auf dem Server (bei Verweisen das
 * Ziel), `conflict` wie in der Antwort (`type` quantity · note · checkedLess
 * · removed).
 */
const conflicts = shallowRef([]);

const householdListeners = new Set();

const { household: sessionHousehold } = useSession();

function setDetail(listId, detail) {
    details.value = { ...details.value, [listId]: Object.freeze(detail) };
}

function dropDetail(listId) {
    if (listId in details.value) {
        const next = { ...details.value };

        delete next[listId];
        details.value = next;
    }
}

function patchDetail(listId, change) {
    const detail = details.value[listId];

    if (detail) {
        setDetail(listId, { ...detail, ...change });
    }
}

function pendingFor(listId) {
    return pendingOps.value.filter((op) => op.listId === listId);
}

function hasPendingCreate(listId) {
    return pendingOps.value.some((op) => op.type === T.LIST_CREATE && op.listId === listId);
}

/** Liste ist nicht mehr zugänglich: aus der Übersicht nehmen (ohne auf deren Abfrage zu warten). */
function dropFromOverview(listId) {
    if (overview.lists.some((item) => item.id === listId)) {
        overview.lists = overview.lists.filter((item) => item.id !== listId);
    }
}

function upsertOverview(list) {
    const index = overview.lists.findIndex((item) => item.id === list.id);

    if (index === -1) {
        overview.lists.push({ role: 'owner', counts: { ...EMPTY_COUNTS }, preview: [], ...list });
        return;
    }

    const known = overview.lists[index];

    // die Abfrage liefert meist denselben Kopf: dann nichts neu rendern
    if (Object.keys(list).every((key) => JSON.stringify(known[key]) === JSON.stringify(list[key]))) {
        return;
    }

    overview.lists[index] = { ...known, ...list };
}

function addConflicts(result, op) {
    const entryId = result.mergedInto ?? result.entry?.id ?? op.entryId ?? null;
    const productId = result.entry?.productId ?? op.productId ?? null;
    const added = (result.conflicts ?? []).map((conflict, index) => ({
        key: op.id + ':' + index,
        listId: op.listId,
        entryId,
        productId,
        op,
        conflict
    }));

    if (added.length > 0) {
        conflicts.value = [...conflicts.value.filter((item) => !added.some((next) => next.key === item.key)), ...added];
    }
}

/** Gewünschtes Nachladen ausführen, sobald für die Liste nichts mehr wartet. */
function pullWhenIdle() {
    for (const listId of [...pullsWanted]) {
        if (!pendingOps.value.some((op) => op.listId === listId)) {
            pullsWanted.delete(listId);
            loadList(listId, { since: true }).catch((error) => console.error(error));
        }
    }
}

queue.onChange((ops) => {
    pendingOps.value = ops;
    syncTrouble.value = ops.length > 0 && queue.lastError !== null;
    pullWhenIdle();
});

queue.onResult((result, op) => {
    if (op.type === T.PRODUCT_CREATE) {
        if (result.status === 'rejected') {
            console.warn('Produkt abgelehnt', result.error);
            dropLocalProduct(op.productId);
            showToast(t('addProducts.createRejected', { name: op.product?.name ?? '' }), { icon: 'notgot' });
        } else if (result.product) {
            confirmProduct(result.product);
        }

        return;
    }

    if (!op.listId) {
        return;
    }

    if (result.status === 'rejected') {
        console.warn('Operation abgelehnt', op.type, result.error);
        showToast(t('lists.syncRejected'), { icon: 'notgot' });

        if (result.error === 'list_deleted' || result.error === 'no_access') {
            patchDetail(op.listId, { status: 'missing', reason: result.error });
        }

        return;
    }

    if (result.status === 'conflict') {
        addConflicts(result, op);
    }

    const detail = details.value[op.listId];

    if (result.list) {
        upsertOverview(result.list);
    }

    if (!detail || detail.status !== 'ready') {
        if (op.type === T.LIST_CREATE && result.list) {
            setDetail(op.listId, {
                status: 'ready',
                list: result.list,
                revision: result.revision ?? result.list.revision ?? 1,
                entries: new Map()
            });
        }

        return;
    }

    const change = {};

    if (result.list) {
        change.list = { ...detail.list, ...result.list };
    }

    if (result.entry) {
        change.entries = upsertEntry(detail.entries, result.entry);
    }

    if (typeof result.revision === 'number') {
        if (result.revision === detail.revision + 1) {
            change.revision = result.revision;
        } else if (result.revision > detail.revision) {
            // dazwischen hat jemand anderes geändert: nachladen, sobald nichts mehr wartet
            pullsWanted.add(op.listId);
            queueMicrotask(pullWhenIdle);
        }
    }

    setDetail(op.listId, { ...detail, ...change });
});

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => queue.flush());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            queue.flush();
        }
    });
}

onLogout(() => {
    queue.clear();
    overview.status = 'idle';
    overview.lists = [];
    details.value = {};
    pullsWanted.clear();
    conflicts.value = [];
});

/**
 * Haushalt hat sich laut Übersicht geändert (entfernt, aufgelöst, Rolle):
 * `listener(household)`; `useHousehold` lädt dann seinen Stand neu.
 */
export function onHouseholdChange(listener) {
    householdListeners.add(listener);

    return () => householdListeners.delete(listener);
}

function noteHousehold(household) {
    const known = sessionHousehold.value;
    const next = household ?? null;

    if ((known?.id ?? null) === (next?.id ?? null) && (known?.role ?? null) === (next?.role ?? null)
        && (known?.name ?? null) === (next?.name ?? null)) {
        return;
    }

    setSessionHousehold(next);

    for (const listener of householdListeners) {
        listener(next);
    }
}

/**
 * Geladene Listen mit der Übersicht abgleichen: geänderte (höhere Revision)
 * „seit N“ nachladen, nicht mehr zugängliche verwerfen. `knownBefore`: nur
 * Listen, die schon vor der Anfrage bekannt waren, können fehlen (eine eben
 * angelegte steht vielleicht noch nicht in der Antwort).
 */
function syncLoadedLists(lists, knownBefore) {
    const byId = new Map(lists.map((item) => [item.id, item]));

    for (const [listId, detail] of Object.entries(details.value)) {
        if (detail.status !== 'ready') {
            continue;
        }

        const server = byId.get(listId);

        if (!server) {
            if (knownBefore.has(listId) && !hasPendingCreate(listId)) {
                // eine geteilte Liste fehlt: Freigabe entzogen (gelöscht kann sie auch sein)
                const reason = detail.list?.role === 'guest' ? 'no_access' : null;

                setDetail(listId, { status: 'missing', list: null, revision: 0, entries: null, reason });
            }

            continue;
        }

        if (server.revision > detail.revision) {
            pullsWanted.add(listId);
        }
    }

    pullWhenIdle();
}

/**
 * Übersicht laden (ETag: unverändert kostet fast nichts). Auch der
 * Live-Abgleich der Übersicht (alle 30 s).
 */
export async function loadLists() {
    if (overview.status !== 'ready') {
        overview.status = 'loading';
    }

    const knownBefore = new Set(Object.entries(details.value)
        .filter(([, detail]) => detail.status === 'ready' && detail.list && detail.revision > 0)
        .map(([listId]) => listId));

    try {
        const data = await api.getLists();
        const lists = data.lists ?? [];

        if (JSON.stringify(lists) !== JSON.stringify(overview.lists)) {
            overview.lists = lists;
        }

        overview.status = 'ready';

        if ('household' in data) {
            noteHousehold(data.household);
        }

        syncLoadedLists(lists, knownBefore);
    } catch (error) {
        if (overview.status !== 'ready') {
            overview.status = 'error';
        }

        throw error;
    }
}

/**
 * Eine Liste laden: ganz oder mit `since` nur Änderungen seit dem bekannten
 * Stand.
 */
export function loadList(listId, { since = false } = {}) {
    const running = pulling.get(listId);

    if (running) {
        return running;
    }

    const promise = pullList(listId, since).finally(() => pulling.delete(listId));

    pulling.set(listId, promise);

    return promise;
}

async function pullList(listId, since) {
    const detail = details.value[listId];
    const known = detail?.status === 'ready';

    if (!known) {
        setDetail(listId, { status: 'loading', list: detail?.list ?? null, revision: 0, entries: null });
    }

    try {
        const response = await queue.pull(listId, since && known ? detail.revision : null);
        const current = details.value[listId];
        const ready = current?.status === 'ready';

        // inzwischen weiter (Antwort auf eine eigene Operation): diese Antwort ist älter
        if (ready && response.revision < current.revision) {
            return;
        }

        if (!ready || changesState(current, response)) {
            setDetail(listId, {
                status: 'ready',
                list: response.list,
                revision: response.revision,
                entries: mergeEntries(ready ? current.entries : null, response)
            });
        }

        upsertOverview(response.list);
    } catch (error) {
        if (error?.code === 'list_not_found' && hasPendingCreate(listId)) {
            // gerade angelegt, noch nicht beim Server: leer anzeigen
            setDetail(listId, { status: 'ready', list: null, revision: 0, entries: new Map() });
            return;
        }

        if (error?.code === 'list_not_found' || error?.code === 'list_deleted' || error?.code === 'no_access') {
            setDetail(listId, { status: 'missing', list: null, revision: 0, entries: null, reason: error.code });
            dropFromOverview(listId);
            return;
        }

        if (!known) {
            setDetail(listId, { status: 'error', list: null, revision: 0, entries: null });
        }

        throw error;
    }
}

/**
 * Live-Abgleich der offenen Liste (alle 5 s): Änderungen „seit N“. Noch nicht
 * geladene Listen lädt die Ansicht selbst.
 */
export function refreshList(listId) {
    const detail = details.value[listId];

    if (detail?.status !== 'ready' || (detail.revision === 0 && hasPendingCreate(listId))) {
        return Promise.resolve();
    }

    return loadList(listId, { since: true });
}

/** Lokaler Stand einer Liste (Server + eigene Operationen) oder null. */
function viewOf(listId) {
    const detail = details.value[listId];
    const server = overview.lists.find((item) => item.id === listId) ?? null;
    const loaded = detail?.status === 'ready';
    const base = {
        list: loaded ? detail.list ?? null : server,
        entries: loaded ? detail.entries : null
    };
    const state = applyOperations(base, pendingFor(listId), listId);

    return { state, server, detail };
}

function previewOf(entries) {
    return sortedEntries(entries, 'open').slice(0, PREVIEW_SIZE).map((entry) => entry.productId);
}

/** Alle bekannten, nicht gelöschten Listen im lokalen Stand, mit Zählern und Vorschau. */
const knownLists = computed(() => {
    const ids = new Set(overview.lists.map((item) => item.id));

    for (const op of pendingOps.value) {
        if (op.type === T.LIST_CREATE) {
            ids.add(op.listId);
        }
    }

    for (const [listId, detail] of Object.entries(details.value)) {
        if (detail.status === 'ready' && detail.list) {
            ids.add(listId);
        }
    }

    const result = [];

    for (const listId of ids) {
        const { state, server } = viewOf(listId);

        if (!state.list || state.list.deleted) {
            continue;
        }

        const counts = state.entries ? countEntries(state.entries) : (server?.counts ?? { ...EMPTY_COUNTS });
        const preview = state.entries ? previewOf(state.entries) : (server?.preview ?? []);

        result.push({ ...state.list, counts, preview });
    }

    return result;
});

/**
 * Aktive Listen für die Übersicht, neueste zuerst (nach Anlegedatum, damit
 * sich die Reihenfolge beim Abhaken nicht ändert).
 */
const activeLists = computed(() => knownLists.value
    .filter((list) => list.status === 'active')
    .sort((a, b) => String(b.createdAt ?? '').localeCompare(String(a.createdAt ?? '')) || (a.id < b.id ? -1 : 1)));

/** Archiv: abgeschlossene Einkäufe, zuletzt abgeschlossen zuerst. */
const archivedLists = computed(() => knownLists.value
    .filter((list) => list.status === 'completed')
    .sort((a, b) => String(b.completedAt ?? '').localeCompare(String(a.completedAt ?? '')) || (a.id < b.id ? -1 : 1)));

const pendingEntryIds = computed(() => new Set(pendingOps.value.map((op) => op.entryId).filter(Boolean)));

/**
 * Eine Liste für die Ansicht: `list`, `status`, Einträge nach Zustand in
 * Listenreihenfolge und Zähler.
 */
export function listView(listId) {
    return computed(() => {
        const { state, detail } = viewOf(listId);
        const status = state.list?.deleted ? 'missing' : (detail?.status ?? 'idle');
        const entries = state.entries;

        return {
            status,
            reason: status === 'missing' ? (detail?.reason ?? (state.list?.deleted ? 'list_deleted' : null)) : null,
            list: state.list,
            entries,
            open: sortedEntries(entries, 'open'),
            notGot: sortedEntries(entries, 'notGot'),
            done: sortedEntries(entries, 'done'),
            counts: entries ? countEntries(entries) : { ...EMPTY_COUNTS }
        };
    });
}

/** Einträge, deren Änderung noch nicht beim Server ist, solange Senden scheitert. */
export function isEntryUnsynced(entryId) {
    return syncTrouble.value && pendingEntryIds.value.has(entryId);
}

function enqueue(op) {
    return queue.enqueue(op);
}

function currentEntries(listId) {
    return viewOf(listId).state.entries;
}

/**
 * Neue Liste, persönlich oder (mit `householdId`) für den Haushalt. Gibt die
 * ID zurück; die Liste steht sofort in der Übersicht, auch bevor der Server
 * antwortet.
 *
 * @param {{ name: string, shopDate?: string|null, householdId?: string|null }} fields
 */
export async function createList({ name, shopDate = null, householdId = null }) {
    const op = operations.createList({ listId: crypto.randomUUID(), name, shopDate, householdId });

    await enqueue(op);

    return op.listId;
}

/** Name und/oder Datum ändern; nur geänderte Felder. */
export function updateList(listId, fields) {
    return enqueue(operations.updateList(listId, fields));
}

export function deleteList(listId) {
    return enqueue(operations.deleteList(listId));
}

/**
 * Menge eines Produkts auf der Liste setzen („Produkte hinzufügen“):
 * 0 → noch nicht drauf: nichts, sonst entfernen; > 0 → noch nicht drauf:
 * neuer Eintrag am Ende, sonst Menge des offenen Eintrags (Einheit bleibt).
 * Erledigte und nicht bekommene Einträge zählen nicht als „drauf“ (neuer
 * Eintrag, „noch mal Milch“). Wirkt sofort auf den angezeigten Stand, auch
 * bei mehreren Tipps im selben Takt: kein Produkt doppelt.
 *
 * @returns {string|null} ID des Eintrags, null wenn keiner (mehr) da ist
 */
export function setProductQuantity(listId, productId, amount) {
    const entries = currentEntries(listId) ?? new Map();
    const existing = activeEntryOf(entries, productId);
    const target = Math.min(MAX_AMOUNT, Math.max(0, amount));

    if (!existing) {
        if (target <= 0) {
            return null;
        }

        const op = operations.addEntry({
            listId,
            productId,
            quantity: { amount: target, unit: null },
            sortKey: appendKey(entries)
        });

        enqueue(op).catch((error) => console.error(error));

        return op.entryId;
    }

    if (target <= 0) {
        enqueue(operations.removeEntry(existing)).catch((error) => console.error(error));

        return null;
    }

    if (target !== existing.quantity.amount) {
        enqueue(operations.setEntry(existing, {
            quantity: { amount: target, unit: existing.quantity.unit }
        })).catch((error) => console.error(error));
    }

    return existing.id;
}

/**
 * Unbekannten Namen als neues Produkt anlegen und gleich mit Menge 1 auf die
 * Liste setzen. Beides als Operation (`product.create` vor `entry.add`), das
 * Produkt steht sofort im Katalog.
 *
 * @returns {string} ID des neuen Produkts
 */
export function createProductOnList(listId, name) {
    // Kategorie und Icon aus dem Namen vorschlagen, wie beim Anlegen im Formular
    const suggestion = suggestForName(name);
    const op = operations.createProduct({
        name: name.trim(),
        category: suggestion?.category ?? null,
        icon: suggestion?.icon ?? null
    });

    addLocalProduct({ id: op.productId, ...op.product });
    enqueue(op).catch((error) => console.error(error));
    setProductQuantity(listId, op.productId, 1);

    return op.productId;
}

/** Menge (mit Einheit) und/oder Notiz ändern; `entry` ist der angezeigte Stand. */
export function setEntry(entry, changes) {
    return enqueue(operations.setEntry(entry, changes));
}

export function checkEntry(entry) {
    return enqueue(operations.checkEntry(entry));
}

export function uncheckEntry(entry) {
    return enqueue(operations.setEntryStatus(entry, 'open'));
}

/** „Nicht bekommen“ (eigener Zustand, Abschnitt unter den offenen). */
export function markNotGot(entry) {
    return enqueue(operations.setEntryStatus(entry, 'notGot'));
}

/** „Nicht bekommen“ zurücknehmen: wieder offen an der alten Stelle. */
export function markOpen(entry) {
    return enqueue(operations.setEntryStatus(entry, 'open'));
}

export function removeEntry(entry) {
    return enqueue(operations.removeEntry(entry));
}

export function restoreEntry(entry) {
    return enqueue(operations.restoreEntry(entry));
}

/**
 * Offenen Eintrag von Position `from` nach `to` bringen (Indizes in den
 * offenen Einträgen der Liste).
 */
export async function moveOpenEntry(listId, from, to) {
    const open = sortedEntries(currentEntries(listId), 'open');

    for (const { entry, sortKey } of planMove(open, from, to)) {
        await enqueue(operations.moveEntry(entry, sortKey));
    }
}

/**
 * Ablegen nach dem Ziehen: `frozenIds` ist die Reihenfolge, die während des
 * Ziehens zu sehen war. Inzwischen eingetroffene Änderungen anderer bleiben
 * erhalten; der Eintrag landet hinter demselben Nachbarn wie beim Ablegen.
 */
export async function moveOpenEntryFrom(listId, frozenIds, from, to) {
    const open = sortedEntries(currentEntries(listId), 'open');
    const move = translateMove(open, frozenIds, from, to);

    if (!move) {
        return;
    }

    for (const { entry, sortKey } of planMove(open, move.from, move.to)) {
        await enqueue(operations.moveEntry(entry, sortKey));
    }
}

/** Eintrag im angezeigten Stand (auch entfernte), z. B. für „Rückgängig“. */
export function findEntry(listId, entryId) {
    return currentEntries(listId)?.get(entryId) ?? null;
}

/** Einträge einer Liste im angezeigten Stand (null, solange nicht geladen). */
export function listEntries(listId) {
    return currentEntries(listId);
}

/** Liste laden, falls ihre Einträge noch fehlen (z. B. Zielliste beim Übernehmen). */
export async function ensureListLoaded(listId) {
    if (details.value[listId]?.status === 'ready') {
        return;
    }

    await loadList(listId);
}

/** Neue Liste ohne Server-Antwort sofort mit leeren Einträgen zeigen. */
function startLocalList(fields) {
    const op = operations.createList({ listId: crypto.randomUUID(), ...fields });

    setDetail(op.listId, { status: 'ready', list: null, revision: 0, entries: new Map() });

    return op;
}

/**
 * Einträge in eine andere Liste übernehmen (`entry.takeOver` je Eintrag),
 * in der Zielliste hinter allen Einträgen in der bisherigen Reihenfolge.
 * Im Ursprung bleiben sie „nicht bekommen“ mit Verweis auf die Zielliste
 * (`entry.transfer`); offene werden dabei „nicht bekommen“.
 *
 * Die Zielliste muss geladen sein (`ensureListLoaded`), damit die App bei
 * anderer Einheit vorher fragen kann; `answers` enthält je Eintrags-ID
 * `keep` · `replace` · `note` (Standard `note`: nichts geht verloren).
 *
 * @param {object[]} entries Einträge der Ursprungsliste (angezeigter Stand)
 * @param {{ targetListId?: string, newList?: { name: string, shopDate?: string|null }, answers?: object }} target
 * @returns {Promise<{ listId: string, count: number, summed: object[], plan: object[] }>}
 */
export async function takeOverEntries(entries, { targetListId = null, newList = null, answers = {} }) {
    let listId = targetListId;

    if (newList) {
        const create = startLocalList({
            name: newList.name,
            shopDate: newList.shopDate ?? null,
            householdId: newList.householdId ?? null
        });

        listId = create.listId;
        await enqueue(create);
    }

    const plan = planTakeOver(currentEntries(listId), entries);
    let sortKey = appendKey(currentEntries(listId));

    for (const entry of entries) {
        await enqueue(operations.takeOverEntry(entry, {
            listId,
            sortKey,
            onUnitMismatch: answers[entry.id] ?? 'note',
            quantityText: formatQuantity(entry.quantity.amount, entry.quantity.unit)
        }));
        sortKey = keyBetween(sortKey, null);
    }

    for (const entry of entries) {
        if (entry.status === 'open') {
            await enqueue(operations.setEntryStatus(entry, 'notGot'));
        }

        await enqueue(operations.transferEntry(entry, listId));
    }

    return {
        listId,
        count: entries.length,
        summed: plan.filter((item) => item.kind === 'sum').map((item) => item.entry),
        plan
    };
}

/** Einkauf abschließen: ins Archiv, bleibt einsehbar und lässt sich wieder öffnen. */
export function completeList(listId) {
    return enqueue(operations.completeList(listId));
}

export function reopenList(listId) {
    return enqueue(operations.reopenList(listId));
}

/**
 * Neue Liste aus einem früheren Einkauf: die gewählten Produkte mit ihren
 * Mengen in derselben Reihenfolge, alle offen, ohne Notizen.
 *
 * @param {{ name: string, shopDate?: string|null, householdId?: string|null }} fields
 * @param {{ productId: string, quantity: { amount: number, unit: string|null } }[]} items in Listenreihenfolge
 * @returns {Promise<string>} ID der neuen Liste
 */
export async function createListFrom(fields, items) {
    const create = startLocalList({
        name: fields.name,
        shopDate: fields.shopDate ?? null,
        householdId: fields.householdId ?? null
    });
    const keys = keysBetween(null, null, items.length);

    await enqueue(create);

    for (const [index, item] of items.entries()) {
        await enqueue(operations.addEntry({
            listId: create.listId,
            productId: item.productId,
            quantity: item.quantity,
            sortKey: keys[index]
        }));
    }

    return create.listId;
}

/**
 * Neue Liste aus dem Sheet „Neue Liste“: leer oder mit den gewählten
 * Produkten eines früheren Einkaufs (`items`), persönlich oder im Haushalt.
 *
 * @param {{ name: string, shopDate?: string|null, householdId?: string|null, items?: object[]|null }} fields
 * @returns {Promise<string>} ID der neuen Liste
 */
export function createListWith({ name, shopDate = null, householdId = null, items = null }) {
    return items
        ? createListFrom({ name, shopDate, householdId }, items)
        : createList({ name, shopDate, householdId });
}

/**
 * Persönliche Liste in den Haushalt verschieben (REST, nur der Besitzer).
 * Die ID bleibt, ausstehende Operationen gelten weiter.
 */
export async function moveListToHousehold(listId) {
    const { list } = await api.moveListToHousehold(listId);
    const detail = details.value[listId];

    upsertOverview(list);

    if (detail?.status === 'ready') {
        const change = { list: { ...detail.list, ...list } };

        if (list.revision === detail.revision + 1) {
            change.revision = list.revision;
        } else if (list.revision > detail.revision) {
            pullsWanted.add(listId);
            queueMicrotask(pullWhenIdle);
        }

        patchDetail(listId, change);
    }

    return list;
}

/**
 * Liste kopieren: `to` 'household' (persönliche Liste in den Haushalt) oder
 * 'personal' (Haushaltsliste als persönliche Kopie, z. B. vor dem Austritt).
 * Das Original bleibt. Gibt die neue Liste zurück.
 */
export async function copyListTo(listId, to) {
    const { list } = await api.copyList(listId, crypto.randomUUID(), to);

    upsertOverview(list);
    loadLists().catch((error) => console.error(error));

    return list;
}

/**
 * Einladung zu einer Liste annehmen (Link): Danach steht sie in der
 * Übersicht. Gibt `{ status: 'accepted' | 'already', list }` zurück.
 */
export async function acceptListInvite(token) {
    const result = await api.acceptInvite(token);

    upsertOverview(result.list);

    const detail = details.value[result.list.id];

    // vorher ohne Zugriff geladen (z. B. nach einem Entzug): neu laden lassen
    if (detail && detail.status !== 'ready') {
        dropDetail(result.list.id);
    }

    loadLists().catch((error) => console.error(error));

    return result;
}

/**
 * Als Gast eine geteilte Liste verlassen: Sie verschwindet aus der
 * Übersicht; wieder dabei nur mit einem neuen Link.
 */
export async function leaveSharedList(listId, userId) {
    await api.removeListShare(listId, userId);

    dropFromOverview(listId);

    if (details.value[listId]) {
        setDetail(listId, { status: 'missing', list: null, revision: 0, entries: null, reason: 'left' });
    }
}

/**
 * Nach Beitritt, Wechsel, Austritt oder Auflösen: geladene Listen verwerfen
 * (manche sind nicht mehr zugänglich, andere neu) und die Übersicht neu laden.
 */
export function reloadAfterHouseholdChange() {
    details.value = {};
    pullsWanted.clear();

    return loadLists();
}

/**
 * Antwort auf einen Konflikt (`ConflictSheet`):
 *
 * - quantity / note: `yours` schickt die eigene Änderung erneut, mit dem
 *   jetzigen Stand als `base`; `theirs` lässt es, wie es ist.
 * - checkedLess: `done` hakt mit der jetzigen Menge ab; `open` lässt offen.
 * - removed: `restore` holt den Eintrag zurück und wiederholt die Änderung;
 *   `keep` lässt ihn entfernt.
 *
 * @param {object} item aus `conflicts`
 * @param {'yours'|'theirs'|'done'|'open'|'restore'|'keep'} choice
 */
export async function resolveConflict(item, choice) {
    conflicts.value = conflicts.value.filter((other) => other.key !== item.key);

    const entry = findEntry(item.listId, item.entryId);

    if (!entry) {
        return;
    }

    const { conflict, op } = item;

    if (conflict.type === 'quantity' && choice === 'yours') {
        await enqueue(operations.setEntry(entry, { quantity: conflict.yours }));
    } else if (conflict.type === 'note' && choice === 'yours') {
        await enqueue(operations.setEntry(entry, { note: conflict.yours }));
    } else if (conflict.type === 'checkedLess' && choice === 'done') {
        await enqueue(operations.checkEntry(entry));
    } else if (conflict.type === 'removed' && choice === 'restore') {
        await enqueue(operations.restoreEntry(entry));
        await repeatOperation(op, findEntry(item.listId, item.entryId) ?? entry);
    }
}

/** Änderung nach „wiederherstellen“ noch einmal, als neue Operation. */
function repeatOperation(op, entry) {
    switch (op.type) {
        case T.ENTRY_SET:
            return enqueue(operations.setEntry(entry, op.set));
        case T.ENTRY_MOVE:
            return enqueue(operations.moveEntry(entry, op.sortKey));
        case T.ENTRY_SET_STATUS:
            return enqueue(operations.setEntryStatus(entry, op.status));
        case T.ENTRY_TRANSFER:
            return enqueue(operations.transferEntry(entry, op.transferredTo));
        default:
            return Promise.resolve();
    }
}

/** Konflikt ohne Antwort schließen: Es bleibt beim Stand in der Liste. */
export function dismissConflict(item) {
    conflicts.value = conflicts.value.filter((other) => other.key !== item.key);
}

export { isVisible, activeEntryOf as activeEntryOfProduct };

export function useLists() {
    return {
        overview,
        activeLists,
        archivedLists,
        pendingOps,
        syncTrouble,
        conflicts
    };
}
