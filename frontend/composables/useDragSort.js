/**
 * Sortieren am Ziehgriff mit Finger, Stift oder Maus (Pointer Events, ohne
 * Bibliothek). Nur der Griff startet das Ziehen; er hat `touch-action:
 * none`, sonst scrollt die Seite stattdessen.
 *
 * Während des Ziehens arbeitet das Modul direkt am DOM (Transformationen),
 * nicht über Vue: so rendert die Liste nicht bei jeder Fingerbewegung neu.
 * Die angehobene Zeile folgt dem Finger, die anderen weichen aus, ein grün
 * umrandeter Platzhalter zeigt das Ziel. Am Rand des Scrollbereichs scrollt
 * die Seite mit.
 *
 * Beim Loslassen ruft es `onDrop(id, from, to)` auf und wartet, bis die neue
 * Reihenfolge im DOM steht; dann fällt die Zeile vom Finger an ihren Platz,
 * ohne dass etwas springt.
 *
 * Zeilen: direkte Kinder der Liste mit `data-entry-id`.
 * CSS-Klassen (global, siehe ListView): `is-sorting` an der Liste,
 * `is-lifted` an der Zeile, `drag-placeholder` für den Platzhalter.
 */
import { onBeforeUnmount, ref } from 'vue';

const EDGE = 72; // px am oberen und unteren Rand, in denen mitgescrollt wird
const MAX_SPEED = 14; // px je Bild
const SETTLE_MS = 160;

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/**
 * @param {{
 *   list: import('vue').Ref<HTMLElement|null>,
 *   onDrop: (id: string, from: number, to: number) => Promise<void>|void
 * }} options
 */
export function useDragSort({ list, onDrop }) {
    const dragging = ref(false);

    let drag = null;

    function rowsOf(element) {
        return [...element.children].filter((child) => child.dataset?.entryId);
    }

    function scrollerOf(element) {
        const content = element.closest('ion-content');

        return content?.shadowRoot?.querySelector('.inner-scroll') ?? null;
    }

    function targetIndex(center) {
        const { slots, from } = drag;
        let to = from;

        for (let i = from + 1; i < slots.length; i++) {
            if (center > slots[i].top + slots[i].height / 2) {
                to = i;
            }
        }

        for (let i = from - 1; i >= 0; i--) {
            if (center < slots[i].top + slots[i].height / 2) {
                to = i;
            }
        }

        return to;
    }

    function layout() {
        const { element, rows, slots, from, startY, lastY, placeholder } = drag;
        const listTop = element.getBoundingClientRect().top;
        const own = slots[from];
        const last = slots[slots.length - 1];

        let offset = lastY - listTop - startY;
        offset = Math.max(-own.top, Math.min(offset, last.top + last.height - own.top - own.height));

        const to = targetIndex(own.top + own.height / 2 + offset);

        drag.offset = offset;
        drag.to = to;

        rows.forEach((row, index) => {
            if (index === from) {
                row.style.transform = `translateY(${offset}px)`;
                return;
            }

            let shift = 0;

            if (from < to && index > from && index <= to) {
                shift = -own.height;
            } else if (to < from && index >= to && index < from) {
                shift = own.height;
            }

            row.style.transform = shift ? `translateY(${shift}px)` : '';
        });

        const top = to <= from ? slots[to].top : slots[to].top + slots[to].height - own.height;

        placeholder.style.top = top + element.offsetTop + 'px';
        placeholder.style.height = own.height + 'px';
    }

    function autoScroll() {
        if (!drag) {
            return;
        }

        const { scroller } = drag;

        if (scroller) {
            const rect = scroller.getBoundingClientRect();
            const paddingBottom = parseFloat(getComputedStyle(scroller).paddingBottom) || 0;
            const top = rect.top + EDGE;
            const bottom = rect.bottom - paddingBottom - EDGE;
            let speed = 0;

            if (drag.lastY < top) {
                speed = -MAX_SPEED * Math.min(1, (top - drag.lastY) / EDGE);
            } else if (drag.lastY > bottom) {
                speed = MAX_SPEED * Math.min(1, (drag.lastY - bottom) / EDGE);
            }

            if (speed !== 0) {
                const before = scroller.scrollTop;
                scroller.scrollTop = before + speed;

                if (scroller.scrollTop !== before) {
                    layout();
                }
            }
        }

        drag.frame = requestAnimationFrame(autoScroll);
    }

    function onMove(event) {
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }

        event.preventDefault();
        drag.lastY = event.clientY;
        layout();
    }

    function detach(state) {
        const { handle } = state;

        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onEnd);
        handle.removeEventListener('pointercancel', onCancel);
        handle.removeEventListener('lostpointercapture', onCancel);
        cancelAnimationFrame(state.frame);
    }

    function cleanUp(state) {
        state.element.classList.remove('is-sorting');
        state.placeholder.remove();

        for (const row of state.rows) {
            row.style.transform = '';
            row.classList.remove('is-lifted');
        }

        dragging.value = false;
    }

    /** Zeile gleitet aus ihrer sichtbaren Lage an den Platz im Layout. */
    function settle(row, visualTop) {
        const delta = visualTop - row.getBoundingClientRect().top;

        if (Math.abs(delta) < 1 || prefersReducedMotion()) {
            row.classList.remove('is-lifted');
            return;
        }

        const animation = row.animate(
            [{ transform: `translateY(${delta}px)` }, { transform: 'translateY(0)' }],
            { duration: SETTLE_MS, easing: 'cubic-bezier(0.2, 0, 0, 1)' }
        );

        animation.finished
            .catch(() => {})
            .finally(() => row.classList.remove('is-lifted'));
    }

    async function finish(cancelled) {
        if (!drag) {
            return;
        }

        const state = drag;
        drag = null;
        detach(state);

        const row = state.rows[state.from];
        const visualTop = row.getBoundingClientRect().top;

        if (cancelled || state.to === state.from) {
            // ohne neue Reihenfolge: alles zurück (Übergang aus der Klasse is-sorting)
            for (const item of state.rows) {
                item.style.transform = '';
            }

            state.placeholder.remove();
            window.setTimeout(() => cleanUp(state), SETTLE_MS);

            return;
        }

        try {
            await onDrop(row.dataset.entryId, state.from, state.to);
        } finally {
            // neue Reihenfolge steht im DOM: Verschiebungen weg, ohne Übergang
            state.element.classList.remove('is-sorting');

            for (const item of state.rows) {
                item.style.transform = '';
            }

            state.placeholder.remove();
            dragging.value = false;
            settle(row, visualTop);
        }
    }

    function onEnd(event) {
        if (drag && event.pointerId === drag.pointerId) {
            finish(false);
        }
    }

    function onCancel(event) {
        if (drag && event.pointerId === drag.pointerId) {
            finish(true);
        }
    }

    /**
     * Am `pointerdown` des Griffs aufrufen.
     *
     * @param {PointerEvent} event
     * @param {string} id Eintrag der Zeile
     */
    function start(event, id) {
        const element = list.value?.$el ?? list.value;

        if (drag || !element || (event.pointerType === 'mouse' && event.button !== 0)) {
            return;
        }

        const rows = rowsOf(element);
        const from = rows.findIndex((row) => row.dataset.entryId === id);

        if (from === -1 || rows.length < 2) {
            return;
        }

        event.preventDefault();

        const handle = event.currentTarget;
        const listTop = element.getBoundingClientRect().top;
        const placeholder = document.createElement('div');

        placeholder.className = 'drag-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        element.after(placeholder);

        drag = {
            element,
            handle,
            rows,
            from,
            to: from,
            offset: 0,
            pointerId: event.pointerId,
            slots: rows.map((row) => ({ top: row.offsetTop, height: row.offsetHeight })),
            startY: event.clientY - listTop,
            lastY: event.clientY,
            placeholder,
            scroller: scrollerOf(element),
            frame: 0
        };

        try {
            handle.setPointerCapture(event.pointerId);
        } catch {
            // ohne Erfassung (z. B. künstliches Ereignis): Bewegungen kommen nur über dem Griff an
        }

        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onEnd);
        handle.addEventListener('pointercancel', onCancel);
        handle.addEventListener('lostpointercapture', onCancel);

        element.classList.add('is-sorting');
        rows[from].classList.add('is-lifted');
        dragging.value = true;
        navigator.vibrate?.(8);

        layout();
        drag.frame = requestAnimationFrame(autoScroll);
    }

    onBeforeUnmount(() => {
        if (drag) {
            const state = drag;
            drag = null;
            detach(state);
            cleanUp(state);
        }
    });

    return { start, dragging };
}
