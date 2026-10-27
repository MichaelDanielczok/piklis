/**
 * Reihenfolge in „Produkte hinzufügen“: Vorschläge, dann der Katalog.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    countOrder,
    currentOrder,
    FIRST_PAGE,
    freezeOrder,
    nextLimit,
    NEXT_PAGE,
    sliceOrder
} from '@/utils/pickerOrder.js';

const catalog = [
    { id: 'apfel' },
    { id: 'brot' },
    { id: 'butter' },
    { id: 'gouda', hidden: true },
    { id: 'milch' },
    { id: 'zwiebeln' }
];

test('suggestions first, then the rest of the catalog in its order', () => {
    const order = freezeOrder(catalog, [{ productId: 'milch' }, { productId: 'brot' }]);

    assert.deepEqual(order, {
        suggested: ['milch', 'brot'],
        rest: ['apfel', 'butter', 'zwiebeln']
    });
});

test('suggestions skip hidden, unknown and repeated products', () => {
    const order = freezeOrder(catalog, [
        { productId: 'gouda' },
        { productId: 'gone' },
        { productId: 'milch' },
        { productId: 'milch' }
    ]);

    assert.deepEqual(order.suggested, ['milch']);
    assert.deepEqual(order.rest, ['apfel', 'brot', 'butter', 'zwiebeln']);
});

test('without suggestions (not loaded, offline) the catalog stays alphabetical', () => {
    assert.deepEqual(freezeOrder(catalog, null), {
        suggested: [],
        rest: ['apfel', 'brot', 'butter', 'milch', 'zwiebeln']
    });
});

test('the frozen order stays while the catalog changes', () => {
    const frozen = freezeOrder(catalog, [{ productId: 'milch' }]);
    const later = [
        { id: 'aal' }, // von jemand anderem angelegt: hinten
        { id: 'apfel' },
        { id: 'brot', hidden: true }, // inzwischen ausgeblendet: fällt weg
        { id: 'butter' },
        { id: 'eis' }, // hier angelegt: eigener Abschnitt vorn
        { id: 'milch' },
        { id: 'zwiebeln' }
    ];

    assert.deepEqual(currentOrder(frozen, later, ['eis']), {
        created: ['eis'],
        suggested: ['milch'],
        rest: ['apfel', 'butter', 'zwiebeln', 'aal']
    });
});

test('a product created here appears only once', () => {
    const frozen = freezeOrder(catalog, [{ productId: 'milch' }]);
    const order = currentOrder(frozen, catalog, ['milch', 'apfel']);

    assert.deepEqual(order.created, ['milch', 'apfel']);
    assert.deepEqual(order.suggested, []);
    assert.deepEqual(order.rest, ['brot', 'butter', 'zwiebeln']);
    assert.equal(countOrder(order), 5);
});

test('48 tiles first, then 24 more each time', () => {
    assert.equal(FIRST_PAGE, 48);
    assert.equal(NEXT_PAGE, 24);
    assert.equal(nextLimit(48), 72);
    assert.equal(nextLimit(72), 96);
    assert.equal(nextLimit(0), 72);
});

test('slicing fills the sections in order', () => {
    const order = { created: ['n1'], suggested: ['s1', 's2', 's3'], rest: ['r1', 'r2'] };

    assert.deepEqual(sliceOrder(order, 3), { created: ['n1'], suggested: ['s1', 's2'], rest: [] });
    assert.deepEqual(sliceOrder(order, 5), { created: ['n1'], suggested: ['s1', 's2', 's3'], rest: ['r1'] });
    assert.deepEqual(sliceOrder(order, 99), order);
});
