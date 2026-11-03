/**
 * Lokaler Speicher der App in IndexedDB: eigenes kleines Modul ohne
 * Bibliothek (docs/architecture.md, „Offline“).
 *
 * Zwei Bereiche (Object Stores):
 *
 * - `ops`: die Warteschlange der Operationen, ein Datensatz je Operation
 *   (Schlüssel = Operations-ID, dazu eine laufende Nummer für die
 *   Reihenfolge). `queueStorage` erfüllt die Schnittstelle von
 *   `createOperationQueue` (`{ load(), save(ops) }`) und schreibt bei jedem
 *   `save` nur, was sich geändert hat. Schreibvorgänge laufen in der
 *   Reihenfolge der Aufrufe (eine Transaktion je `save`, IndexedDB führt
 *   Transaktionen auf demselben Store nacheinander aus): Der zuletzt
 *   gemeldete Stand gewinnt immer, auch bei schnellen Mehrfachtipps.
 * - `data`: Schlüssel/Wert für die Offline-Kopien: `me` (Benutzer),
 *   `overview` (Listenübersicht), `list:<id>` (aktive Listen mit Einträgen),
 *   `orphan:<id>` (nicht übertragene Änderungen einer entzogenen Liste),
 *   `products` (Katalog), `suggestions`, dazu `meta:user`.
 *
 * Alles gehört einem Benutzer (`bindUser`): Meldet sich ein anderer an,
 * wird vorher alles gelöscht; Abmelden löscht ebenfalls (`wipe`). Die
 * Warteschlange lädt erst, wenn der Benutzer feststeht, damit nie
 * Operationen eines anderen gesendet werden.
 *
 * Ohne IndexedDB (Node-Tests, gesperrter Speicher) arbeitet dasselbe Modul
 * im Arbeitsspeicher; die App läuft dann, nur ohne Offline-Start.
 */

export const DB_NAME = 'piklis';
const DB_VERSION = 1;
export const STORES = Object.freeze(['ops', 'data']);

const USER_KEY = 'meta:user';

function clone(value) {
    return value === undefined ? undefined : structuredClone(value);
}

/**
 * Speicher im Arbeitsspeicher mit derselben Schnittstelle wie IndexedDB
 * (für Tests und als Rückfall).
 */
export function createMemoryBackend() {
    const stores = new Map(STORES.map((name) => [name, new Map()]));

    return {
        kind: 'memory',

        async get(store, key) {
            return clone(stores.get(store).get(key));
        },

        /** @returns {Promise<[string, any][]>} nach Schlüssel sortiert */
        async getAll(store) {
            return [...stores.get(store).entries()]
                .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
                .map(([key, value]) => [key, clone(value)]);
        },

        async write(store, puts = [], deletes = []) {
            const target = stores.get(store);

            for (const key of deletes) {
                target.delete(key);
            }

            for (const [key, value] of puts) {
                target.set(key, clone(value));
            }
        },

        async clear() {
            for (const target of stores.values()) {
                target.clear();
            }
        }
    };
}

function requestResult(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transactionDone(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error ?? new Error('Transaction aborted'));
    });
}

/**
 * IndexedDB mit der Schnittstelle von `createMemoryBackend`. Lässt sich die
 * Datenbank nicht öffnen, arbeitet es im Arbeitsspeicher weiter.
 *
 * @param {IDBFactory|undefined} factory
 */
export function createIndexedDbBackend(factory = globalThis.indexedDB, name = DB_NAME) {
    if (!factory) {
        return createMemoryBackend();
    }

    let opening = null;
    let fallback = null;

    function open() {
        opening ??= new Promise((resolve, reject) => {
            const request = factory.open(name, DB_VERSION);

            request.onupgradeneeded = () => {
                const db = request.result;

                for (const store of STORES) {
                    if (!db.objectStoreNames.contains(store)) {
                        db.createObjectStore(store);
                    }
                }
            };
            request.onsuccess = () => {
                const db = request.result;

                // neue Version in einem anderen Tab: Platz machen, beim nächsten Zugriff neu öffnen
                db.onversionchange = () => {
                    db.close();
                    opening = null;
                };
                resolve(db);
            };
            request.onerror = () => reject(request.error);
            request.onblocked = () => reject(new Error('IndexedDB blocked'));
        }).catch((error) => {
            console.warn('Offline-Speicher nicht verfügbar, nur im Arbeitsspeicher', error);
            fallback ??= createMemoryBackend();

            return null;
        });

        return opening;
    }

    return {
        kind: 'indexeddb',

        async get(store, key) {
            const db = await open();

            if (!db) {
                return fallback.get(store, key);
            }

            return requestResult(db.transaction(store, 'readonly').objectStore(store).get(key));
        },

        async getAll(store) {
            const db = await open();

            if (!db) {
                return fallback.getAll(store);
            }

            const objectStore = db.transaction(store, 'readonly').objectStore(store);
            const [keys, values] = await Promise.all([
                requestResult(objectStore.getAllKeys()),
                requestResult(objectStore.getAll())
            ]);

            return keys.map((key, index) => [key, values[index]]);
        },

        async write(store, puts = [], deletes = []) {
            const db = await open();

            if (!db) {
                return fallback.write(store, puts, deletes);
            }

            const transaction = db.transaction(store, 'readwrite');
            const objectStore = transaction.objectStore(store);

            for (const key of deletes) {
                objectStore.delete(key);
            }

            for (const [key, value] of puts) {
                objectStore.put(value, key);
            }

            return transactionDone(transaction);
        },

        async clear() {
            const db = await open();

            if (!db) {
                return fallback.clear();
            }

            const transaction = db.transaction([...STORES], 'readwrite');

            for (const store of STORES) {
                transaction.objectStore(store).clear();
            }

            return transactionDone(transaction);
        }
    };
}

/**
 * @param {ReturnType<typeof createMemoryBackend>} backend
 */
export function createLocalStore(backend = createMemoryBackend()) {
    let releaseUser;
    let userReady = new Promise((resolve) => {
        releaseUser = resolve;
    });

    /** Gespeicherte Operationen: id → { seq, json } */
    const savedOps = new Map();
    let nextSeq = 0;

    /** Zuletzt geschriebener Inhalt je Datenschlüssel (unverändert = nicht schreiben). */
    const savedData = new Map();

    function forgetWritten() {
        savedOps.clear();
        savedData.clear();
        nextSeq = 0;
    }

    const queueStorage = {
        /** Warteschlange nach einem Neustart, in der ursprünglichen Reihenfolge. */
        async load() {
            await userReady;

            const rows = await backend.getAll('ops');
            const records = rows
                .map(([id, record]) => ({ id, ...record }))
                .filter((record) => record.op && typeof record.seq === 'number')
                .sort((a, b) => a.seq - b.seq);

            savedOps.clear();

            for (const record of records) {
                savedOps.set(record.id, { seq: record.seq, json: JSON.stringify(record.op) });
            }

            nextSeq = records.length > 0 ? records[records.length - 1].seq + 1 : 0;

            return records.map((record) => record.op);
        },

        /**
         * Stand der Warteschlange festhalten. Der Unterschied zum vorigen
         * Aufruf wird sofort (synchron) bestimmt, geschrieben in einer
         * Transaktion.
         */
        save(ops) {
            const puts = [];
            const ids = new Set();

            for (const op of ops) {
                ids.add(op.id);

                const json = JSON.stringify(op);
                const known = savedOps.get(op.id);

                if (known?.json === json) {
                    continue;
                }

                const seq = known?.seq ?? nextSeq++;

                savedOps.set(op.id, { seq, json });
                puts.push([op.id, { seq, op: JSON.parse(json) }]);
            }

            const deletes = [...savedOps.keys()].filter((id) => !ids.has(id));

            for (const id of deletes) {
                savedOps.delete(id);
            }

            if (puts.length === 0 && deletes.length === 0) {
                return Promise.resolve();
            }

            return backend.write('ops', puts, deletes);
        }
    };

    const data = {
        get(key) {
            return backend.get('data', key);
        },

        /** Alle Werte, deren Schlüssel mit `prefix` beginnt: [[key, value]]. */
        async entries(prefix) {
            const rows = await backend.getAll('data');
            const result = rows.filter(([key]) => key.startsWith(prefix));

            for (const [key, value] of result) {
                savedData.set(key, JSON.stringify(value));
            }

            return result;
        },

        /** Schreibt nur, wenn sich der Inhalt geändert hat. */
        set(key, value) {
            const json = JSON.stringify(value);

            if (savedData.get(key) === json) {
                return Promise.resolve(false);
            }

            savedData.set(key, json);

            // über JSON: Vue-Proxys lassen sich nicht strukturiert klonen
            return backend.write('data', [[key, JSON.parse(json)]], []).then(() => true);
        },

        /** Mehrere Schlüssel auf einmal setzen (Wert) bzw. löschen (undefined). */
        setMany(changes) {
            const puts = [];
            const deletes = [];

            for (const [key, value] of changes) {
                if (value === undefined) {
                    if (savedData.has(key)) {
                        savedData.delete(key);
                        deletes.push(key);
                    }

                    continue;
                }

                const json = JSON.stringify(value);

                if (savedData.get(key) !== json) {
                    savedData.set(key, json);
                    puts.push([key, JSON.parse(json)]);
                }
            }

            if (puts.length === 0 && deletes.length === 0) {
                return Promise.resolve();
            }

            return backend.write('data', puts, deletes);
        },

        remove(key) {
            savedData.delete(key);

            return backend.write('data', [], [key]);
        },

        /** Gespeicherte Schlüssel mit `prefix` (wie zuletzt gelesen oder geschrieben). */
        knownKeys(prefix) {
            return [...savedData.keys()].filter((key) => key.startsWith(prefix));
        }
    };

    return {
        backend,
        queueStorage,
        data,

        /** Benutzer, dem der lokale Stand gehört (null = noch keiner). */
        async storedUser() {
            return (await backend.get('data', USER_KEY)) ?? null;
        },

        /**
         * Den lokalen Stand diesem Benutzer zuordnen. Gehörte er einem
         * anderen, wird vorher alles gelöscht. Gibt true zurück, wenn ein
         * anderer Benutzer vorher da war.
         */
        async bindUser(userId) {
            const stored = (await backend.get('data', USER_KEY)) ?? null;
            const changed = stored !== null && stored !== userId;

            if (stored !== userId) {
                await backend.clear();
                forgetWritten();
                await backend.write('data', [[USER_KEY, userId]], []);
            }

            releaseUser();

            return changed;
        },

        /** Abmelden: alles löschen; die Warteschlange wartet wieder auf einen Benutzer. */
        async wipe() {
            forgetWritten();
            userReady = new Promise((resolve) => {
                releaseUser = resolve;
            });
            await backend.clear();
        },

        /** Größe der gespeicherten Daten in Bytes (JSON), je Bereich. */
        async sizes() {
            const result = { lists: 0, listCount: 0, catalog: 0, productCount: 0, queue: 0, opCount: 0, other: 0 };

            for (const [key, value] of await backend.getAll('data')) {
                const size = new Blob([JSON.stringify(value)]).size;

                if (key.startsWith('list:') || key.startsWith('orphan:') || key === 'overview') {
                    result.lists += size;
                    result.listCount += key.startsWith('list:') ? 1 : 0;
                } else if (key === 'products' || key === 'suggestions') {
                    result.catalog += size;
                    result.productCount += key === 'products' && Array.isArray(value) ? value.length : 0;
                } else {
                    result.other += size;
                }
            }

            for (const [, record] of await backend.getAll('ops')) {
                result.queue += new Blob([JSON.stringify(record)]).size;
                result.opCount++;
            }

            return result;
        }
    };
}

/** Der Speicher der App (IndexedDB im Browser, Arbeitsspeicher in Node). */
export const localStore = createLocalStore(createIndexedDbBackend());
