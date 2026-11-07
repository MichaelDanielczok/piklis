<script setup>
/**
 * Mengen-Ebene der Halten-Geste (`useHoldGesture`): Milchglas über der App und
 * die gemeinsame Physik von Lineal und Zählwerk. Die Position ist ein Index als
 * Fließkommazahl, bei jedem Frame rechnet die Variante alles aus dem Abstand zur
 * Position neu (`render`), Vue fasst dabei nur die aktuelle Stufe an.
 *
 * Den Finger hat die Zeile oder Kachel; die Geste ruft `dragTo`, `release` und
 * `close` auf. Das Mausrad läuft hier auf.
 *
 * Eine offene Tastatur schließt beim Halten, sonst bliebe für die Skala kein Platz.
 *
 * `rect`: gehaltenes Element. `wide`: Es ist schmaler als eine Zeile (Kachel),
 * das Sichtfenster des Lineals bekommt dann Zeilenbreite.
 * Emits: moved (Mausrad, zählt wie Ziehen)
 */
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { ArrowUp } from '@lucide/vue';
import QuantityCounter from './QuantityCounter.vue';
import QuantityRuler from './QuantityRuler.vue';
import { formatQuantity } from '@/utils/quantity.js';
import { amountAt, clamp, easeOutCubic, HOLD, rubberBand, scaleIndex, scaleMaxIndex } from '@/utils/quantityScale.js';
import { t } from '@/i18n/index.js';

const EDGE = 8;
const WIDE_MAX = 480;

const props = defineProps({
    variant: {
        type: String,
        default: 'ruler',
        validator: (value) => ['ruler', 'counter'].includes(value)
    },
    rect: {
        type: Object,
        default: null
    },
    wide: {
        type: Boolean,
        default: false
    },
    name: {
        type: String,
        required: true
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
    }
});

const emit = defineEmits(['moved']);

const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
// Die Tastatur (Kacheln der Eingabeleiste) ließe keinen Platz: Sie geht zu, die Ebene nimmt die volle Höhe
if (document.activeElement?.matches?.('input, textarea')) {
    document.activeElement.blur();
}

const height = window.innerHeight;

const isRuler = computed(() => props.variant === 'ruler');
const maxIndex = scaleMaxIndex(props.amount, props.unit);
const startIndex = scaleIndex(props.amount, props.unit);
const gap = isRuler.value ? HOLD.rulerGap : HOLD.counterGap;

const anchor = anchorOf();
const scale = useTemplateRef('scale');
const index = ref(startIndex);
const isOpen = ref(false);
const announcement = ref('');

// nur auf der Liste lässt sich etwas entfernen
const removes = computed(() => index.value === 0 && props.amount > 0);

let position = startIndex;
let base = startIndex;
let frame = 0;
let opening = 0;

/** Lage der gehaltenen Zeile; eine Kachel bekommt Zeilenbreite um ihre Mitte. */
function anchorOf() {
    const rect = props.rect ?? { top: height / 2, height: 0, left: EDGE, right: window.innerWidth - EDGE };

    if (!props.wide) {
        return { top: rect.top, height: rect.height, left: rect.left, right: rect.right };
    }

    const width = Math.min(window.innerWidth - 2 * EDGE, WIDE_MAX);
    const left = clamp((rect.left + rect.right - width) / 2, EDGE, window.innerWidth - EDGE - width);

    return { top: rect.top, height: rect.height, left, right: left + width };
}

function show() {
    index.value = clamp(Math.round(position), 0, maxIndex);
    scale.value?.render(position);
}

function announce() {
    announcement.value = index.value === 0 ? t('holdLayer.removes') : formatQuantity(amountAt(index.value, props.unit), props.unit);
}

/** @param {number} dragged Fingerweg nach oben in px seit Beginn des Haltens */
function dragTo(dragged) {
    const before = index.value;

    cancelAnimationFrame(frame);
    position = rubberBand(base + dragged / gap, maxIndex);
    show();

    if (index.value !== before) {
        globalThis.navigator?.vibrate?.(HOLD.vibrateStep);
        announce();
    }
}

function animateTo(target, done) {
    const from = position;
    const started = performance.now();
    const duration = reduced ? 0 : HOLD.snapMs;

    function tick(now) {
        const progress = duration ? Math.min(1, (now - started) / duration) : 1;

        position = from + (target - from) * easeOutCubic(progress);
        show();

        if (progress < 1) {
            frame = requestAnimationFrame(tick);
        } else {
            done?.();
        }
    }

    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(tick);
}

function close(done) {
    cancelAnimationFrame(frame);
    cancelAnimationFrame(opening);
    isOpen.value = false;
    setTimeout(done, reduced ? 0 : HOLD.fadeMs);
}

/**
 * Einrasten, schließen, dann die neue Menge melden; null: Stufe unverändert.
 *
 * @param {(amount: number|null) => void} done
 */
function release(done) {
    const target = index.value;

    animateTo(target, () => {
        close(() => done(target === startIndex ? null : amountAt(target, props.unit)));
    });
}

function onWheel(event) {
    const target = clamp(index.value + (event.deltaY > 0 ? 1 : -1), 0, maxIndex);

    base += target - index.value;
    emit('moved');
    animateTo(target);
    index.value = target;
    announce();
}

onMounted(() => {
    show();
    opening = requestAnimationFrame(() => {
        isOpen.value = true;
    });
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    cancelAnimationFrame(opening);
});

defineExpose({ dragTo, release, close });
</script>

<template>
    <Teleport to="body">
        <div
            class="quantity-layer"
            :class="{ 'quantity-layer--open': isOpen }"
            :style="{ height: height + 'px' }"
            role="dialog"
            aria-modal="true"
            :aria-label="t('holdLayer.label', { name })"
            data-name="quantity-layer"
            @wheel.prevent="onWheel"
        >
            <div class="quantity-layer__frost"></div>

            <component
                :is="isRuler ? QuantityRuler : QuantityCounter"
                ref="scale"
                :name="name"
                :image-url="imageUrl"
                :icon="icon"
                :category="category"
                :amount="amount"
                :unit="unit"
                :max-index="maxIndex"
                :start-index="startIndex"
                :index="index"
                :removes="removes"
                :height="height"
                :anchor="anchor"
                :open="isOpen"
            />

            <div class="quantity-layer__hint">
                <b class="quantity-layer__hint-title">
                    <ArrowUp class="quantity-layer__hint-icon" aria-hidden="true" />
                    {{ isRuler ? t('holdLayer.hintRuler') : t('holdLayer.hintCounter') }}
                </b>
                <span class="quantity-layer__hint-text">
                    {{ isRuler ? t('holdLayer.hintReleaseRuler') : t('holdLayer.hintRelease') }}
                </span>
            </div>

            <div class="visually-hidden" aria-live="assertive">{{ announcement }}</div>
        </div>
    </Teleport>
</template>

<style scoped>
.quantity-layer {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: var(--z-hold-layer);
    opacity: 0;
    color: var(--color-text-1);
    cursor: ns-resize;
    touch-action: none;
    user-select: none;
    -webkit-user-select: none;
    transition: opacity var(--duration-hold-fade) var(--ease-hold);
}

.quantity-layer--open {
    opacity: 1;
}

.quantity-layer__frost {
    position: absolute;
    inset: 0;
    background: var(--color-frost);
    backdrop-filter: var(--hold-blur);
    -webkit-backdrop-filter: var(--hold-blur);
}

.quantity-layer__hint {
    position: absolute;
    left: var(--space-6);
    right: var(--space-6);
    bottom: calc(var(--space-10) + env(safe-area-inset-bottom, 0px));
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-1);
    text-align: center;
    pointer-events: none;
}

.quantity-layer__hint-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.quantity-layer__hint-icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.quantity-layer__hint-text {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
