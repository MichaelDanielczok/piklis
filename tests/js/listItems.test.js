/**
 * Listenartikel im Speicher der App: zusammenführen, verschwundene erkennen,
 * mit der Liste speichern, gleichen Namen wiederfinden.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { goneListItems, listItemNamed, listItemsOf, mergeListItems } from '@/utils/listItems.js';

const lights = { id: 'lights', name: 'Lichterkette', listItem: true };
const ribbon = { id: 'ribbon', name: 'Geschenkband', listItem: true, local: true };
const milk = { id: 'milk', name: 'Milch', listItem: false };

const known = new Map([[lights.id, lights], [ribbon.id, ribbon]]);
const find = (id) => ({ lights, ribbon, milk })[id] ?? null;

test('unchanged items keep the same map, so nothing renders again', () => {
    assert.equal(mergeListItems(known, [{ ...lights }]), known);
    assert.equal(mergeListItems(known, []), known);
});

test('new and changed items make a new map', () => {
    const changed = { ...lights, description: 'für den Balkon' };
    const next = mergeListItems(known, [changed, { id: 'x', name: 'X', listItem: true }]);

    assert.notEqual(next, known);
    assert.equal(next.get('lights'), changed);
    assert.equal(next.size, 3);
    assert.equal(known.get('lights'), lights);
});

test('an item on the list that the server no longer sends is gone', () => {
    assert.deepEqual(goneListItems(known, [], ['lights', 'milk', 'lights']), ['lights']);
    assert.deepEqual(goneListItems(known, [lights], ['lights']), []);
});

test('unconfirmed items are not gone, the server may not know them yet', () => {
    assert.deepEqual(goneListItems(known, [], ['ribbon']), []);
});

test('only confirmed list items are stored with the list, each once', () => {
    const entries = [{ productId: 'lights' }, { productId: 'milk' }, { productId: 'ribbon' }, { productId: 'lights' }];

    assert.deepEqual(listItemsOf(entries, find), [lights]);
});

test('a list item is found by its name, whatever the case and accents', () => {
    const entries = [{ productId: 'milk' }, { productId: 'lights' }];

    assert.equal(listItemNamed(entries, ' lichterkette', find), lights);
    assert.equal(listItemNamed(entries, 'LICHTERKETTE ', find), lights);
    assert.equal(listItemNamed(entries, 'Lichter', find), null);
});

test('a catalog product of that name does not count', () => {
    assert.equal(listItemNamed([{ productId: 'milk' }], 'Milch', find), null);
});
