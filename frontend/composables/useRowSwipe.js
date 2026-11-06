/**
 * Zeile nach links wischen, um die Aktionen dahinter freizulegen (Konzept
 * „Abhaken und Nicht bekommen“). Pointer Events, ohne Bibliothek; die Zeile
 * hat `touch-action: pan-y`, senkrecht scrollt die Seite wie immer.
 *
 * - Erst ab 8 px und überwiegend waagrecht gilt es als Wischen; dann folgt
 *   die Zeile dem Finger, nur nach links und höchstens so weit, wie die
 *   Aktionen breit sind. Wischen nach rechts ist nicht belegt (Zurück-Geste).
 * - Loslassen über die halbe Aktionsbreite: Aktionen bleiben sichtbar zum
 *   Antippen. Wischen selbst löst nie etwas aus, auch nicht weites Wischen.
 * - Es ist immer höchstens eine Zeile offen; Tippen auf die Zeile schließt
 *   sie wieder, ohne das Produkt zu öffnen.
 *
 * Die Aktionen sind nur ein Kurzweg: dieselben Funktionen stehen im Produkt
 * im Listenkontext als Knöpfe (Tastatur, Bildschirmleser).
 */
import { onScopeDispose, ref, watch } from 'vue';

const THRESHOLD = 8;

/** ID der gerade offenen Zeile (app-weit nur eine). */
const openRow = ref(null);

let nextId = 0;

/**
 * @param {{
 *   root: import('vue').Ref<HTMLElement|null>,
 *   actions: import('vue').Ref<HTMLElement|null>,
 *   enabled: () => boolean
 * }} options `actions`: Element mit allen Aktionen, seine Breite ist der Wischweg
 */
export function useRowSwipe({ root, actions, enabled }) {
    const id = ++nextId;
    const offset = ref(0);
    const dragging = ref(false);

    let start = null;
    let suppressClick = false;

    function actionsWidth() {
        return actions.value?.offsetWidth ?? 0;
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

    /** Das Mengen-Control übernimmt den Finger: kein Wischen mehr, offene Zeile schließt sich. */
    function release() {
        start = null;
        openRow.value = null;
    }

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

        offset.value = Math.max(-actionsWidth(), Math.min(0, start.base + dx));
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
        setTimeout(() => {
            suppressClick = false;
        }, 60);

        if (-offset.value > actionsWidth() / 2) {
            offset.value = -actionsWidth();
        } else {
            close();
        }
    }

    function onPointerCancel() {
        if (start?.active) {
            dragging.value = false;
            offset.value = offset.value < -actionsWidth() / 2 ? -actionsWidth() : 0;
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

    onScopeDispose(() => {
        if (openRow.value === id) {
            openRow.value = null;
        }
    });

    return {
        offset,
        dragging,
        close,
        release,
        listeners: {
            pointerdown: onPointerDown,
            pointermove: onPointerMove,
            pointerup: onPointerUp,
            pointercancel: onPointerCancel
        },
        onClickCapture
    };
}
