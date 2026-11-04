/**
 * Bildschirmtastatur: Höhe und Zustand landen an der Wurzel und verschwinden wieder.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installKeyboardInset } from '@/utils/keyboardInset.js';

function fakeRoot() {
    const properties = new Map();

    return {
        dataset: {},
        style: {
            setProperty: (name, value) => properties.set(name, value),
            removeProperty: (name) => properties.delete(name)
        },
        properties
    };
}

function keyboardEvent(type, detail) {
    const event = new Event(type);

    event.detail = detail;

    return event;
}

test('offene Tastatur setzt Höhe und Zustand', () => {
    const target = new EventTarget();
    const root = fakeRoot();

    installKeyboardInset(target, root);
    target.dispatchEvent(keyboardEvent('ionKeyboardDidShow', { keyboardHeight: 336 }));

    assert.equal(root.properties.get('--keyboard-height'), '336px');
    assert.equal(root.dataset.keyboard, 'open');
});

test('Größenänderung der offenen Tastatur übernimmt die neue Höhe', () => {
    const target = new EventTarget();
    const root = fakeRoot();

    installKeyboardInset(target, root);
    target.dispatchEvent(keyboardEvent('ionKeyboardDidShow', { keyboardHeight: 336 }));
    target.dispatchEvent(keyboardEvent('ionKeyboardDidShow', { keyboardHeight: 380 }));

    assert.equal(root.properties.get('--keyboard-height'), '380px');
});

test('geschlossene Tastatur räumt beides weg', () => {
    const target = new EventTarget();
    const root = fakeRoot();

    installKeyboardInset(target, root);
    target.dispatchEvent(keyboardEvent('ionKeyboardDidShow', { keyboardHeight: 336 }));
    target.dispatchEvent(keyboardEvent('ionKeyboardDidHide'));

    assert.equal(root.properties.has('--keyboard-height'), false);
    assert.equal(root.dataset.keyboard, undefined);
});
