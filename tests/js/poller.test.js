/**
 * Live-Abgleich: Takt, Sichtbarkeit, keine zwei Läufe gleichzeitig.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPoller } from '@/utils/poller.js';

function fakeTimers() {
    const timers = new Map();
    let next = 1;

    return {
        setTimer(callback, delay) {
            const id = next++;
            timers.set(id, { callback, delay });
            return id;
        },
        clearTimer(id) {
            timers.delete(id);
        },
        pending() {
            return [...timers.values()];
        },
        async fire() {
            const [id, timer] = [...timers.entries()][0];
            timers.delete(id);
            await timer.callback();
        }
    };
}

test('fragt im Takt ab, erst nach dem Ende des vorigen Laufs', async () => {
    const timers = fakeTimers();
    let calls = 0;
    const poller = createPoller(() => { calls++; }, 5000, timers);

    poller.start();
    assert.equal(calls, 0, 'ohne immediate erst nach dem Takt');
    assert.deepEqual(timers.pending().map((timer) => timer.delay), [5000]);

    await timers.fire();
    assert.equal(calls, 1);
    assert.equal(timers.pending().length, 1, 'nächster Lauf geplant');

    poller.stop();
    assert.equal(timers.pending().length, 0);
});

test('verdeckt keine Abfrage, wieder sichtbar sofort eine', async () => {
    const timers = fakeTimers();
    let calls = 0;
    const poller = createPoller(() => { calls++; }, 5000, timers);

    poller.start();
    poller.setVisible(false);
    assert.equal(timers.pending().length, 0);

    await poller.setVisible(true);
    await Promise.resolve();
    assert.equal(calls, 1);
    assert.equal(timers.pending().length, 1);
});

test('nie zwei Läufe gleichzeitig, Fehler beenden den Takt nicht', async () => {
    const timers = fakeTimers();
    let calls = 0;
    let release;
    const errors = [];
    const poller = createPoller(() => {
        calls++;
        return new Promise((resolve, reject) => {
            release = () => reject(new Error('offline'));
        });
    }, 5000, { ...timers, onError: (error) => errors.push(error.message) });

    poller.start({ immediate: true });
    const second = poller.trigger();
    assert.equal(calls, 1);

    release();
    await second;
    assert.deepEqual(errors, ['offline']);
    assert.equal(timers.pending().length, 1, 'weiter im Takt');
});

test('gestoppt oder nie gestartet: keine Abfrage', async () => {
    const timers = fakeTimers();
    let calls = 0;
    const poller = createPoller(() => { calls++; }, 5000, timers);

    await poller.trigger();
    poller.setVisible(false);
    poller.setVisible(true);
    assert.equal(calls, 0);
    assert.equal(timers.pending().length, 0);
});
