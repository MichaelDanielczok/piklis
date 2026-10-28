/**
 * Wiederholte Abfrage für den Live-Abgleich (docs/architecture.md, „Sync“ ›
 * „Reading“): eine Aufgabe in festem Abstand, solange der Poller läuft und
 * die App sichtbar ist. Nie zwei Läufe gleichzeitig; der nächste beginnt
 * `interval` nach dem Ende des vorigen, damit ein langsamer Server nicht
 * zugeschüttet wird. Fehler (Netz weg) beenden ihn nicht, der nächste Lauf
 * versucht es wieder.
 *
 * Ohne Vue und ohne Browser-Ereignisse, damit `node --test` es mit
 * künstlichen Timern prüft; `composables/useLivePoll.js` hängt es an die
 * Ansicht und an `visibilitychange`.
 *
 * @param {() => Promise<unknown>|unknown} task
 * @param {number} interval Millisekunden
 * @param {{ setTimer?: Function, clearTimer?: Function, onError?: Function }} [options]
 */
export function createPoller(task, interval, {
    setTimer = (callback, delay) => setTimeout(callback, delay),
    clearTimer = (timer) => clearTimeout(timer),
    onError = () => {}
} = {}) {
    let running = false;
    let visible = true;
    let timer = null;
    let current = null;

    function clear() {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    }

    function schedule() {
        clear();

        if (running && visible) {
            timer = setTimer(run, interval);
        }
    }

    function run() {
        timer = null;

        if (!running || !visible) {
            return Promise.resolve();
        }

        if (current !== null) {
            return current;
        }

        current = (async () => {
            try {
                await task();
            } catch (error) {
                onError(error);
            }
        })().finally(() => {
            current = null;
            schedule();
        });

        return current;
    }

    return {
        /** Starten; `immediate` fragt sofort einmal ab, sonst erst nach `interval`. */
        start({ immediate = false } = {}) {
            if (running) {
                return;
            }

            running = true;

            if (immediate) {
                run();
            } else {
                schedule();
            }
        },

        stop() {
            running = false;
            clear();
        },

        /**
         * App sichtbar oder verdeckt. Verdeckt: keine Abfragen; wieder
         * sichtbar: sofort eine, dann im Takt.
         */
        setVisible(value) {
            const was = visible;

            visible = value;

            if (!visible) {
                clear();
            } else if (!was) {
                run();
            }
        },

        /** Außer der Reihe abfragen (z. B. wieder online); danach neuer Takt. */
        trigger() {
            clear();

            return run();
        },

        get running() {
            return running;
        }
    };
}
