/**
 * Wischen einer Listenzeile: Knöpfe freilegen, ohne dass Wischen etwas auslöst.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, nextTick, ref } from 'vue';
import { useRowSwipe } from '@/composables/useRowSwipe.js';

const ACTIONS_WIDTH = 176;

function row() {
    const scope = effectScope();
    const swipe = scope.run(() => useRowSwipe({
        root: ref({ offsetWidth: 358 }),
        actions: ref({ offsetWidth: ACTIONS_WIDTH }),
        enabled: () => true
    }));

    return { ...swipe, stop: () => scope.stop() };
}

function pointer(clientX, clientY = 100) {
    return { button: 0, pointerId: 1, clientX, clientY, target: { closest: () => null } };
}

/** Finger von 300 nach `to`, ohne loszulassen. */
function drag({ listeners }, to) {
    listeners.pointerdown(pointer(300));
    listeners.pointermove(pointer(290));
    listeners.pointermove(pointer(to));
}

test('releasing past half of the actions keeps them open', () => {
    const swipe = row();

    drag(swipe, 200);
    swipe.listeners.pointerup(pointer(200));

    assert.equal(swipe.offset.value, -ACTIONS_WIDTH);
    swipe.stop();
});

test('a short swipe closes again', () => {
    const swipe = row();

    drag(swipe, 240);
    swipe.listeners.pointerup(pointer(240));

    assert.equal(swipe.offset.value, 0);
    swipe.stop();
});

test('a far swipe stops at the actions and stays open', () => {
    const swipe = row();

    drag(swipe, -200);
    assert.equal(swipe.offset.value, -ACTIONS_WIDTH);

    swipe.listeners.pointerup(pointer(-200));
    assert.equal(swipe.offset.value, -ACTIONS_WIDTH);
    swipe.stop();
});

test('swiping to the right does nothing on a closed row', () => {
    const swipe = row();

    swipe.listeners.pointerdown(pointer(100));
    swipe.listeners.pointermove(pointer(160));
    swipe.listeners.pointerup(pointer(160));

    assert.equal(swipe.offset.value, 0);
    assert.equal(swipe.dragging.value, false);
    swipe.stop();
});

test('mostly vertical movement is left to scrolling', () => {
    const swipe = row();

    swipe.listeners.pointerdown(pointer(300, 100));
    swipe.listeners.pointermove(pointer(295, 160));
    swipe.listeners.pointermove(pointer(200, 160));

    assert.equal(swipe.offset.value, 0);
    swipe.stop();
});

test('swiping an open row back to the right closes it', () => {
    const swipe = row();

    drag(swipe, 100);
    swipe.listeners.pointerup(pointer(100));

    swipe.listeners.pointerdown(pointer(100));
    swipe.listeners.pointermove(pointer(120));
    swipe.listeners.pointermove(pointer(280));
    swipe.listeners.pointerup(pointer(280));

    assert.equal(swipe.offset.value, 0);
    swipe.stop();
});

test('opening a row closes the one before', async () => {
    const first = row();
    const second = row();

    drag(first, 100);
    first.listeners.pointerup(pointer(100));
    drag(second, 100);
    second.listeners.pointerup(pointer(100));
    await nextTick();

    assert.equal(first.offset.value, 0);
    assert.equal(second.offset.value, -ACTIONS_WIDTH);
    first.stop();
    second.stop();
});
