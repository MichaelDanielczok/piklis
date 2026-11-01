import { test } from 'node:test';
import assert from 'node:assert/strict';
import { draftOf, isDraftChanged, moveItem, templateEntries, templatePlan, withQuantity } from '../../frontend/utils/templates.js';

const MILK = 'p-milk';
const BREAD = 'p-bread';
const EGGS = 'p-eggs';

function entry(id, productId, sortKey, extra = {}) {
    return [id, {
        id,
        productId,
        sortKey,
        quantity: { amount: 1, unit: null },
        note: '',
        status: 'open',
        removed: false,
        mergedInto: null,
        ...extra
    }];
}

test('templatePlan keeps the list order, done and not got products and merges notes', () => {
    const entries = new Map([
        entry('e1', BREAD, 'b', { status: 'done', note: 'Vollkorn' }),
        entry('e2', MILK, 'a', { quantity: { amount: 2, unit: 'pack' } }),
        entry('e3', EGGS, 'c', { status: 'notGot' }),
        entry('e4', MILK, 'd', { quantity: { amount: 1, unit: 'pack' }, note: 'laktosefrei' }),
        entry('e5', EGGS, 'e', { removed: true, note: 'weg' })
    ]);

    const plan = templatePlan(entries);

    assert.deepEqual(plan.map((item) => item.productId), [MILK, BREAD, EGGS]);
    assert.deepEqual(plan[0].quantity, { amount: 3, unit: 'pack' });
    assert.equal(plan[0].note, 'laktosefrei');
    assert.equal(plan[1].status, 'done');
    assert.equal(plan[1].note, 'Vollkorn');
    assert.equal(plan[2].note, '');
});

test('templateEntries drops notes unless wanted', () => {
    const items = [{ productId: MILK, quantity: { amount: 2, unit: 'pack' }, note: 'laktosefrei', status: 'open' }];

    assert.deepEqual(templateEntries(items, { withNotes: false }), [
        { productId: MILK, quantity: { amount: 2, unit: 'pack' }, note: '' }
    ]);
    assert.equal(templateEntries(items)[0].note, 'laktosefrei');
});

test('withQuantity adds at the end, changes and removes', () => {
    let items = [];

    items = withQuantity(items, MILK, 1);
    items = withQuantity(items, BREAD, 1);
    items = withQuantity(items, MILK, 3);

    assert.deepEqual(items.map((item) => [item.productId, item.quantity.amount]), [[MILK, 3], [BREAD, 1]]);
    assert.deepEqual(withQuantity(items, MILK, 0).map((item) => item.productId), [BREAD]);
    assert.equal(withQuantity(items, EGGS, 0), items);
});

test('moveItem moves one entry', () => {
    const items = [{ productId: MILK }, { productId: BREAD }, { productId: EGGS }];

    assert.deepEqual(moveItem(items, 2, 0).map((item) => item.productId), [EGGS, MILK, BREAD]);
    assert.equal(moveItem(items, 1, 1), items);
});

test('a draft is unchanged until name or items differ', () => {
    const template = {
        name: 'Wocheneinkauf Lidl',
        revision: 4,
        entries: [{ id: 't1', productId: MILK, quantity: { amount: 2, unit: 'pack' }, note: '', sortKey: 'i' }]
    };
    const draft = draftOf(template);

    assert.equal(draft.revision, 4);
    assert.equal(isDraftChanged(draft, template), false);
    assert.equal(isDraftChanged({ ...draft, name: 'Lidl' }, template), true);
    assert.equal(isDraftChanged({ ...draft, items: withQuantity(draft.items, BREAD, 1) }, template), true);
});
