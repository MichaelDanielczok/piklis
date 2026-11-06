<script setup>
/**
 * Mengen-Control für Kachel und Listenzeile (Konzept „Mengen, Reihenfolge und
 * Wiederherstellen“); die Geste steckt in `useQuantityHold`, von dort kommt
 * `state`.
 *
 * - `hold`: senkrechte Skala neben dem Finger, mehr oben, weniger unten. Sie
 *   ist rein visuell (keine Treffer, kein Fokus), den Finger hat die Kachel.
 * - `menu`: ein kleiner Dialog mit −, Menge, +, „Details“ und „Entfernen“.
 *   Er bleibt offen, bis man daneben tippt; −, + wirken sofort. Der Hintergrund
 *   ist solange `inert`, Escape schließt, der Fokus kehrt zum Auslöser zurück.
 *
 * Direkt nach dem Loslassen nimmt der Dialog 300 ms lang keine Tipps an: Der
 * Klick der eben beendeten Berührung träfe sonst die Fläche daneben.
 *
 * `removable` (Kachel): Minus erreicht 0 und nimmt das Produkt damit weg. In der
 * Liste ist 1 das Minimum, Entfernen eine eigene Aktion.
 *
 * Ereignisse: `change` (Menge), `details`, `remove`, `close`.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, useTemplateRef, watch } from 'vue';
import { ChevronDown, ChevronUp, Minus, Plus } from '@lucide/vue';
import { useRoute } from 'vue-router';
import { formatQuantity, MAX_AMOUNT, steppedAmount } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const SCALE_REACH = 2;
const FINGER_GAP = 44; // px zur Fingermitte, damit die Fingerkuppe die Skala nicht verdeckt
const EDGE = 12;
const ARM_MS = 300;

const props = defineProps({
    state: {
        type: Object,
        required: true
    },
    name: {
        type: String,
        required: true
    },
    amount: {
        type: Number,
        required: true
    },
    unit: {
        type: String,
        default: null
    },
    removable: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['change', 'details', 'remove', 'close']);

const route = useRoute();
const titleId = useId();
const panel = useTemplateRef('panel');
const left = ref(0);
const height = ref(0);
const armed = ref(props.state.mode === 'menu');

let armTimer = null;

const isMenu = computed(() => props.state.mode === 'menu');

function stepped(from, steps) {
    return steppedAmount(from, props.unit, steps, { removable: props.removable });
}

const canIncrease = computed(() => props.amount < MAX_AMOUNT);
const canDecrease = computed(() => stepped(props.amount, -1) !== props.amount);

/** Skala von oben nach unten; was jenseits der Grenzen läge, bleibt leer. */
const scale = computed(() => {
    const center = props.state.draft;
    const rows = [];

    for (let offset = SCALE_REACH; offset >= -SCALE_REACH; offset--) {
        const value = stepped(center, offset);
        const inner = stepped(center, offset - Math.sign(offset));

        rows.push({
            offset,
            text: offset !== 0 && value === inner ? '' : formatQuantity(value, props.unit)
        });
    }

    return rows;
});

const position = computed(() => ({
    left: left.value + 'px',
    '--_y': props.state.y + 'px',
    '--_height': height.value + 'px'
}));

/** Neben den Finger, auf die Seite mit mehr Platz; oben und unten begrenzt das CSS. */
function place() {
    const box = panel.value;

    if (!box) {
        return;
    }

    const { x } = props.state;
    const { offsetWidth: width, offsetHeight } = box;
    const wanted = x < window.innerWidth / 2 ? x + FINGER_GAP : x - FINGER_GAP - width;

    left.value = Math.max(EDGE, Math.min(wanted, window.innerWidth - width - EDGE));
    height.value = offsetHeight;
}

function setBackgroundInert(on) {
    const app = document.querySelector('ion-app');

    if (app) {
        app.inert = on;
    }
}

function enterMenu() {
    setBackgroundInert(true);
    panel.value?.focus({ preventScroll: true });
}

onMounted(() => {
    place();

    if (isMenu.value) {
        enterMenu();
    }
});

watch(isMenu, async (menu) => {
    if (!menu) {
        return;
    }

    await nextTick();
    place();
    enterMenu();
    armTimer = setTimeout(() => {
        armed.value = true;
    }, ARM_MS);
});

// Ionic behält die Seite im Verlauf, das Control hinge sonst über der nächsten (Zurück-Taste)
watch(() => route.fullPath, close);

onBeforeUnmount(() => {
    clearTimeout(armTimer);
    setBackgroundInert(false);

    if (props.state.opener?.isConnected) {
        props.state.opener.focus({ preventScroll: true });
    }
});

function change(direction) {
    emit('change', stepped(props.amount, direction));
}

function close() {
    emit('close');
}

function showDetails() {
    close();
    emit('details');
}

function remove() {
    close();
    emit('remove');
}
</script>

<template>
    <Teleport to="body">
        <!-- ein Knopf, weil iOS Tipps auf eine reine Fläche hier nicht als Klick meldet; Bildschirmleser nutzen „Schließen“ -->
        <button
            v-if="isMenu"
            type="button"
            class="quantity-scrim"
            :class="{ 'quantity-scrim--armed': armed }"
            tabindex="-1"
            aria-hidden="true"
            data-name="quantity-scrim"
            @click="close"
        ></button>

        <div
            ref="panel"
            class="quantity-control"
            :class="{ 'quantity-control--menu': isMenu, 'quantity-control--armed': armed }"
            :style="position"
            :role="isMenu ? 'dialog' : undefined"
            :aria-modal="isMenu ? 'true' : undefined"
            :aria-labelledby="isMenu ? titleId : undefined"
            :aria-hidden="isMenu ? undefined : 'true'"
            tabindex="-1"
            data-name="quantity-control"
            @keydown.esc.stop="close"
        >
            <template v-if="isMenu">
                <p :id="titleId" class="quantity-control__name">{{ name }}</p>

                <button
                    type="button"
                    class="quantity-control__step quantity-control__step--plus"
                    data-name="increase"
                    :aria-label="t('quantityControl.increase', { name })"
                    :disabled="!canIncrease"
                    @click="change(1)"
                >
                    <Plus aria-hidden="true" />
                </button>

                <output class="quantity-control__value" aria-live="polite">{{ formatQuantity(amount, unit) }}</output>

                <button
                    type="button"
                    class="quantity-control__step"
                    data-name="decrease"
                    :aria-label="t('quantityControl.decrease', { name })"
                    :disabled="!canDecrease"
                    @click="change(-1)"
                >
                    <Minus aria-hidden="true" />
                </button>

                <div class="quantity-control__actions">
                    <button type="button" class="quantity-control__action" data-name="details" @click="showDetails">
                        {{ t('quantityControl.details') }}
                    </button>
                    <button
                        v-if="amount > 0"
                        type="button"
                        class="quantity-control__action quantity-control__action--danger"
                        data-name="remove"
                        @click="remove"
                    >
                        {{ t('quantityControl.remove') }}
                    </button>
                    <button type="button" class="quantity-control__action visually-hidden-focusable" @click="close">
                        {{ t('common.close') }}
                    </button>
                </div>
            </template>

            <template v-else>
                <ChevronUp class="quantity-control__hint" />
                <ol class="quantity-control__scale">
                    <li
                        v-for="row in scale"
                        :key="row.offset"
                        class="quantity-control__tick"
                        :class="{ 'quantity-control__tick--current': row.offset === 0 }"
                    >
                        {{ row.text }}
                    </li>
                </ol>
                <ChevronDown class="quantity-control__hint" />
            </template>
        </div>
    </Teleport>
</template>

<style scoped>
.quantity-scrim {
    position: fixed;
    inset: 0;
    z-index: var(--z-quantity-control);
    background: var(--color-scrim);
    pointer-events: none;
    touch-action: none;
}

.quantity-scrim--armed {
    pointer-events: auto;
}

.quantity-control {
    --_top-limit: calc(env(safe-area-inset-top, 0px) + var(--space-3));
    --_bottom-limit: calc(env(safe-area-inset-bottom, 0px) + var(--space-3));

    position: fixed;
    top: clamp(
        var(--_top-limit),
        calc(var(--_y) - var(--_height) / 2),
        calc(100dvh - var(--_bottom-limit) - var(--_height))
    );
    z-index: calc(var(--z-quantity-control) + 1);
    display: flex;
    flex-direction: column;
    align-items: center;
    width: var(--quantity-control-width);
    padding: var(--space-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    box-shadow: var(--shadow-menu);
    pointer-events: none;
    /* schnelle Tipps auf + sind kein Doppeltipp-Zoom */
    touch-action: none;
    -webkit-touch-callout: none;
    user-select: none;
    -webkit-user-select: none;
}

.quantity-control:focus {
    outline: none;
}

.quantity-control--armed {
    pointer-events: auto;
}

.quantity-control__hint {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    color: var(--color-text-2);
}

.quantity-control__scale {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    width: 100%;
    margin: var(--space-1) 0;
    padding: 0;
    list-style: none;
}

.quantity-control__tick {
    min-height: var(--line-height-qty);
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-qty);
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.quantity-control__tick--current {
    padding: var(--space-2) 0;
    border-radius: var(--radius-sm);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
}

.quantity-control__name {
    display: -webkit-box;
    width: 100%;
    margin-bottom: var(--space-2);
    overflow: hidden;
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
    overflow-wrap: anywhere;
    text-align: center;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}

.quantity-control__step {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border: 2px solid var(--color-accent);
    border-radius: 50%;
    color: var(--color-accent-text);
    transition: transform var(--duration-fast) var(--ease-out);
}

.quantity-control__step--plus {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.quantity-control__step:active:not(:disabled) {
    transform: scale(var(--press-scale));
}

.quantity-control__step:disabled {
    opacity: 0.4;
}

.quantity-control__step svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}

.quantity-control__value {
    width: 100%;
    padding: var(--space-2) 0;
    font-size: var(--font-size-name);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    text-align: center;
}

.quantity-control__actions {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-top: var(--space-2);
    border-top: 1px solid var(--color-border-subtle);
}

.quantity-control__action {
    min-height: var(--tap-size);
    border-radius: var(--radius-sm);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
}

.quantity-control__action--danger {
    color: var(--color-danger);
}

.quantity-control__step:focus-visible,
.quantity-control__action:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

@media (hover: hover) {
    .quantity-control__action:hover {
        background: var(--color-surface-2);
    }
}
</style>
