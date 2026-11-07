/**
 * Halten-Geste: Scrollen und Tippen dürfen nicht gestört werden, Ziehen ändert
 * die Menge, Halten und Loslassen öffnet das Aktionsmenü.
 */
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, ref } from 'vue';
import { useHoldGesture } from '@/composables/useHoldGesture.js';
import { HOLD } from '@/utils/quantityScale.js';

function setup({ enabled = () => true, released = 3 } = {}) {
    mock.timers.enable({ apis: ['setTimeout'] });

    const commits = [];
    const drags = [];
    const holds = [];
    const closes = [];
    const layer = ref({
        dragTo: (distance) => drags.push(distance),
        release: (done) => done(released),
        close: (done) => {
            closes.push(true);
            done();
        }
    });
    const scope = effectScope();
    const hold = scope.run(() => useHoldGesture({
        root: ref({ setPointerCapture() {}, getBoundingClientRect: () => ({ top: 10 }) }),
        layer,
        enabled,
        amount: () => 2,
        onCommit: (amount, before) => commits.push([amount, before]),
        onHold: () => holds.push(true)
    }));

    return {
        ...hold,
        commits,
        drags,
        holds,
        closes,
        stop() {
            scope.stop();
            mock.timers.reset();
        }
    };
}

function pointer(clientY = 100, { clientX = 100, target = { closest: () => null } } = {}) {
    return { button: 0, pointerId: 1, isPrimary: true, clientX, clientY, target };
}

function holdDown({ listeners }, y = 100) {
    listeners.pointerdown(pointer(y));
    mock.timers.tick(HOLD.holdMs);
}

test('the layer opens after holding still', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer());
    mock.timers.tick(HOLD.holdMs - 1);
    assert.equal(hold.held.value, null);

    mock.timers.tick(1);
    assert.equal(hold.held.value.amount, 2);
    assert.equal(hold.held.value.rect.top, 10);
    assert.equal(hold.holds.length, 1);
    hold.stop();
});

test('moving before the hold counts as scrolling or swiping', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer());
    hold.pointer.move(pointer(100 + HOLD.slopPx));
    mock.timers.tick(10);
    assert.equal(hold.held.value === null, true);
    hold.pointer.move(pointer(101 + HOLD.slopPx));
    mock.timers.tick(HOLD.holdMs);
    assert.equal(hold.held.value, null);
    hold.stop();
});

test('a swipe that started in the meantime wins', () => {
    let swiped = false;
    const hold = setup({ enabled: () => !swiped });

    hold.listeners.pointerdown(pointer());
    swiped = true;
    mock.timers.tick(HOLD.holdMs);
    assert.equal(hold.held.value, null);
    hold.stop();
});

test('lifting before the hold stays a plain tap', () => {
    const hold = setup();
    const click = { preventDefault: mock.fn(), stopPropagation: mock.fn() };

    hold.listeners.pointerdown(pointer());
    hold.pointer.up(pointer());
    mock.timers.tick(HOLD.holdMs);
    hold.onClickCapture(click);

    assert.equal(hold.held.value, null);
    assert.equal(click.preventDefault.mock.callCount(), 0);
    hold.stop();
});

test('dragging follows the finger and commits on release', () => {
    const hold = setup();

    holdDown(hold);
    hold.pointer.move(pointer(100 - HOLD.moveCommitPx));
    assert.deepEqual(hold.drags, []);

    hold.pointer.move(pointer(70));
    hold.pointer.up(pointer(70));

    assert.deepEqual(hold.drags, [30]);
    assert.deepEqual(hold.commits, [[3, 2]]);
    assert.equal(hold.held.value, null);
    assert.equal(hold.menu.value, null);
    hold.stop();
});

test('the same step commits nothing', () => {
    const hold = setup({ released: null });

    holdDown(hold);
    hold.pointer.move(pointer(80));
    hold.pointer.up(pointer(80));

    assert.deepEqual(hold.commits, []);
    hold.stop();
});

test('releasing without dragging opens the action menu', () => {
    const hold = setup();

    holdDown(hold);
    hold.pointer.move(pointer(100 - HOLD.moveCommitPx));
    hold.pointer.up(pointer(100 - HOLD.moveCommitPx));

    assert.equal(hold.closes.length, 1);
    assert.equal(hold.held.value, null);
    assert.deepEqual(hold.menu.value, { opener: null });
    assert.deepEqual(hold.commits, []);
    hold.stop();
});

test('the click after the gesture opens nothing', () => {
    const hold = setup();
    const click = { preventDefault: mock.fn(), stopPropagation: mock.fn() };

    holdDown(hold);
    hold.pointer.up(pointer());
    hold.onClickCapture(click);

    assert.equal(click.preventDefault.mock.callCount(), 1);
    assert.equal(click.stopPropagation.mock.callCount(), 1);
    hold.stop();
});

test('a cancelled gesture changes nothing', () => {
    const hold = setup();

    holdDown(hold);
    hold.pointer.move(pointer(40));
    hold.pointer.cancel(pointer(40));

    assert.equal(hold.held.value, null);
    assert.equal(hold.menu.value, null);
    assert.deepEqual(hold.commits, []);
    hold.stop();
});

test('the page does not scroll while the layer has the finger', () => {
    const hold = setup();
    const event = { cancelable: true, preventDefault: mock.fn() };

    hold.listeners.touchmove(event);
    assert.equal(event.preventDefault.mock.callCount(), 0);

    holdDown(hold);
    hold.listeners.touchmove(event);
    assert.equal(event.preventDefault.mock.callCount(), 1);
    hold.stop();
});

test('handle and check area start nothing', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer(100, { target: { closest: () => ({}) } }));
    mock.timers.tick(HOLD.holdMs);

    assert.equal(hold.held.value, null);
    hold.stop();
});

test('the wheel counts as dragging', () => {
    const hold = setup();

    holdDown(hold);
    hold.markMoved();
    hold.pointer.up(pointer());

    assert.deepEqual(hold.commits, [[3, 2]]);
    hold.stop();
});

test('the context menu key opens the actions without a gesture', () => {
    const hold = setup();
    const opener = {};
    const event = { key: 'ContextMenu', target: opener, preventDefault: mock.fn() };

    hold.listeners.keydown(event);

    assert.equal(event.preventDefault.mock.callCount(), 1);
    assert.equal(hold.menu.value.opener, opener);

    hold.closeMenu();
    hold.listeners.keydown({ key: 'F10', shiftKey: true, target: opener, preventDefault() {} });
    assert.equal(hold.menu.value.opener, opener);
    hold.stop();
});
