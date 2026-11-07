<script setup>
/**
 * Aktionsmenü der Halten-Geste (Halten und Loslassen), als Sheet von unten oder
 * Dialog in der Mitte: Kopf, Mengen-Stepper, Aktionen, abgesetzt „Entfernen“.
 * Eigene Fläche statt `AppSheet`, denn hier gehören Griffgeste und Maße dem
 * Design, und der Dialog teilt sich alles andere mit dem Sheet.
 *
 * −, + wirken sofort (`change`). Der Stepper bleibt über 0, Entfernen ist die
 * eigene Aktion. Eine Kachel, die noch nicht auf der Liste steht, hat 0 und
 * kein Entfernen (`removable`).
 *
 * Schließen über X, Scrim, Escape oder am Griff nach unten (ab 96 px). Der Fokus
 * springt auf „Schließen“, bleibt im Menü und kehrt zu `returnFocus` zurück. Der
 * Hintergrund ist solange `inert`. Direkt nach dem Öffnen nimmt der Scrim 300 ms
 * lang keine Tipps an: Der Klick der eben beendeten Berührung träfe sonst ihn.
 *
 * actions: Auswahl aus `details`, `note`, `transfer`, `notGot`
 * Emits: change(amount) · action(key, auch `remove`) · close(before): vorherige
 * Menge, wenn sie sich geändert hat, sonst null
 */
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import { ArrowRightFromLine, ChevronRight, Info, Minus, PencilLine, Plus, Trash2, X } from '@lucide/vue';
import { useRoute } from 'vue-router';
import ProductImage from '@/components/products/ProductImage.vue';
import { dismissToast } from '@/composables/useToast.js';
import { formatQuantity, MAX_AMOUNT, quantityStep, steppedAmount } from '@/utils/quantity.js';
import { HOLD } from '@/utils/quantityScale.js';
import { t } from '@/i18n/index.js';

const ARM_MS = 300;

const ACTION_ICONS = { details: Info, note: PencilLine, transfer: ArrowRightFromLine, notGot: X };

const props = defineProps({
    variant: {
        type: String,
        default: 'sheet',
        validator: (value) => ['sheet', 'dialog'].includes(value)
    },
    name: {
        type: String,
        required: true
    },
    subtitle: {
        type: String,
        default: ''
    },
    imageUrl: {
        type: String,
        default: null
    },
    icon: {
        type: String,
        default: undefined
    },
    category: {
        type: String,
        default: null
    },
    amount: {
        type: Number,
        required: true
    },
    unit: {
        type: String,
        default: null
    },
    actions: {
        type: Array,
        default: () => ['details']
    },
    removable: {
        type: Boolean,
        default: true
    },
    removeLabel: {
        type: String,
        default: ''
    },
    returnFocus: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['change', 'action', 'close']);

const route = useRoute();
const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
const before = props.amount;

const panel = useTemplateRef('panel');
const closeButton = useTemplateRef('closeButton');
const isOpen = ref(false);
const isArmed = ref(false);
const isDragging = ref(false);
const dragOffset = ref(0);

let closing = false;
let dragStart = 0;
let armTimer = null;
let opening = 0;

const isSheet = computed(() => props.variant === 'sheet');
const value = computed(() => (props.amount > 0 ? formatQuantity(props.amount, props.unit) : '–'));
const stepText = computed(() => formatQuantity(quantityStep(props.unit), props.unit));
const canIncrease = computed(() => props.amount < MAX_AMOUNT);
const canDecrease = computed(() => props.amount > 0 && steppedAmount(props.amount, props.unit, -1) !== props.amount);
const removeText = computed(() => props.removeLabel || t('actionMenu.remove'));
const panelStyle = computed(() => (dragOffset.value > 0 ? { transform: 'translateY(' + dragOffset.value + 'px)' } : undefined));

function setBackgroundInert(on) {
    const app = document.querySelector('ion-app');

    if (app) {
        app.inert = on;
    }
}

function step(direction) {
    emit('change', steppedAmount(props.amount, props.unit, direction));
    globalThis.navigator?.vibrate?.(HOLD.vibrateStep);
}

/** Zuerst die Bewegung, die Aktion danach: Eine entfernte Zeile nimmt das Menü mit. */
function finish(action = null) {
    if (closing) {
        return;
    }

    closing = true;
    isOpen.value = false;
    dragOffset.value = 0;

    setTimeout(() => {
        emit('close', action !== 'remove' && props.amount !== before ? before : null);

        if (action) {
            emit('action', action);
        }
    }, reduced ? 0 : HOLD.panelMs);
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        finish();
        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const focusable = panel.value.querySelectorAll('button:not([disabled])');
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function onGripDown(event) {
    isDragging.value = true;
    dragStart = event.clientY;
    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function onGripMove(event) {
    if (isDragging.value) {
        dragOffset.value = Math.max(0, event.clientY - dragStart);
    }
}

function onGripEnd() {
    if (!isDragging.value) {
        return;
    }

    isDragging.value = false;

    if (dragOffset.value > HOLD.sheetCloseDrag) {
        finish();
    } else {
        dragOffset.value = 0;
    }
}

// Ionic behält die Seite im Verlauf, das Menü hinge sonst über der nächsten (Zurück-Taste)
watch(() => route.fullPath, () => finish());

onMounted(() => {
    dismissToast();
    setBackgroundInert(true);
    document.addEventListener('keydown', onKeydown, true);
    armTimer = setTimeout(() => {
        isArmed.value = true;
    }, ARM_MS);
    opening = requestAnimationFrame(() => {
        isOpen.value = true;
        closeButton.value?.focus({ preventScroll: true });
    });
});

onBeforeUnmount(() => {
    clearTimeout(armTimer);
    cancelAnimationFrame(opening);
    document.removeEventListener('keydown', onKeydown, true);
    setBackgroundInert(false);

    if (props.returnFocus?.isConnected) {
        props.returnFocus.focus({ preventScroll: true });
    }
});
</script>

<template>
    <Teleport to="body">
        <!-- ein Knopf, weil iOS Tipps auf eine reine Fläche hier nicht als Klick meldet; Bildschirmleser nutzen „Schließen“ -->
        <button
            type="button"
            class="action-menu__scrim"
            :class="{ 'action-menu__scrim--open': isOpen, 'action-menu__scrim--armed': isArmed }"
            tabindex="-1"
            aria-hidden="true"
            data-name="action-scrim"
            @click="finish()"
        ></button>

        <div
            ref="panel"
            class="action-menu"
            :class="['action-menu--' + variant, { 'action-menu--open': isOpen, 'action-menu--dragging': isDragging }]"
            :style="panelStyle"
            role="dialog"
            aria-modal="true"
            :aria-label="name"
            tabindex="-1"
            data-name="action-menu"
        >
            <div
                v-if="isSheet"
                class="action-menu__grip"
                aria-hidden="true"
                @pointerdown="onGripDown"
                @pointermove="onGripMove"
                @pointerup="onGripEnd"
                @pointercancel="onGripEnd"
            >
                <span class="action-menu__grip-mark"></span>
            </div>

            <div class="action-menu__head">
                <ProductImage :image-url="imageUrl" :icon="icon" :category="category" />
                <div class="action-menu__titles">
                    <h2 class="action-menu__title" lang="de">{{ name }}</h2>
                    <span v-if="subtitle" class="action-menu__subtitle">{{ subtitle }}</span>
                </div>
                <button ref="closeButton" type="button" class="action-menu__close" data-name="action-close" :aria-label="t('common.close')" @click="finish()">
                    <X aria-hidden="true" />
                </button>
            </div>

            <div class="action-menu__quantity">
                <span v-if="isSheet" class="action-menu__label">{{ t('actionMenu.quantity') }}</span>
                <div class="action-menu__stepper">
                    <button
                        type="button"
                        class="action-menu__step action-menu__step--minus"
                        data-name="action-minus"
                        :disabled="!canDecrease"
                        :aria-label="t('actionMenu.less', { step: stepText })"
                        @click="step(-1)"
                    >
                        <Minus aria-hidden="true" />
                    </button>
                    <output class="action-menu__value" aria-live="polite">{{ value }}</output>
                    <button
                        type="button"
                        class="action-menu__step action-menu__step--plus"
                        data-name="action-plus"
                        :disabled="!canIncrease"
                        :aria-label="t('actionMenu.more', { step: stepText })"
                        @click="step(1)"
                    >
                        <Plus aria-hidden="true" />
                    </button>
                </div>
            </div>

            <div class="action-menu__actions">
                <button
                    v-for="action in actions"
                    :key="action"
                    type="button"
                    class="action-menu__action"
                    :class="{ 'action-menu__action--not-got': action === 'notGot' }"
                    :data-name="'action-' + action"
                    @click="finish(action)"
                >
                    <component :is="ACTION_ICONS[action]" class="action-menu__action-icon" aria-hidden="true" />
                    <span class="action-menu__action-text">{{ t('actionMenu.' + action + (isSheet ? 'Short' : '')) }}</span>
                    <ChevronRight v-if="!isSheet && action === 'details'" class="action-menu__chevron" aria-hidden="true" />
                </button>
            </div>

            <div v-if="removable" class="action-menu__danger">
                <button type="button" class="action-menu__remove" data-name="action-remove" @click="finish('remove')">
                    <Trash2 class="action-menu__action-icon" aria-hidden="true" />
                    {{ removeText }}
                </button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.action-menu__scrim {
    position: fixed;
    inset: 0;
    z-index: var(--z-hold-layer);
    width: 100%;
    background: var(--color-scrim);
    opacity: 0;
    pointer-events: none;
    transition: opacity var(--duration-hold-panel) var(--ease-hold);
}

.action-menu__scrim--open {
    opacity: 1;
}

.action-menu__scrim--armed {
    pointer-events: auto;
}

.action-menu {
    position: fixed;
    z-index: calc(var(--z-hold-layer) + 1);
    display: flex;
    flex-direction: column;
    background: var(--color-surface-1);
    color: var(--color-text-1);
    outline: none;
}

.action-menu--sheet {
    left: 0;
    right: 0;
    bottom: 0;
    gap: var(--space-4);
    max-width: var(--action-sheet-width);
    max-height: calc(100dvh - var(--space-6));
    margin: 0 auto;
    padding: var(--space-2) var(--space-4) calc(var(--space-5) + env(safe-area-inset-bottom, 0px));
    overflow-y: auto;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    box-shadow: var(--shadow-sheet);
    transform: translateY(100%);
    transition: transform var(--duration-hold-panel) var(--ease-hold);
}

.action-menu--sheet.action-menu--open {
    transform: translateY(0);
}

.action-menu--dragging {
    transition: none;
}

.action-menu--dialog {
    left: 50%;
    top: 50%;
    width: min(var(--action-dialog-width), calc(100vw - var(--space-10)));
    max-height: calc(100dvh - var(--space-6) * 2);
    overflow: auto;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-dialog);
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.94);
    transition:
        transform var(--duration-hold-panel) var(--ease-hold),
        opacity var(--duration-hold-fade) var(--ease-hold);
}

.action-menu--dialog.action-menu--open {
    opacity: 1;
    transform: translate(-50%, -50%);
}

.action-menu__grip {
    display: flex;
    justify-content: center;
    align-self: stretch;
    height: var(--space-5);
    margin: calc(var(--space-2) * -1) calc(var(--space-4) * -1) calc(var(--space-3) * -1);
    padding-top: var(--space-2);
    cursor: grab;
    touch-action: none;
}

.action-menu__grip-mark {
    width: var(--action-grip-width);
    height: var(--action-grip-height);
    border-radius: var(--radius-pill);
    background: var(--color-border);
}

.action-menu__head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.action-menu--dialog .action-menu__head {
    align-items: flex-start;
    padding: var(--space-4) var(--space-3) var(--space-3) var(--space-4);
}

.action-menu__titles {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.action-menu--dialog .action-menu__titles {
    padding-top: var(--space-1);
}

.action-menu__title {
    margin: 0;
    font-size: var(--font-size-menu-title);
    font-weight: var(--font-weight-semibold);
    line-height: 1.5625rem;
    hyphens: auto;
    overflow-wrap: break-word;
}

.action-menu__subtitle {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.action-menu__close {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
    color: var(--color-icon);
}

.action-menu__close svg {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.action-menu__quantity {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.action-menu__label {
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.action-menu__stepper {
    display: flex;
    align-items: center;
    gap: var(--space-1);
}

.action-menu--sheet .action-menu__stepper {
    height: var(--action-stepper-sheet);
    padding: var(--space-1);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.action-menu--dialog .action-menu__stepper {
    justify-content: space-between;
    height: var(--action-stepper-dialog);
    margin: 0 var(--space-4) var(--space-4);
    padding: 0 var(--space-3);
    border-radius: var(--radius-md);
    background: var(--color-bg);
    box-shadow: inset 0 0 0 1px var(--color-border-subtle);
}

.action-menu__value {
    flex: 1 1 auto;
    font-size: var(--font-size-h2);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
    text-align: center;
}

.action-menu--dialog .action-menu__value {
    font-size: var(--font-size-stepper);
    line-height: var(--space-8);
}

.action-menu__step {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    height: var(--tap-size-main);
}

.action-menu__step:disabled {
    opacity: 0.4;
}

.action-menu--sheet .action-menu__step {
    width: var(--action-step-width);
    border-radius: var(--radius-sm);
}

.action-menu--dialog .action-menu__step {
    width: var(--tap-size-main);
    border-radius: var(--radius-pill);
}

.action-menu__step--minus {
    background: var(--color-surface-1);
    color: var(--color-text-1);
}

.action-menu--sheet .action-menu__step--minus {
    box-shadow: var(--shadow-1);
}

.action-menu--dialog .action-menu__step--minus {
    box-shadow: inset 0 0 0 1.5px var(--color-border);
}

.action-menu__step--plus {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.action-menu--sheet .action-menu__actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-2);
}

.action-menu--dialog .action-menu__actions {
    display: flex;
    flex-direction: column;
    border-top: 1px solid var(--color-border-subtle);
}

.action-menu__action {
    display: flex;
    color: var(--color-text-1);
    text-align: left;
}

.action-menu--sheet .action-menu__action {
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 0.375rem;
    min-height: var(--action-tile-height);
    padding: 0.625rem 0.875rem;
    border-radius: var(--radius-md);
    box-shadow: inset 0 0 0 1px var(--color-border);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
}

.action-menu--dialog .action-menu__action {
    align-items: center;
    gap: 0.875rem;
    min-height: var(--button-height);
    padding: 0 var(--space-4);
    border-bottom: 1px solid var(--color-border-subtle);
    font-size: var(--font-size-name);
}

.action-menu--dialog .action-menu__action:last-child {
    border-bottom: 0;
}

.action-menu__action-icon {
    flex: none;
    width: 1.375rem;
    height: 1.375rem;
    color: var(--color-icon);
}

.action-menu__action--not-got .action-menu__action-icon {
    color: var(--color-notgot);
}

.action-menu__action-text {
    flex: 1 1 auto;
}

.action-menu__chevron {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--color-ring);
}

.action-menu__remove {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: var(--button-height);
    color: var(--color-danger);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
}

.action-menu__remove .action-menu__action-icon {
    color: inherit;
}

.action-menu--sheet .action-menu__remove {
    justify-content: center;
    gap: 0.625rem;
    border-radius: var(--radius-md);
    background: var(--color-danger-soft);
}

.action-menu--dialog .action-menu__danger {
    border-top: 0.375rem solid var(--color-bg);
}

.action-menu--dialog .action-menu__remove {
    gap: 0.875rem;
    padding: 0 var(--space-4);
    text-align: left;
}

.action-menu__close:focus-visible,
.action-menu__step:focus-visible,
.action-menu__remove:focus-visible,
.action-menu--sheet .action-menu__action:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.action-menu--dialog .action-menu__action:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: -3px;
}
</style>
