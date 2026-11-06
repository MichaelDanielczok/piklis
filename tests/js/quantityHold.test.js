/**
 * Halten und Schieben: Das Mengen-Control darf Scrollen und Tippen nicht stören.
 */
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { effectScope, ref, toRaw } from 'vue';
import { HOLD_MS, STEP_PX, useQuantityHold } from '@/composables/useQuantityHold.js';

function setup({ amount = 2, unit = null, removable = false } = {}) {
    mock.timers.enable({ apis: ['setTimeout'] });

    const commits = [];
    const holds = [];
    const scope = effectScope();
    const hold = scope.run(() => useQuantityHold({
        root: ref({ setPointerCapture() {} }),
        enabled: () => true,
        amount: () => amount,
        unit: () => unit,
        removable: () => removable,
        onCommit: (value) => commits.push(value),
        onHold: () => holds.push(true)
    }));

    return {
        ...hold,
        commits,
        holds,
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
    mock.timers.tick(HOLD_MS);
}

test('the control opens after holding still', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer());
    mock.timers.tick(HOLD_MS - 1);
    assert.equal(hold.control.value, null);

    mock.timers.tick(1);
    assert.equal(hold.control.value.mode, 'hold');
    assert.equal(hold.holds.length, 1);
    hold.stop();
});

test('moving before the hold counts as scrolling or swiping', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer(100));
    hold.listeners.pointermove(pointer(92));
    mock.timers.tick(HOLD_MS);

    assert.equal(hold.control.value, null);
    hold.stop();
});

test('a scroll taken over by the browser ends the press', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer());
    hold.listeners.pointercancel(pointer());
    mock.timers.tick(HOLD_MS);

    assert.equal(hold.control.value, null);
    hold.stop();
});

test('a quick tap stays a tap', () => {
    const hold = setup();
    const event = { preventDefault() { assert.fail('tap suppressed'); }, stopPropagation() {} };

    hold.listeners.pointerdown(pointer());
    hold.listeners.pointerup(pointer());
    hold.onClickCapture(event);
    mock.timers.tick(HOLD_MS);

    assert.equal(hold.control.value, null);
    hold.stop();
});

test('elements marked data-no-hold start nothing', () => {
    const hold = setup();

    hold.listeners.pointerdown(pointer(100, { target: { closest: () => ({}) } }));
    mock.timers.tick(HOLD_MS);

    assert.equal(hold.control.value, null);
    hold.stop();
});

test('sliding up adds, releasing saves and closes', () => {
    const hold = setup();

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 - 3 * STEP_PX));
    assert.equal(hold.control.value.draft, 5);
    assert.deepEqual(hold.commits, []);

    hold.listeners.pointerup(pointer(100 - 3 * STEP_PX));
    assert.deepEqual(hold.commits, [5]);
    assert.equal(hold.control.value, null);
    hold.stop();
});

test('steps follow the unit', () => {
    const hold = setup({ amount: 300, unit: 'g' });

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 + 2 * STEP_PX));
    hold.listeners.pointerup(pointer(100 + 2 * STEP_PX));

    assert.deepEqual(hold.commits, [200]);
    hold.stop();
});

test('rows stop at the minimum and react at once when turning back', () => {
    const hold = setup({ amount: 1 });

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 + 5 * STEP_PX));
    assert.equal(hold.control.value.draft, 1);

    hold.listeners.pointermove(pointer(100 + 4 * STEP_PX));
    assert.equal(hold.control.value.draft, 2);
    hold.stop();
});

test('tiles reach zero when removable', () => {
    const hold = setup({ amount: 1, removable: true });

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 + STEP_PX));
    hold.listeners.pointerup(pointer(100 + STEP_PX));

    assert.deepEqual(hold.commits, [0]);
    hold.stop();
});

test('sliding back to the start saves nothing but still closes', () => {
    const hold = setup();

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 - STEP_PX));
    hold.listeners.pointermove(pointer(100));
    hold.listeners.pointerup(pointer(100));

    assert.deepEqual(hold.commits, []);
    assert.equal(hold.control.value, null);
    hold.stop();
});

test('releasing without sliding keeps the control open with buttons', () => {
    const hold = setup();

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 - STEP_PX + 1));
    hold.listeners.pointerup(pointer(100 - STEP_PX + 1));

    assert.equal(hold.control.value.mode, 'menu');
    assert.deepEqual(hold.commits, []);
    hold.stop();
});

test('the click after the gesture is not a tap', () => {
    const hold = setup();
    let stopped = false;

    holdDown(hold);
    hold.listeners.pointerup(pointer());
    hold.onClickCapture({ preventDefault() {}, stopPropagation() { stopped = true; } });

    assert.equal(stopped, true);
    hold.stop();
});

test('a cancelled gesture saves nothing', () => {
    const hold = setup();

    holdDown(hold);
    hold.listeners.pointermove(pointer(100 - STEP_PX));
    hold.listeners.pointercancel(pointer());

    assert.deepEqual(hold.commits, []);
    assert.equal(hold.control.value, null);
    hold.stop();
});

test('only the held control blocks scrolling', () => {
    const hold = setup();
    let prevented = 0;
    const touch = { cancelable: true, preventDefault() { prevented++; } };

    hold.listeners.pointerdown(pointer());
    hold.listeners.touchmove(touch);
    assert.equal(prevented, 0);

    mock.timers.tick(HOLD_MS);
    hold.listeners.touchmove(touch);
    assert.equal(prevented, 1);
    hold.stop();
});

test('without the gesture the menu opens directly at the element', () => {
    const hold = setup();
    const opener = { getBoundingClientRect: () => ({ left: 10, top: 20, width: 40, height: 60 }) };

    hold.openMenu(opener);

    assert.equal(hold.control.value.mode, 'menu');
    assert.equal(hold.control.value.x, 30);
    assert.equal(hold.control.value.y, 50);
    assert.equal(toRaw(hold.control.value.opener), opener);
    hold.stop();
});
