/**
 * Lokaler Speicher (api/localStore.js) mit dem Speicher im Arbeitsspeicher,
 * der dieselbe Schnittstelle wie IndexedDB hat, und die Warteschlange
 * darauf: Neuladen, Reihenfolge, schnelle Mehrfachtipps, anderer Benutzer.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ApiError } from '@/api/index.js';
import { createLocalStore, createMemoryBackend } from '@/api/localStore.js';
import { createOperationQueue, operations } from '@/api/operations.js';

const LIST = '11111111-1111-4111-8111-111111111111';
const MILK = { id: 'bbbbbbbb-0000-4000-8000-000000000001', listId: LIST, quantity: { amount: 1, unit: null }, note: '' };

function offlineTransport() {
    const transport = {
        offline: true,
        batches: [],
        async sendOperations(ops) {
            if (transport.offline) {
                throw new ApiError(0, 'network', 'Network error');
            }

            transport.batches.push(ops.map((op) => structuredClone(op)));

            return { results: ops.map((op, index) => ({ id: op.id, status: 'applied', revision: index + 2 })) };
        },
        async getList() {
            throw new ApiError(0, 'network', 'Network error');
        }
    };

    return transport;
}

const noTimers = { setTimer: () => 1, clearTimer: () => {} };

/** Ein „Neuladen“: neuer Speicher und neue Warteschlange auf demselben Backend. */
async function reload(backend, transport, userId = 'u1') {
    const store = createLocalStore(backend);
    const queue = createOperationQueue({ transport, storage: store.queueStorage, ...noTimers });

    await store.bindUser(userId);
    await queue.ready;

    return { store, queue };
}

test('the queue survives a reload while offline and is sent in order afterwards', async () => {
    const backend = createMemoryBackend();
    const transport = offlineTransport();
    const first = await reload(backend, transport);

    const check = await first.queue.enqueue(operations.checkEntry(MILK));
    const set = await first.queue.enqueue(operations.setEntry(MILK, { quantity: { amount: 2 } }));
    const add = await first.queue.enqueue(operations.addEntry({ listId: LIST, productId: 'p', sortKey: 'k' }));

    await first.queue.flush();
    assert.equal(first.queue.pending().length, 3);

    const second = await reload(backend, transport);

    assert.deepEqual(second.queue.pending().map((op) => op.id), [check.id, set.id, add.id]);

    transport.offline = false;
    await second.queue.flush();

    assert.deepEqual(transport.batches[0].map((op) => op.id), [check.id, set.id, add.id]);
    assert.equal(second.queue.pending().length, 0);

    const third = await reload(backend, transport);

    assert.equal(third.queue.pending().length, 0);
});

test('fast repeated taps are all stored, coalesced into one change', async () => {
    const backend = createMemoryBackend();
    const transport = offlineTransport();
    const { queue } = await reload(backend, transport);

    // drei Plus-Tipps im selben Takt, ohne auf das Speichern zu warten
    const taps = [2, 3, 4].map((amount) => queue.enqueue(operations.setEntry(
        { ...MILK, quantity: { amount: amount - 1, unit: null } },
        { quantity: { amount } }
    )));

    await Promise.all(taps);

    const { queue: after } = await reload(backend, transport);
    const pending = after.pending();

    assert.equal(pending.length, 1);
    assert.deepEqual(pending[0].set, { quantity: { amount: 4, unit: null } });
    assert.deepEqual(pending[0].base, { quantity: { amount: 1, unit: null } });
});

test('a different user starts with an empty store', async () => {
    const backend = createMemoryBackend();
    const transport = offlineTransport();
    const first = await reload(backend, transport, 'u1');

    await first.queue.enqueue(operations.checkEntry(MILK));
    await first.store.data.set('list:' + LIST, { list: { id: LIST }, revision: 3, entries: [] });

    const other = createLocalStore(backend);

    assert.equal(await other.storedUser(), 'u1');
    assert.equal(await other.bindUser('u2'), true);

    const queue = createOperationQueue({ transport, storage: other.queueStorage, ...noTimers });

    await queue.ready;
    assert.equal(queue.pending().length, 0);
    assert.equal(await other.data.get('list:' + LIST), undefined);
    assert.equal(await other.storedUser(), 'u2');
});

test('the queue waits for the user before loading', async () => {
    const backend = createMemoryBackend();
    const store = createLocalStore(backend);
    const queue = createOperationQueue({ transport: offlineTransport(), storage: store.queueStorage, ...noTimers });
    let loaded = false;

    queue.ready.then(() => {
        loaded = true;
    });

    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(loaded, false);

    await store.bindUser('u1');
    await queue.ready;
    assert.equal(loaded, true);
});

test('data writes only changed values and removes keys in one go', async () => {
    const backend = createMemoryBackend();
    const writes = [];
    const original = backend.write;

    backend.write = (store, puts, deletes) => {
        writes.push({ store, puts: puts.map(([key]) => key), deletes });
        return original(store, puts, deletes);
    };

    const store = createLocalStore(backend);

    await store.bindUser('u1');
    writes.length = 0;

    await store.data.set('products', [{ id: 'a' }]);
    await store.data.set('products', [{ id: 'a' }]);
    assert.equal(writes.length, 1);

    await store.data.setMany([['list:a', { revision: 1 }], ['list:b', { revision: 1 }]]);
    await store.data.setMany([['list:a', { revision: 1 }], ['list:b', undefined]]);

    assert.deepEqual(writes.slice(1), [
        { store: 'data', puts: ['list:a', 'list:b'], deletes: [] },
        { store: 'data', puts: [], deletes: ['list:b'] }
    ]);
    assert.deepEqual(store.data.knownKeys('list:'), ['list:a']);
    assert.deepEqual((await store.data.entries('list:')).map(([key]) => key), ['list:a']);
});

test('wipe clears everything and waits for the next user', async () => {
    const backend = createMemoryBackend();
    const { store, queue } = await reload(backend, offlineTransport());

    await queue.enqueue(operations.checkEntry(MILK));
    await store.data.set('me', { id: 'u1' });
    await store.wipe();

    assert.equal(await store.storedUser(), null);
    assert.equal(await store.data.get('me'), undefined);
    assert.deepEqual(await backend.getAll('ops'), []);
});

test('sizes are reported per area', async () => {
    const { store, queue } = await reload(createMemoryBackend(), offlineTransport());

    await queue.enqueue(operations.checkEntry(MILK));
    await store.data.set('list:' + LIST, { list: { id: LIST }, revision: 3, entries: [MILK] });
    await store.data.set('products', [{ id: 'p1' }, { id: 'p2' }]);

    const sizes = await store.sizes();

    assert.equal(sizes.listCount, 1);
    assert.equal(sizes.productCount, 2);
    assert.equal(sizes.opCount, 1);
    assert.ok(sizes.lists > 0 && sizes.catalog > 0 && sizes.queue > 0);
});
