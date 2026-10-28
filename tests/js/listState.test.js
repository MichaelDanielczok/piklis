/**
 * Lokaler Stand einer Liste: Serverstand plus eigene Operationen.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { operations } from '@/api/operations.js';
import {
    activeEntryOf,
    appendKey,
    applyOperation,
    applyOperations,
    changesState,
    countEntries,
    mergeEntries,
    planMove,
    sortedEntries,
    translateMove
} from '@/utils/listState.js';

const LIST = '11111111-1111-4111-8111-111111111111';
const OTHER = '22222222-2222-4222-8222-222222222222';

function entry(id, sortKey, fields = {}) {
    return {
        id,
        listId: LIST,
        productId: 'p-' + id,
        quantity: { amount: 1, unit: null },
        note: '',
        sortKey,
        status: 'open',
        checkedQuantity: null,
        removed: false,
        mergedInto: null,
        ...fields
    };
}

function stateOf(...entries) {
    return {
        list: { id: LIST, name: 'Lidl', shopDate: null, status: 'active', deleted: false },
        entries: new Map(entries.map((item) => [item.id, item]))
    };
}

function ids(entries) {
    return entries.map((item) => item.id);
}

test('list.create legt die Liste lokal an, list.update ändert nur gesendete Felder', () => {
    const created = applyOperation({ list: null, entries: new Map() }, {
        ...operations.createList({ listId: LIST, name: 'DM', shopDate: '2026-10-31' }),
        createdAt: '2026-10-27T10:00:00+01:00'
    });

    assert.equal(created.list.name, 'DM');
    assert.equal(created.list.status, 'active');
    assert.equal(created.list.ownership, 'personal');

    const renamed = applyOperation(created, operations.updateList(LIST, { name: 'Rossmann' }));

    assert.equal(renamed.list.name, 'Rossmann');
    assert.equal(renamed.list.shopDate, '2026-10-31');

    const undated = applyOperation(renamed, operations.updateList(LIST, { shopDate: null }));

    assert.equal(undated.list.shopDate, null);
    assert.equal(applyOperation(undated, operations.deleteList(LIST)).list.deleted, true);
});

test('Zustand wird nie verändert', () => {
    const before = stateOf(entry('a', 'i'));
    const after = applyOperation(before, operations.checkEntry(before.entries.get('a')));

    assert.equal(before.entries.get('a').status, 'open');
    assert.equal(after.entries.get('a').status, 'done');
});

test('entry.add hängt an, Abhaken und Zurückholen behalten die Position', () => {
    let state = stateOf(entry('a', 'i'), entry('b', 'j'), entry('c', 'k'));

    state = applyOperation(state, operations.addEntry({
        listId: LIST,
        entryId: 'd',
        productId: 'p-d',
        sortKey: appendKey(state.entries)
    }));

    assert.deepEqual(ids(sortedEntries(state.entries, 'open')), ['a', 'b', 'c', 'd']);

    state = applyOperation(state, operations.checkEntry(state.entries.get('b')));

    assert.deepEqual(ids(sortedEntries(state.entries, 'open')), ['a', 'c', 'd']);
    assert.deepEqual(ids(sortedEntries(state.entries, 'done')), ['b']);
    assert.deepEqual(state.entries.get('b').checkedQuantity, { amount: 1, unit: null });

    state = applyOperation(state, operations.setEntryStatus(state.entries.get('b'), 'open'));

    assert.deepEqual(ids(sortedEntries(state.entries, 'open')), ['a', 'b', 'c', 'd']);
    assert.equal(state.entries.get('b').checkedQuantity, null);
});

test('neue Einträge kommen hinter erledigte und entfernte', () => {
    const entries = new Map([
        ['a', entry('a', 'i')],
        ['b', entry('b', 'k', { status: 'done' })],
        ['c', entry('c', 'm', { removed: true })]
    ]);

    assert.ok(appendKey(entries) > 'm');
    assert.equal(appendKey(new Map()), 'i');
});

test('entry.set ändert Menge und Notiz, remove und restore blenden aus und ein', () => {
    let state = stateOf(entry('a', 'i'));
    const milk = state.entries.get('a');

    state = applyOperation(state, operations.setEntry(milk, { quantity: { amount: 2, unit: 'pack' }, note: 'die blaue' }));

    assert.deepEqual(state.entries.get('a').quantity, { amount: 2, unit: 'pack' });
    assert.equal(state.entries.get('a').note, 'die blaue');

    state = applyOperation(state, operations.removeEntry(state.entries.get('a')));

    assert.deepEqual(sortedEntries(state.entries), []);
    assert.equal(countEntries(state.entries).total, 0);

    state = applyOperation(state, operations.restoreEntry(state.entries.get('a')));

    assert.deepEqual(ids(sortedEntries(state.entries)), ['a']);
});

test('applyOperations wendet nur Operationen dieser Liste an', () => {
    const state = stateOf(entry('a', 'i'));
    const ops = [
        operations.updateList(OTHER, { name: 'Andere' }),
        operations.updateList(LIST, { name: 'REWE' })
    ];

    assert.equal(applyOperations(state, ops, LIST).list.name, 'REWE');
});

test('ohne geladene Einträge betreffen Eintrags-Operationen nichts', () => {
    const state = { list: { id: LIST, name: 'Lidl' }, entries: null };
    const op = operations.addEntry({ listId: LIST, entryId: 'x', productId: 'p', sortKey: 'i' });

    assert.equal(applyOperation(state, op), state);
});

test('mergeEntries: ganz ersetzt, seit N ergänzt, Verweise fallen heraus, Entfernte bleiben markiert', () => {
    const base = new Map([['a', entry('a', 'i')], ['b', entry('b', 'j')]]);

    const full = mergeEntries(base, { full: true, entries: [entry('c', 'k')] });

    assert.deepEqual([...full.keys()], ['c']);

    const since = mergeEntries(base, {
        full: false,
        entries: [entry('a', 'i', { removed: true }), entry('b', 'j', { mergedInto: 'z' })]
    });

    assert.deepEqual([...since.keys()], ['a']);
    assert.equal(since.get('a').removed, true);
});

test('countEntries zählt sichtbare Einträge je Zustand', () => {
    const entries = new Map([
        ['a', entry('a', 'i')],
        ['b', entry('b', 'j', { status: 'done' })],
        ['c', entry('c', 'k', { status: 'notGot' })],
        ['d', entry('d', 'l', { removed: true })]
    ]);

    assert.deepEqual(countEntries(entries), { open: 1, done: 1, notGot: 1, total: 3 });
});

test('activeEntryOf findet offene, nicht erledigte Einträge eines Produkts', () => {
    const entries = new Map([
        ['a', entry('a', 'i', { productId: 'milk', status: 'done' })],
        ['b', entry('b', 'j', { productId: 'milk' })]
    ]);

    assert.equal(activeEntryOf(entries, 'milk').id, 'b');
    assert.equal(activeEntryOf(new Map([['a', entries.get('a')]]), 'milk'), null);
});

test('activeEntryOf: nicht bekommen zählt nicht als drauf (Plus legt einen neuen offenen an)', () => {
    const entries = new Map([['a', entry('a', 'i', { productId: 'gouda', status: 'notGot' })]]);

    assert.equal(activeEntryOf(entries, 'gouda'), null);
});

test('entry.takeOver: neu am Ende oder in den offenen Eintrag zusammengeführt', () => {
    const state = stateOf(
        entry('cream', 'i', { productId: 'cream', quantity: { amount: 1, unit: 'cup' } }),
        entry('gouda-old', 'j', { productId: 'gouda', status: 'notGot' })
    );
    const from = { listId: OTHER, productId: 'cream', quantity: { amount: 2, unit: 'cup' }, note: 'für den Kuchen' };
    const summed = applyOperation(state, operations.takeOverEntry(from, { listId: LIST, sortKey: 'k' }));

    assert.equal(summed.entries.size, 2);
    assert.deepEqual(summed.entries.get('cream').quantity, { amount: 3, unit: 'cup' });
    assert.equal(summed.entries.get('cream').note, 'für den Kuchen');

    const gouda = { listId: OTHER, productId: 'gouda', quantity: { amount: 400, unit: 'g' }, note: '' };
    const op = operations.takeOverEntry(gouda, { listId: LIST, sortKey: 'k' });
    const added = applyOperation(state, op);

    assert.equal(added.entries.size, 3);
    assert.equal(added.entries.get(op.entryId).status, 'open');
    assert.equal(op.fromListId, OTHER);

    const other = { listId: OTHER, productId: 'cream', quantity: { amount: 200, unit: 'ml' }, note: '' };
    const kept = applyOperation(state, operations.takeOverEntry(other, { listId: LIST, sortKey: 'k', onUnitMismatch: 'keep' }));

    assert.deepEqual(kept.entries.get('cream').quantity, { amount: 1, unit: 'cup' });
});

test('planMove: nach unten, nach oben, an Anfang und Ende', () => {
    const sorted = [entry('a', 'i'), entry('b', 'j'), entry('c', 'k'), entry('d', 'l')];

    function order(from, to) {
        const moves = planMove(sorted, from, to);
        const keys = new Map(sorted.map((item) => [item.id, item.sortKey]));

        for (const move of moves) {
            keys.set(move.entry.id, move.sortKey);
        }

        return [...keys.entries()].sort((x, y) => (x[1] < y[1] ? -1 : x[1] > y[1] ? 1 : 0)).map(([id]) => id);
    }

    assert.deepEqual(order(0, 2), ['b', 'c', 'a', 'd']);
    assert.deepEqual(order(3, 1), ['a', 'd', 'b', 'c']);
    assert.deepEqual(order(2, 0), ['c', 'a', 'b', 'd']);
    assert.deepEqual(order(0, 3), ['b', 'c', 'd', 'a']);
    assert.deepEqual(planMove(sorted, 1, 1), []);
    assert.equal(planMove(sorted, 0, 2).length, 1);
});

test('planMove: gleiche Schlüssel der Nachbarn verschieben zuerst den rechten', () => {
    const sorted = [entry('a', 'i'), entry('b', 'k'), entry('c', 'k'), entry('d', 'm')];
    const moves = planMove(sorted, 0, 1);

    assert.equal(moves.length, 2);
    assert.equal(moves[0].entry.id, 'c');
    assert.ok(moves[0].sortKey > 'k' && moves[0].sortKey < 'm');
    assert.ok(moves[1].sortKey > 'k' && moves[1].sortKey < moves[0].sortKey);
});

test('mergeEntries: ältere Revision ersetzt keinen neueren Eintrag (Abfrage und Antwort überholen sich)', () => {
    const known = new Map([['a', entry('a', 'i', { revision: 7, quantity: { amount: 3, unit: null } })]]);

    const older = mergeEntries(known, { full: false, entries: [entry('a', 'i', { revision: 6 })] });
    assert.equal(older.get('a').quantity.amount, 3);

    const newer = mergeEntries(known, { full: false, entries: [entry('a', 'i', { revision: 8, note: 'bio' })] });
    assert.equal(newer.get('a').note, 'bio');

    const full = mergeEntries(known, { full: true, entries: [entry('a', 'i', { revision: 6 })] });
    assert.equal(full.get('a').revision, 6, 'der ganze Stand gilt');
});

test('changesState: leere Antwort „seit N“ bei gleicher Revision ändert nichts', () => {
    const list = { id: LIST, name: 'Lidl' };
    const detail = { revision: 5, list };

    assert.equal(changesState(detail, { full: false, revision: 5, list: { ...list }, entries: [] }), false);
    assert.equal(changesState(detail, { full: false, revision: 6, list, entries: [] }), true);
    assert.equal(changesState(detail, { full: false, revision: 5, list: { ...list, name: 'Rewe' }, entries: [] }), true);
    assert.equal(changesState(detail, { full: false, revision: 6, list, entries: [entry('a', 'i')] }), true);
    assert.equal(changesState(detail, { full: true, revision: 5, list, entries: [] }), true);
});

test('translateMove: Ablegen nach dem Ziehen gilt für den aktuellen Stand', () => {
    const frozen = ['a', 'b', 'c', 'd'];
    const same = [entry('a', 'a'), entry('b', 'b'), entry('c', 'c'), entry('d', 'd')];

    // unverändert: dieselben Indizes
    assert.deepEqual(translateMove(same, frozen, 0, 2), { from: 0, to: 2 });
    assert.deepEqual(translateMove(same, frozen, 3, 0), { from: 3, to: 0 });

    // inzwischen kam „x“ hinter „a“ dazu: c landet weiter hinter a
    const added = [entry('a', 'a'), entry('x', 'ai'), entry('b', 'b'), entry('c', 'c'), entry('d', 'd')];
    assert.deepEqual(translateMove(added, frozen, 2, 1), { from: 3, to: 1 });

    // inzwischen wurde „b“ abgehakt: hinter den nächsten Nachbarn davor
    const checked = [entry('a', 'a'), entry('c', 'c'), entry('d', 'd')];
    assert.deepEqual(translateMove(checked, frozen, 3, 2), { from: 2, to: 1 });

    // alle Nachbarn davor weg: vor den ersten danach
    const gone = [entry('c', 'c'), entry('d', 'd')];
    assert.deepEqual(translateMove(gone, frozen, 3, 2), { from: 1, to: 0 });

    // der gezogene Eintrag ist nicht mehr offen
    assert.equal(translateMove(checked, frozen, 1, 3), null);
});
