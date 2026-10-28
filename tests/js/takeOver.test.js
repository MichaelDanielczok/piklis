/**
 * Übernehmen in eine andere Liste und neue Liste aus einem früheren Einkauf;
 * die Zusammenführung prüft tests/unit/Sync/TakeOverMergeTest.php mit
 * denselben Fällen.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { copyPlan, mergeNotes, mergeTakeOver, planTakeOver } from '@/utils/takeOver.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/take-over.json', import.meta.url), 'utf8'));

function entry(id, productId, sortKey, amount, unit = null, fields = {}) {
    return {
        id,
        productId,
        sortKey,
        quantity: { amount, unit },
        note: '',
        status: 'open',
        removed: false,
        mergedInto: null,
        ...fields
    };
}

test('mergeTakeOver matches the shared fixture', () => {
    for (const { name, existing, incoming, mode, text, expected } of fixture.merge) {
        assert.deepEqual(mergeTakeOver(existing, incoming, mode, text), expected, name);
    }
});

test('mergeNotes bleibt bei 500 Zeichen', () => {
    const long = 'a'.repeat(490);

    assert.ok(mergeNotes(long, 'für den Kuchen').length <= 500);
    assert.ok(mergeNotes(long, 'für den Kuchen').startsWith(long + ' · f'));
});

test('planTakeOver: neu, addieren, andere Einheit', () => {
    const target = new Map([
        ['t1', entry('t1', 'cream', 'i', 1, 'cup')],
        ['t2', entry('t2', 'gouda', 'j', 1, 'pack')],
        ['t3', entry('t3', 'milk', 'k', 1, null, { status: 'done' })]
    ]);
    const plan = planTakeOver(target, [
        entry('a', 'cream', 'i', 2, 'cup'),
        entry('b', 'gouda', 'j', 400, 'g'),
        entry('c', 'milk', 'k', 2)
    ]);

    assert.deepEqual(plan.map((item) => item.kind), ['sum', 'mismatch', 'add']);
    assert.equal(plan[1].existing.id, 't2');
});

test('copyPlan: Listenreihenfolge, je Produkt einmal, alle Zustände', () => {
    const entries = new Map([
        ['a', entry('a', 'milk', 'k', 2, 'pack', { status: 'done' })],
        ['b', entry('b', 'bread', 'i', 1)],
        ['c', entry('c', 'gouda', 'j', 400, 'g', { status: 'notGot', note: 'jung' })],
        ['d', entry('d', 'milk', 'l', 1, 'pack')],
        ['e', entry('e', 'eggs', 'h', 10, null, { removed: true })],
        ['f', entry('f', 'butter', 'm', 1, null, { mergedInto: 'b' })]
    ]);

    assert.deepEqual(copyPlan(entries), [
        { productId: 'bread', quantity: { amount: 1, unit: null }, status: 'open' },
        { productId: 'gouda', quantity: { amount: 400, unit: 'g' }, status: 'notGot' },
        { productId: 'milk', quantity: { amount: 3, unit: 'pack' }, status: 'open' }
    ]);
});
