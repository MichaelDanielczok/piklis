/**
 * Zeile nach links wischen, um eine Aktion dahinter freizulegen (Designpaket
 * 06: „Nach links wischen → Nicht bekommen“). Pointer Events, ohne
 * Bibliothek; die Zeile hat `touch-action: pan-y`, senkrecht scrollt die
 * Seite wie immer.
 *
 * - Erst ab 8 px und überwiegend waagrecht gilt es als Wischen; dann folgt
 *   die Zeile dem Finger (nur nach links).
 * - Loslassen über die halbe Aktionsbreite: Aktion bleibt sichtbar zum
 *   Antippen. Weit gewischt (über 60 % der Zeile): Aktion sofort.
 * - Es ist immer höchstens eine Zeile offen; Tippen auf die Zeile schließt
 *   sie wieder, ohne das Produkt zu öffnen.
 *
 * Die Aktion ist nur ein Kurzweg: dieselbe Funktion steht im Produkt im
 * Listenkontext als Knopf (Tastatur, Bildschirmleser).
 */
import { onBeforeUnmount, ref, watch } from 'vue';

const THRESHOLD = 8;
const FULL_SWIPE = 0.6;

/** ID der gerade offenen Zeile (app-weit nur eine). */
const openRow = ref(null);

let nextId = 0;

/**
 * @param {{
 *   root: import('vue').Ref<HTMLElement|null>,
 *   action: import('vue').Ref<HTMLElement|null>,
 *   enabled: () => boolean,
 *   onAction: () => void
 * }} options
 */
export function useRowSwipe({ root, action, enabled, onAction }) {
    const id = ++nextId;
    const offset = ref(0);
    const dragging = ref(false);

    let start = null;
    let suppressClick = false;

    function actionWidth() {
        return action.value?.offsetWidth ?? 88;
    }

    function close() {
        offset.value = 0;

        if (openRow.value === id) {
            openRow.value = null;
        }
    }

    watch(openRow, (current) => {
        if (current !== id && offset.value !== 0 && !dragging.value) {
            offset.value = 0;
        }
    });

    function onPointerDown(event) {
        if (!enabled() || event.button > 0 || event.target.closest('[data-name="handle"], [data-name="swipe-action"]')) {
            return;
        }

        start = { x: event.clientX, y: event.clientY, pointerId: event.pointerId, base: offset.value, active: false };
    }

    function onPointerMove(event) {
        if (!start || event.pointerId !== start.pointerId) {
            return;
        }

        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;

        if (!start.active) {
            if (Math.abs(dx) < THRESHOLD && Math.abs(dy) < THRESHOLD) {
                return;
            }

            if (Math.abs(dy) >= Math.abs(dx) || (dx > 0 && start.base === 0)) {
                start = null;
                return;
            }

            start.active = true;
            dragging.value = true;
            openRow.value = id;
            root.value?.setPointerCapture?.(event.pointerId);
        }

        const width = root.value?.offsetWidth ?? 320;

        offset.value = Math.max(-width * 0.9, Math.min(0, start.base + dx));
    }

    function onPointerUp(event) {
        if (!start || event.pointerId !== start.pointerId) {
            return;
        }

        const wasActive = start.active;

        start = null;

        if (!wasActive) {
            return;
        }

        dragging.value = false;
        suppressClick = true;
        window.setTimeout(() => {
            suppressClick = false;
        }, 60);

        const width = root.value?.offsetWidth ?? 320;

        if (-offset.value > width * FULL_SWIPE) {
            close();
            onAction();
        } else if (-offset.value > actionWidth() / 2) {
            offset.value = -actionWidth();
        } else {
            close();
        }
    }

    function onPointerCancel() {
        if (start?.active) {
            dragging.value = false;
            offset.value = offset.value < -actionWidth() / 2 ? -actionWidth() : 0;
        }

        start = null;
    }

    /** Klick nach dem Wischen oder auf eine offene Zeile: nur schließen. */
    function onClickCapture(event) {
        if (event.target.closest('[data-name="swipe-action"]')) {
            return;
        }

        if (suppressClick) {
            // Klick am Ende des Wischens: weder Produkt öffnen noch schließen
            event.preventDefault();
            event.stopPropagation();
            suppressClick = false;
            return;
        }

        if (offset.value !== 0) {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }

        // eine andere Zeile ist offen: erst nur sie schließen (wie in iOS)
        if (openRow.value !== null && openRow.value !== id) {
            event.preventDefault();
            event.stopPropagation();
            openRow.value = null;
        }
    }

    function runAction() {
        close();
        onAction();
    }

    onBeforeUnmount(() => {
        if (openRow.value === id) {
            openRow.value = null;
        }
    });

    return {
        offset,
        dragging,
        close,
        runAction,
        listeners: {
            pointerdown: onPointerDown,
            pointermove: onPointerMove,
            pointerup: onPointerUp,
            pointercancel: onPointerCancel
        },
        onClickCapture
    };
}
