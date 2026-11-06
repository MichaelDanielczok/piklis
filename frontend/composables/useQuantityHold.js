/**
 * Halten und Schieben für das Mengen-Control (Konzept „Mengen, Reihenfolge und
 * Wiederherstellen“). Pointer Events, ohne Bibliothek; `QuantityControl.vue`
 * zeigt den Zustand aus `control`.
 *
 * - Halten: Nach 350 ms an einer Stelle öffnet die Skala neben dem Finger.
 *   Bewegt sich der Finger vorher um 8 px, gilt es als Scrollen oder Wischen
 *   und es passiert nichts; Scrollen selbst beendet den Druck (`pointercancel`).
 * - Schieben: Je 20 px nach oben ein Schritt mehr, nach unten einer weniger.
 *   Loslassen speichert (`onCommit`) und schließt. Am Anfang oder Ende der
 *   Skala läuft der Bezugspunkt mit, Umkehren wirkt sofort.
 * - Ohne Schieben bleibt das Control offen (`mode: 'menu'`) mit Knöpfen; für
 *   Tastatur und Bildschirmleser öffnet `openMenu` denselben Zustand ohne Geste.
 *
 * Die Seite darf nach dem Halten nicht mitscrollen: `touch-action` wirkt nur
 * ab Beginn einer Berührung, deshalb sperrt ein nicht passiver `touchmove`
 * (Vue registriert an Elementen nicht passiv) das Scrollen, solange das Control
 * den Finger hat. Elemente mit `data-no-hold` (Ziehgriff, Abhaken) starten
 * nichts.
 */
import { onScopeDispose, ref } from 'vue';
import { steppedAmount } from '@/utils/quantity.js';

export const HOLD_MS = 350;
export const STEP_PX = 20;

const MOVE_TOLERANCE = 8;
// der Klick nach dem Loslassen kommt gleich danach; die nächste Berührung hebt die Sperre auf
const CLICK_GUARD_MS = 300;

/**
 * @param {{
 *   root: import('vue').Ref<HTMLElement|null>,
 *   enabled: () => boolean,
 *   amount: () => number,
 *   unit: () => string|null,
 *   removable?: () => boolean,
 *   onCommit: (amount: number) => void,
 *   onHold?: () => void
 * }} options `onHold`: das Control hat den Finger übernommen
 */
export function useQuantityHold({ root, enabled, amount, unit, removable = () => false, onCommit, onHold = () => {} }) {
    /** null oder `{ mode: 'hold'|'menu', x, y, draft, opener }`. */
    const control = ref(null);

    let press = null;
    let suppressClick = false;
    let guard = null;

    function clearGuard() {
        clearTimeout(guard);
        suppressClick = false;
    }

    function dropPress() {
        if (press) {
            clearTimeout(press.timer);
            press = null;
        }
    }

    function begin() {
        press.active = true;
        press.anchorY = press.y;
        press.amount = amount();

        control.value = { mode: 'hold', x: press.x, y: press.y, draft: press.amount, opener: null };

        onHold();
        globalThis.navigator?.vibrate?.(8);

        try {
            root.value?.setPointerCapture?.(press.pointerId);
        } catch {
            // ohne Erfassung (künstliches Ereignis): Bewegungen kommen nur über dem Element an
        }
    }

    function onPointerDown(event) {
        clearGuard();

        if (press || control.value || !enabled() || event.button > 0 || event.isPrimary === false || event.target.closest('[data-no-hold]')) {
            return;
        }

        press = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, active: false, moved: false };
        press.timer = setTimeout(begin, HOLD_MS);
    }

    function follow(y) {
        let distance = press.anchorY - y;

        while (Math.abs(distance) >= STEP_PX) {
            const direction = Math.sign(distance);

            press.moved = true;
            press.anchorY -= direction * STEP_PX;
            distance -= direction * STEP_PX;
            control.value.draft = steppedAmount(control.value.draft, unit(), direction, { removable: removable() });
        }
    }

    function onPointerMove(event) {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }

        if (press.active) {
            follow(event.clientY);
            return;
        }

        if (Math.hypot(event.clientX - press.x, event.clientY - press.y) >= MOVE_TOLERANCE) {
            dropPress();
        }
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
            control.value.mode = 'menu';
            return;
        }

        const { draft } = control.value;

        control.value = null;

        if (draft !== before) {
            onCommit(draft);
        }
    }

    /** Vom System abgebrochen: nichts speichern. */
    function onPointerCancel(event) {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }

        if (press.active) {
            control.value = null;
        }

        dropPress();
    }

    function onTouchMove(event) {
        if (press?.active && event.cancelable) {
            event.preventDefault();
        }
    }

    /** Android meldet langes Drücken als Kontextmenü. */
    function onContextMenu(event) {
        if (press || control.value) {
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

    /**
     * Ohne Geste öffnen; der Fokus kehrt beim Schließen zu `opener` zurück.
     *
     * @param {HTMLElement} opener
     */
    function openMenu(opener) {
        const rect = opener.getBoundingClientRect();

        control.value = {
            mode: 'menu',
            x: rect.left + rect.width / 2,
            y: rect.top + rect.height / 2,
            draft: amount(),
            opener
        };
    }

    function close() {
        control.value = null;
    }

    onScopeDispose(() => {
        dropPress();
        clearGuard();
    });

    return {
        control,
        openMenu,
        close,
        listeners: {
            pointerdown: onPointerDown,
            pointermove: onPointerMove,
            pointerup: onPointerUp,
            pointercancel: onPointerCancel,
            touchmove: onTouchMove,
            contextmenu: onContextMenu
        },
        onClickCapture
    };
}
