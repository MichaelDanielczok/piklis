/**
 * Warteschlange der Operationen mit einem Server-Ersatz (ohne Netz).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ApiError } from '@/api/index.js';
import { createOperationQueue, operations, applyPulledEntries, MAX_BATCH } from '@/api/operations.js';

const LIST = '11111111-1111-4111-8111-111111111111';
const MILK = { id: 'bbbbbbbb-0000-4000-8000-000000000001', listId: LIST, quantity: { amount: 2, unit: null }, note: '' };

/** Server-Ersatz: beantwortet jede Operation mit applied, kann ausfallen. */
function fakeTransport() {
    const transport = {
        batches: [],
        failNext: 0,
        failWith: () => new ApiError(0, 'network', 'Network error'),
        async sendOperations(ops) {
            if (transport.failNext > 0) {
                transport.failNext--;
                throw transport.failWith();
            }

            transport.batches.push(ops.map((op) => structuredClone(op)));

            return { results: ops.map((op, index) => ({ id: op.id, status: 'applied', revision: index + 1 })) };
        },
        async getList(listId, since) {
            return { list: { id: listId }, revision: 3, full: since === null, entries: [] };
        }
    };

    return transport;
}

function manualTimers() {
    const timers = [];

    return {
        timers,
        setTimer: (callback, delay) => {
            timers.push({ callback, delay });
            return timers.length;
        },
        clearTimer: () => {}
    };
}

test('builders carry base and seen values from the local entry', () => {
    const set = operations.setEntry(MILK, { quantity: { amount: 3 } });

    assert.deepEqual(set, {
        type: 'entry.set',
        listId: LIST,
        entryId: MILK.id,
        set: { quantity: { amount: 3, unit: null } },
        base: { quantity: { amount: 2, unit: null } }
    });

    const check = operations.checkEntry(MILK);

    assert.equal(check.status, 'done');
    assert.deepEqual(check.seen, { amount: 2, unit: null });

    const add = operations.addEntry({ listId: LIST, productId: 'p', sortKey: 'i' });

    assert.match(add.entryId, /^[0-9a-f-]{36}$/);
    assert.deepEqual(add.quantity, { amount: 1, unit: null });
});

test('operations are sent in order and removed after their result', async () => {
    const transport = fakeTransport();
    const queue = createOperationQueue({ transport, autoFlush: false });
    const results = [];

    queue.onResult((result, op) => results.push([result.status, op.type]));

    await queue.enqueue(operations.createList({ listId: LIST, name: 'Lidl' }));
    await queue.enqueue(operations.addEntry({ listId: LIST, productId: 'p', sortKey: 'i' }));

    assert.equal(queue.pending(LIST).length, 2);

    await queue.flush();

    assert.equal(transport.batches.length, 1);
    assert.deepEqual(transport.batches[0].map((op) => op.type), ['list.create', 'entry.add']);
    assert.ok(transport.batches[0].every((op) => typeof op.id === 'string' && typeof op.createdAt === 'string'));
    assert.deepEqual(results, [['applied', 'list.create'], ['applied', 'entry.add']]);
    assert.equal(queue.pending().length, 0);
});

test('network errors keep the operations and retry with a pause', async () => {
    const transport = fakeTransport();
    const timers = manualTimers();
    const queue = createOperationQueue({ transport, autoFlush: false, ...timers });

    transport.failNext = 1;
    const op = await queue.enqueue(operations.removeEntry(MILK));
    await queue.flush();

    assert.equal(queue.pending().length, 1, 'still waiting');
    assert.equal(queue.isEntryPending(MILK.id), true);
    assert.equal(queue.lastError.code, 'network');
    assert.equal(timers.timers.length, 1);
    assert.equal(timers.timers[0].delay, 1000);

    timers.timers[0].callback();
    await queue.flush();

    assert.equal(queue.pending().length, 0);
    assert.equal(transport.batches[0][0].id, op.id, 'resent with the same id');
});

test('client errors are not retried automatically', async () => {
    const transport = fakeTransport();
    const timers = manualTimers();
    const queue = createOperationQueue({ transport, autoFlush: false, ...timers });

    transport.failNext = 1;
    transport.failWith = () => new ApiError(401, 'unauthorized', 'Not logged in.');
    await queue.enqueue(operations.completeList(LIST));
    await queue.flush();

    assert.equal(queue.pending().length, 1);
    assert.equal(timers.timers.length, 0);
});

test('large queues are sent in batches', async () => {
    const transport = fakeTransport();
    const queue = createOperationQueue({ transport, autoFlush: false });

    for (let i = 0; i < MAX_BATCH + 5; i++) {
        await queue.enqueue(operations.addEntry({ listId: LIST, productId: 'p' + i, sortKey: 'i' }));
    }

    await queue.flush();

    assert.deepEqual(transport.batches.map((batch) => batch.length), [MAX_BATCH, 5]);
});

test('unsent quantity taps on one entry become one change', async () => {
    const transport = fakeTransport();
    const queue = createOperationQueue({ transport, autoFlush: false });

    await queue.enqueue(operations.setEntry(MILK, { quantity: { amount: 3 } }));
    await queue.enqueue(operations.setEntry({ ...MILK, quantity: { amount: 3 } }, { quantity: { amount: 4 } }));

    assert.equal(queue.pending().length, 1);
    assert.deepEqual(queue.pending()[0].set.quantity, { amount: 4, unit: null });
    assert.deepEqual(queue.pending()[0].base.quantity, { amount: 2, unit: null });

    // zurück auf den Ausgangswert: nichts mehr zu senden
    await queue.enqueue(operations.setEntry({ ...MILK, quantity: { amount: 4 } }, { quantity: { amount: 2 } }));

    assert.equal(queue.pending().length, 0);
});

test('a change after another operation on the same entry is kept separate', async () => {
    const queue = createOperationQueue({ transport: fakeTransport(), autoFlush: false });

    await queue.enqueue(operations.setEntry(MILK, { quantity: { amount: 3 } }));
    await queue.enqueue(operations.checkEntry({ ...MILK, quantity: { amount: 3 } }));
    await queue.enqueue(operations.setEntry({ ...MILK, quantity: { amount: 3 } }, { quantity: { amount: 4 } }));

    assert.deepEqual(queue.pending().map((op) => op.type), ['entry.set', 'entry.setStatus', 'entry.set']);
});

test('moves of one entry are coalesced', async () => {
    const queue = createOperationQueue({ transport: fakeTransport(), autoFlush: false });

    await queue.enqueue(operations.moveEntry(MILK, 'k'));
    await queue.enqueue(operations.moveEntry(MILK, 'm'));

    assert.equal(queue.pending().length, 1);
    assert.equal(queue.pending()[0].sortKey, 'm');
});

test('storage keeps the queue (interface for IndexedDB in step 13)', async () => {
    const saved = [];
    const storage = {
        async load() {
            return [{ id: 'old', type: 'list.complete', listId: LIST }];
        },
        async save(ops) {
            saved.push(ops.map((op) => op.id));
        }
    };

    const transport = fakeTransport();
    const queue = createOperationQueue({ transport, storage, autoFlush: false });

    await queue.ready;
    assert.deepEqual(queue.pending().map((op) => op.id), ['old']);

    await queue.flush();

    assert.equal(transport.batches[0][0].id, 'old');
    assert.deepEqual(saved.at(-1), []);
});

test('pull fetches changes since a revision', async () => {
    const queue = createOperationQueue({ transport: fakeTransport(), autoFlush: false });

    assert.equal((await queue.pull(LIST, 2)).full, false);
    assert.equal((await queue.pull(LIST)).full, true);
});

test('pulled entries update the local state', () => {
    const local = new Map([
        ['a', { id: 'a', sortKey: 'i' }],
        ['b', { id: 'b', sortKey: 'j' }]
    ]);

    const changed = applyPulledEntries(local, {
        full: false,
        entries: [
            { id: 'a', sortKey: 'k', removed: false, mergedInto: null },
            { id: 'b', removed: true, mergedInto: null },
            { id: 'c', removed: true, mergedInto: 'a' },
            { id: 'd', sortKey: 'l', removed: false, mergedInto: null }
        ]
    });

    assert.deepEqual([...changed.keys()], ['a', 'd']);
    assert.equal(changed.get('a').sortKey, 'k');

    const full = applyPulledEntries(local, { full: true, entries: [{ id: 'x', removed: false, mergedInto: null }] });

    assert.deepEqual([...full.keys()], ['x']);
});

test('clear drops everything on logout', async () => {
    const queue = createOperationQueue({ transport: fakeTransport(), autoFlush: false });

    await queue.enqueue(operations.completeList(LIST));
    await queue.clear();

    assert.equal(queue.pending().length, 0);
});
