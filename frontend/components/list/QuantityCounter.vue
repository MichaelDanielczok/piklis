<script setup>
/**
 * Mengenwahl „Zählwerk“: große Zahlentrommel in der Bildschirmmitte, der Rest
 * ruhig im Hintergrund. Größere Werte liegen unten. Die Zeilen der Trommel sind
 * Plätze, die `render` wiederverwendet.
 *
 * Maße und Tabellen aus design/piklis-halten-geste, README Abschnitt 5.
 */
import { computed, onMounted, useTemplateRef } from 'vue';
import { Trash2 } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import { formatQuantity, quantityParts, quantityStep } from '@/utils/quantity.js';
import { amountAt, COUNTER, hexToRgb, lerpKnots, mixColor, slotIndex } from '@/utils/quantityScale.js';
import { t } from '@/i18n/index.js';

const CENTER_RATIO = 0.54;
const HEAD_ABOVE = 370;
const HEAD_MIN = 24;
const BAND_HALF = 70;
const ITEM_OFFSET = 62;
const ZERO_OFFSET = 82;
const NUMBER_SIZE = 84;
const FIT_CHARS = 3.2;
const SLOTS = 12;

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
    removes: {
        type: Boolean,
        default: false
    },
    height: {
        type: Number,
        required: true
    }
});

const center = Math.round(props.height * CENTER_RATIO);
const maskImage = 'linear-gradient(to bottom, transparent ' + (center - 240) + 'px, #000 ' + (center - 140) + 'px, #000 '
    + (center + 140) + 'px, transparent ' + (center + 240) + 'px)';

const drum = useTemplateRef('drum');
const slots = [];

let colors = null;

const meta = computed(() => {
    const step = t('holdLayer.steps', { quantity: formatQuantity(quantityStep(props.unit), props.unit) });

    return props.amount > 0 ? t('holdLayer.before', { quantity: formatQuantity(props.amount, props.unit) }) + ' · ' + step : step;
});

function build() {
    for (let number = 0; number < SLOTS; number++) {
        const item = document.createElement('div');
        const value = document.createElement('span');
        const unit = document.createElement('span');

        item.className = 'quantity-counter__item';
        item.style.top = center - ITEM_OFFSET + 'px';
        item.style.display = 'none';
        value.className = 'quantity-counter__number';
        unit.className = 'quantity-counter__unit';
        item.append(value);
        drum.value.appendChild(item);

        slots.push({ index: null, item, value, unit, shown: false });
    }
}

function fill(slot, index) {
    const { number, unit } = quantityParts(amountAt(index, props.unit), props.unit);
    const fit = number.length <= 3 ? 1 : FIT_CHARS / number.length;

    slot.index = index;
    slot.value.textContent = number;
    slot.value.style.fontSize = Math.round(NUMBER_SIZE * fit) + 'px';
    slot.unit.textContent = unit;

    if (unit) {
        slot.value.append(slot.unit);
    }
}

function setShown(slot, shown) {
    if (slot.shown !== shown) {
        slot.item.style.display = shown ? '' : 'none';
        slot.shown = shown;
    }
}

/** @param {number} position Index als Fließkommazahl */
function render(position) {
    if (!colors) {
        return;
    }

    const first = Math.max(0, Math.ceil(position - COUNTER.reach));
    const last = Math.min(props.maxIndex, Math.floor(position + COUNTER.reach));

    slots.forEach((slot, number) => {
        const index = slotIndex(number, SLOTS, first, last);

        if (index === null) {
            slot.index = null;
            setShown(slot, false);
            return;
        }

        if (slot.index !== index) {
            fill(slot, index);
        }

        setShown(slot, true);

        const distance = index - position;
        const away = Math.abs(distance);
        const offset = (distance < 0 ? -1 : 1) * lerpKnots(COUNTER.offset, distance);

        slot.item.style.transform = 'translateY(' + offset.toFixed(1) + 'px) scale(' + lerpKnots(COUNTER.scale, distance).toFixed(3) + ')';
        slot.item.style.opacity = lerpKnots(COUNTER.opacity, distance).toFixed(3);
        slot.value.style.color = mixColor(colors.accent, colors.muted, Math.min(1, away));
        slot.unit.style.opacity = Math.max(0, 1 - away * 2.2).toFixed(2);
    });
}

onMounted(() => {
    const style = getComputedStyle(document.documentElement);

    colors = {
        accent: hexToRgb(style.getPropertyValue('--color-accent')),
        muted: hexToRgb(style.getPropertyValue('--color-text-2'))
    };

    build();
});

defineExpose({ render });
</script>

<template>
    <div class="quantity-counter__head" :style="{ top: Math.max(HEAD_MIN, center - HEAD_ABOVE) + 'px' }">
        <ProductImage size="md" :image-url="imageUrl" :icon="icon" :category="category" />
        <span class="quantity-counter__name" lang="de">{{ name }}</span>
        <span class="quantity-counter__meta">{{ meta }}</span>
    </div>

    <div class="quantity-counter__band" :style="{ top: center - BAND_HALF + 'px' }"></div>

    <div ref="drum" class="quantity-counter__drum" :style="{ maskImage, WebkitMaskImage: maskImage }" aria-hidden="true"></div>

    <div v-if="removes" class="quantity-counter__zero" :style="{ top: center + ZERO_OFFSET + 'px' }">
        <Trash2 class="quantity-counter__icon" aria-hidden="true" />
        {{ t('holdLayer.releaseRemoves') }}
    </div>
</template>

<style scoped>
.quantity-counter__head {
    position: absolute;
    left: var(--space-6);
    right: var(--space-6);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.625rem;
    text-align: center;
}

.quantity-counter__name {
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.quantity-counter__meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.quantity-counter__band {
    position: absolute;
    left: var(--space-6);
    right: var(--space-6);
    height: var(--hold-band-height);
    border-radius: var(--radius-lg);
    background: var(--color-accent-soft);
    box-shadow: inset 0 0 0 2px var(--color-accent);
}

.quantity-counter__drum {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.quantity-counter__drum :deep(.quantity-counter__item) {
    position: absolute;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    height: var(--hold-drum-height);
    will-change: transform, opacity;
}

.quantity-counter__drum :deep(.quantity-counter__number) {
    position: relative;
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-bold);
    letter-spacing: -0.02em;
    line-height: var(--hold-drum-height);
    white-space: nowrap;
}

.quantity-counter__drum :deep(.quantity-counter__unit) {
    position: absolute;
    left: 100%;
    bottom: 2.0625rem;
    margin-left: var(--space-2);
    color: var(--color-accent-text);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    letter-spacing: 0;
    line-height: 1.625rem;
}

.quantity-counter__zero {
    position: absolute;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    color: var(--color-notgot);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.quantity-counter__icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}
</style>
