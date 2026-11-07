<script setup>
/**
 * Mengenwahl „Lineal“: Die gehaltene Zeile bleibt stehen und wird zum
 * Sichtfenster, rechts davon läuft ein Maßband mit Teilstrichen durch die
 * Mengenspalte. Größere Werte liegen unten, wer nach oben zieht, schiebt die
 * Skala mit. Striche und Zahlen sind Plätze, die `render` wiederverwendet.
 *
 * Maße und Tabellen aus design/piklis-halten-geste, README Abschnitt 4.
 */
import { computed, onMounted, useTemplateRef } from 'vue';
import { Trash2 } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import { formatAmount, formatQuantity, MEASURE_UNITS, quantityParts } from '@/utils/quantity.js';
import { amountAt, clamp, hexToRgb, HOLD, lerpKnots, mixColor, RULER, slotIndex } from '@/utils/quantityScale.js';
import { t } from '@/i18n/index.js';

const LENS_HEIGHT = 80;
const LENS_TOP_MIN = 120;
const LENS_BOTTOM_MIN = 240;
const LENS_PADDING = 14;
const THUMB = 44;
const GAP = 12;
const TEXT_MIN = 96;
const TEXT_MARGIN = 16; // Abstand des Textblocks zur breitesten Zahl
const LENS_REACH = 38; // Striche im Fenster sind grün
const TAPE_RIGHT = 8;
const TAPE_WIDTH = 176;
const TAPE_TOP_MIN = 16;
const TICK_RIGHT = 24;
const LABEL_RIGHT = 70;
const LABEL_WIDTH = 124;
const LABEL_HEIGHT = 36;
const HINT_SPACE = 112;
const TICKS = 10;
const TICK_WIDTHS = [26, 9, 9, 9, 9, 16, 9, 9, 9, 9];
const MEASURED_LABELS = 200;

const props = defineProps({
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
    },
    maxIndex: {
        type: Number,
        required: true
    },
    startIndex: {
        type: Number,
        required: true
    },
    index: {
        type: Number,
        required: true
    },
    removes: {
        type: Boolean,
        default: false
    },
    height: {
        type: Number,
        required: true
    },
    anchor: {
        type: Object,
        required: true
    },
    open: {
        type: Boolean,
        default: false
    }
});

const gap = HOLD.rulerGap;
const { anchor, height } = props;

const lensTop = clamp(anchor.top + anchor.height / 2 - LENS_HEIGHT / 2, LENS_TOP_MIN, height - LENS_BOTTOM_MIN);
const center = lensTop + LENS_HEIGHT / 2;
const tickRight = anchor.right - TICK_RIGHT;
const labelLeft = anchor.right - LABEL_RIGHT - LABEL_WIDTH;
const hintTop = height - HINT_SPACE;
const fadeEnd = Math.min(center + 330, hintTop);

const tapeTop = Math.max(TAPE_TOP_MIN, center - 440);
// liegt die Zeile weit oben, endet das Band früher; die Striche blenden dann dort aus
const fadeStart = Math.max(center - 360, tapeTop);
const maskImage = 'linear-gradient(to bottom, transparent ' + fadeStart + 'px, #000 ' + Math.max(center - 190, fadeStart + 60) + 'px, #000 '
    + Math.min(center + 190, fadeEnd - 80) + 'px, transparent ' + fadeEnd + 'px)';
const maskStyle = { maskImage, WebkitMaskImage: maskImage };

const tapeStyle = {
    left: anchor.right - TAPE_RIGHT - TAPE_WIDTH + 'px',
    top: tapeTop + 'px',
    bottom: Math.max(height - hintTop, height - center - 440) + 'px'
};

const lensStyle = {
    left: anchor.left + 'px',
    width: anchor.right - anchor.left + 'px',
    top: lensTop + 'px'
};

const marks = useTemplateRef('marks');
const previous = useTemplateRef('previous');

const reach = Math.ceil(height / gap) + 1;
const slotCount = 2 * reach + 2;
const slots = [];

let colors = null;

const before = computed(() => formatQuantity(props.amount, props.unit));
const current = computed(() => formatQuantity(amountAt(props.index, props.unit), props.unit));

/** Zahl auf der Skala: Gewichte mit Einheit, alles andere nur die Zahl. */
function labelOf(index) {
    const amount = amountAt(index, props.unit);

    if (index > 0 && MEASURE_UNITS.includes(props.unit)) {
        return formatQuantity(amount, props.unit);
    }

    return index === 0 ? quantityParts(0).number : formatAmount(amount);
}

/** Der Name reicht bis kurz vor die breiteste Zahl; die größten liegen am Ende. */
function textWidth() {
    const context = document.createElement('canvas').getContext('2d');
    let widest = 0;

    context.font = '700 30px ' + getComputedStyle(document.body).fontFamily;

    for (let index = Math.max(0, props.maxIndex - MEASURED_LABELS); index <= props.maxIndex; index++) {
        widest = Math.max(widest, context.measureText(labelOf(index)).width);
    }

    return Math.max(TEXT_MIN, Math.round(anchor.right - LABEL_RIGHT - widest - TEXT_MARGIN - (anchor.left + LENS_PADDING + THUMB + GAP)));
}

const textStyle = { width: textWidth() + 'px' };

function build() {
    for (let slot = 0; slot < slotCount; slot++) {
        const ticks = TICK_WIDTHS.map((width, part) => {
            const element = document.createElement('span');
            const thickness = part === 0 ? 2 : 1;

            element.className = 'quantity-ruler__tick';
            element.style.left = tickRight - width + 'px';
            element.style.width = width + 'px';
            element.style.height = thickness + 'px';
            element.style.display = 'none';
            marks.value.appendChild(element);

            return { element, part, thickness, shown: false };
        });

        const label = document.createElement('span');

        label.className = 'quantity-ruler__label';
        label.style.left = labelLeft + 'px';
        label.style.display = 'none';
        marks.value.appendChild(label);

        slots.push({ index: null, label, labelShown: false, ticks });
    }
}

function setShown(item, key, element, shown) {
    if (item[key] !== shown) {
        element.style.display = shown ? '' : 'none';
        item[key] = shown;
    }
}

function renderTick(tick, index, position) {
    const value = index + tick.part / TICKS;
    const y = center + (value - position) * gap;
    const visible = y > -10 && y < height + 10 && !(index === props.maxIndex && tick.part > 0);

    setShown(tick, 'shown', tick.element, visible);

    if (!visible) {
        return;
    }

    const distance = Math.abs(y - center);
    const { style } = tick.element;

    style.transform = 'translateY(' + (y - tick.thickness / 2).toFixed(1) + 'px)';

    if (distance < LENS_REACH) {
        style.background = colors.accentHex;
    } else {
        style.background = tick.part === 0 ? colors.mutedHex : colors.tick;
    }

    style.opacity = Math.max(0.35, 1 - distance / gap * 0.12).toFixed(2);
}

function renderLabel(slot, index, position) {
    const distance = index - position;
    const y = center + distance * gap;
    const visible = y > -30 && y < height + 30;

    setShown(slot, 'labelShown', slot.label, visible);

    if (!visible) {
        return;
    }

    const { style } = slot.label;

    style.transform = 'translateY(' + (y - LABEL_HEIGHT / 2).toFixed(1) + 'px) scale(' + lerpKnots(RULER.scale, distance).toFixed(3) + ')';
    style.opacity = lerpKnots(RULER.opacity, distance).toFixed(3);
    style.color = mixColor(colors.accent, colors.muted, Math.min(1, Math.abs(distance)));
}

/** @param {number} position Index als Fließkommazahl */
function render(position) {
    if (!colors) {
        return;
    }

    const first = Math.max(0, Math.floor(position - reach));
    const last = Math.min(props.maxIndex, Math.ceil(position + reach));

    slots.forEach((slot, number) => {
        const index = slotIndex(number, slotCount, first, last);

        if (index === null) {
            slot.index = null;
            setShown(slot, 'labelShown', slot.label, false);
            slot.ticks.forEach((tick) => setShown(tick, 'shown', tick.element, false));
            return;
        }

        if (slot.index !== index) {
            slot.index = index;
            slot.label.textContent = labelOf(index);
        }

        slot.ticks.forEach((tick) => renderTick(tick, index, position));
        renderLabel(slot, index, position);
    });

    const shown = Math.round(position) !== props.startIndex;

    previous.value.style.display = shown ? '' : 'none';
    previous.value.style.transform = 'translateY(' + (center + (props.startIndex - position) * gap - 4).toFixed(1) + 'px)';
}

onMounted(() => {
    const style = getComputedStyle(document.documentElement);
    const accentHex = style.getPropertyValue('--color-accent').trim();
    const mutedHex = style.getPropertyValue('--color-text-2').trim();

    colors = {
        accentHex,
        mutedHex,
        accent: hexToRgb(accentHex),
        muted: hexToRgb(mutedHex),
        tick: style.getPropertyValue('--color-ring').trim()
    };

    build();
});

defineExpose({ render });
</script>

<template>
    <div class="quantity-ruler__mask" :style="maskStyle" aria-hidden="true">
        <div class="quantity-ruler__tape" :style="tapeStyle"></div>
    </div>

    <div class="quantity-ruler__lens" :class="{ 'quantity-ruler__lens--open': open }" :style="lensStyle">
        <ProductImage :image-url="imageUrl" :icon="icon" :category="category" />
        <span class="quantity-ruler__text" :style="textStyle">
            <span class="quantity-ruler__name" lang="de">{{ name }}</span>
            <span v-if="removes" class="quantity-ruler__sub quantity-ruler__sub--remove">
                <Trash2 class="quantity-ruler__icon" aria-hidden="true" />
                {{ t('holdLayer.removes') }}
            </span>
            <span v-else class="quantity-ruler__sub">
                <b class="quantity-ruler__value">{{ current }}</b>
                <template v-if="amount > 0"> · {{ t('holdLayer.before', { quantity: before }) }}</template>
            </span>
        </span>
    </div>

    <div ref="marks" class="quantity-ruler__mask quantity-ruler__marks" :style="maskStyle" aria-hidden="true">
        <span ref="previous" class="quantity-ruler__previous" :style="{ left: tickRight - 34 + 'px', display: 'none' }"></span>
    </div>
</template>

<style scoped>
.quantity-ruler__mask {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.quantity-ruler__tape {
    position: absolute;
    width: var(--hold-tape-width);
    border-radius: var(--hold-tape-radius);
    background: var(--color-surface-1);
    box-shadow: 0 0 0 1px var(--color-border-subtle), var(--shadow-tape);
}

.quantity-ruler__lens {
    position: absolute;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    height: var(--hold-lens-height);
    padding-left: 0.875rem;
    border-radius: var(--hold-lens-radius);
    background: var(--color-surface-1);
    box-shadow: var(--shadow-lift), 0 0 0 2px var(--color-accent);
    transform: scale(var(--press-scale));
    transition: transform var(--duration-hold-panel) var(--ease-hold);
}

.quantity-ruler__lens--open {
    transform: none;
}

.quantity-ruler__text {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.quantity-ruler__name {
    display: -webkit-box;
    overflow: hidden;
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: 1.3125rem;
    hyphens: auto;
    overflow-wrap: break-word;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.quantity-ruler__sub {
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.quantity-ruler__sub--remove {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    color: var(--color-notgot);
    font-weight: var(--font-weight-semibold);
}

.quantity-ruler__icon {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.quantity-ruler__value {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.quantity-ruler__marks :deep(.quantity-ruler__tick) {
    position: absolute;
    top: 0;
    border-radius: 1px;
    will-change: transform;
}

.quantity-ruler__marks :deep(.quantity-ruler__label) {
    position: absolute;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    width: var(--hold-ruler-label-width);
    height: var(--hold-ruler-label-height);
    font-size: var(--hold-ruler-number);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-bold);
    letter-spacing: -0.01em;
    line-height: var(--hold-ruler-label-height);
    transform-origin: right center;
    white-space: nowrap;
    will-change: transform, opacity;
}

.quantity-ruler__previous {
    position: absolute;
    top: 0;
    width: var(--hold-prev-size);
    height: var(--hold-prev-size);
    border-radius: var(--radius-pill);
    box-shadow: 0 0 0 1.75px var(--color-text-2);
}
</style>
