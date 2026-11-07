/**
 * Halten-Geste für Listenzeile, Kachel und Vorlage (Pointer Events, ohne
 * Bibliothek). `QuantityLayer` und `ActionMenu` zeigen, was sie auslöst:
 *
 * - Finger runter, 380 ms still: Die Mengen-Ebene öffnet (`held`). Bewegt er
 *   sich vorher um 10 px, gilt es als Scrollen oder Wischen; hebt er ab, als Tipp.
 * - Ziehen über 6 px: Die Menge folgt dem Finger stufenlos, Loslassen rastet ein
 *   und meldet `onCommit(amount, before)`. Gleiche Stufe meldet nichts.
 * - Loslassen ohne Ziehen: Die Ebene schließt, das Aktionsmenü öffnet (`menu`).
 * - Vom System abgebrochen: Ebene zu, nichts geändert.
 *
 * Bewegen und Loslassen hört das Fenster ab, solange der Finger unten ist: Ohne
 * Bewegung meldet iOS das Loslassen dem Element unter dem Finger, und das ist
 * dann die Mengen-Ebene, nicht mehr die Zeile.
 *
 * Die Seite darf nach dem Halten nicht mitscrollen: `touch-action` wirkt nur ab
 * Beginn einer Berührung, deshalb sperrt ein nicht passiver `touchmove` (Vue
 * registriert an Elementen nicht passiv) das Scrollen, solange die Ebene den
 * Finger hat. Elemente mit `data-no-hold` (Ziehgriff, Abhaken) starten nichts.
 */
import { onScopeDispose, shallowRef } from 'vue';
import { HOLD } from '@/utils/quantityScale.js';

// der Klick nach dem Loslassen kommt gleich danach; die nächste Berührung hebt die Sperre auf
const CLICK_GUARD_MS = 300;

/**
 * @param {{
 *   root: import('vue').Ref<HTMLElement|null>,
 *   layer: import('vue').Ref<{ dragTo: Function, release: Function, close: Function }|null>,
 *   enabled: () => boolean,
 *   amount: () => number,
 *   onCommit: (amount: number, before: number) => void,
 *   onHold?: () => void
 * }} options `onHold`: die Ebene hat den Finger übernommen
 */
export function useHoldGesture({ root, layer, enabled, amount, onCommit, onHold = () => {} }) {
    /** null oder `{ rect, amount }`, solange die Mengen-Ebene steht. */
    const held = shallowRef(null);
    /** null oder `{ opener }`, solange das Aktionsmenü steht. */
    const menu = shallowRef(null);

    let press = null;
    let suppressClick = false;
    let guard = null;

    function clearGuard() {
        clearTimeout(guard);
        suppressClick = false;
    }

    const pointer = { move: onPointerMove, up: onPointerUp, cancel: onPointerCancel };

    function follow(on) {
        const method = on ? 'addEventListener' : 'removeEventListener';

        globalThis.window?.[method]('pointermove', onPointerMove, true);
        globalThis.window?.[method]('pointerup', onPointerUp, true);
        globalThis.window?.[method]('pointercancel', onPointerCancel, true);
    }

    function dropPress() {
        if (press) {
            clearTimeout(press.timer);
            press = null;
            follow(false);
        }
    }

    function begin() {
        // erst jetzt zählt ein angefangenes Wischen: Die Zeile hat sich dann schon bewegt
        if (!enabled()) {
            dropPress();
            return;
        }

        press.active = true;
        press.startY = press.lastY;
        press.amount = amount();

        held.value = { rect: root.value?.getBoundingClientRect?.() ?? null, amount: press.amount };

        onHold();
        globalThis.navigator?.vibrate?.(HOLD.vibrateHold);

        try {
            root.value?.setPointerCapture?.(press.pointerId);
        } catch {
            // ohne Erfassung (künstliches Ereignis): Bewegungen kommen nur über dem Element an
        }
    }

    function onPointerDown(event) {
        clearGuard();

        if (press || held.value || menu.value || !enabled() || event.button > 0 || event.isPrimary === false || event.target.closest('[data-no-hold]')) {
            return;
        }

        press = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, lastY: event.clientY, active: false, moved: false };
        press.timer = setTimeout(begin, HOLD.holdMs);
        follow(true);
    }

    function onPointerMove(event) {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }

        press.lastY = event.clientY;

        if (!press.active) {
            if (Math.hypot(event.clientX - press.x, event.clientY - press.y) > HOLD.slopPx) {
                dropPress();
            }

            return;
        }

        const dragged = press.startY - event.clientY;

        if (Math.abs(dragged) > HOLD.moveCommitPx) {
            press.moved = true;
        }

        if (press.moved) {
            layer.value?.dragTo(dragged);
        }
    }

    function endHeld(done) {
        const current = layer.value;

        if (current) {
            current.close(done);
        } else {
            done();
        }
    }

    function openActions() {
        held.value = null;
        menu.value = { opener: null };
    }

    function onPointerUp(event) {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }

        const { active, moved, amount: before } = press;

        dropPress();

        if (!active) {
            return;
        }

        suppressClick = true;
        guard = setTimeout(clearGuard, CLICK_GUARD_MS);

        if (!moved) {
            endHeld(openActions);
            return;
        }

        const current = layer.value;

        if (!current) {
            held.value = null;
            return;
        }

        current.release((value) => {
            held.value = null;

            if (value !== null) {
                onCommit(value, before);
            }
        });
    }

    /** Vom System abgebrochen: nichts speichern. */
    function onPointerCancel(event) {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }

        const { active } = press;

        dropPress();

        if (active) {
            endHeld(() => {
                held.value = null;
            });
        }
    }

    function onTouchMove(event) {
        if (press?.active && event.cancelable) {
            event.preventDefault();
        }
    }

    /** Android meldet langes Drücken als Kontextmenü, die Maus den rechten Klick. */
    function onContextMenu(event) {
        if (press || held.value) {
            event.preventDefault();
        }
    }

    /** Klick am Ende der Geste: nicht als Tipp werten. */
    function onClickCapture(event) {
        if (suppressClick) {
            event.preventDefault();
            event.stopPropagation();
            clearGuard();
        }
    }

    /** Kontextmenü-Taste oder Umschalt+F10: Die Geste geht mit der Tastatur nicht. */
    function onKeyDown(event) {
        if (enabled() && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) {
            event.preventDefault();
            openMenu(event.target);
        }
    }

    /** Das Mausrad zählt wie Ziehen (Desktop). */
    function markMoved() {
        if (press?.active) {
            press.moved = true;
        }
    }

    /**
     * Ohne Geste öffnen; der Fokus kehrt beim Schließen zu `opener` zurück.
     *
     * @param {HTMLElement} opener
     */
    function openMenu(opener) {
        menu.value = { opener };
    }

    function closeMenu() {
        menu.value = null;
    }

    onScopeDispose(() => {
        dropPress();
        clearGuard();
    });

    return {
        held,
        menu,
        openMenu,
        closeMenu,
        markMoved,
        pointer,
        listeners: {
            pointerdown: onPointerDown,
            touchmove: onTouchMove,
            contextmenu: onContextMenu,
            keydown: onKeyDown
        },
        onClickCapture
    };
}
