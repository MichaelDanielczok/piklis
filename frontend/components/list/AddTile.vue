<script setup>
/**
 * Kachel in „Produkte hinzufügen“: so hoch wie ihr Inhalt (ab 80 px), Bild
 * 40 px, `meta` rechts daneben, darunter der kleine Name in höchstens 2 Zeilen.
 * Das Raster gibt jeder Reihe die Höhe ihrer höchsten Kachel, `height: 100%`
 * füllt sie, Kacheln einer Reihe sind also gleich hoch.
 *
 * - Ein Tipp irgendwo auf die Kachel erhöht um 1 (aus 0 wird 1); die Kachel
 *   behält dabei ihre Größe, schnelle Tipps treffen immer dasselbe Produkt.
 * - Ausgewählt: Badge mit der Menge oben rechts, 2-px-Innenkontur in Grün.
 * - Halten öffnet das Mengen-Control (`useQuantityHold`), dort auch „Details“
 *   (`open`) und „Entfernen“ (Menge 0). Ohne Geste geht es über den Knopf
 *   „Menge“, den nur Tastatur und Bildschirmleser finden.
 *
 * v-model: Menge (0 = nicht ausgewählt).
 * `meta`: kurzer Text neben dem Bild (Packungsgröße, „erledigt“), ohne ihn die
 * Einheit.
 */
import { computed, nextTick, useTemplateRef } from 'vue';
import ProductImage from '@/components/products/ProductImage.vue';
import QuantityControl from './QuantityControl.vue';
import { useQuantityHold } from '@/composables/useQuantityHold.js';
import { formatAmount, formatQuantity, MEASURE_UNITS, unitName } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

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
    unit: {
        type: String,
        default: null
    },
    meta: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['open']);

const quantity = defineModel({ type: Number, default: 0 });

const selected = computed(() => quantity.value > 0);
const metaText = computed(() => props.meta || unitName(props.unit));

// Gewichte brauchen die Einheit, um lesbar zu sein; sonst reicht die Zahl
const badgeText = computed(() => (MEASURE_UNITS.includes(props.unit) ? formatQuantity(quantity.value, props.unit) : formatAmount(quantity.value)));

const label = computed(() => {
    const action = selected.value
        ? t('tile.increase', { name: props.name, quantity: formatQuantity(quantity.value, props.unit) })
        : t('tile.add', { name: props.name });

    return metaText.value ? action + ', ' + metaText.value : action;
});

// Mehrere Tipps vor dem nächsten Rendern zählen alle: Ausgangswert ist der
// zuletzt gemeldete, nicht der noch alte Wert der Eigenschaft
let pending = null;

function increase() {
    pending = (pending ?? quantity.value) + 1;
    quantity.value = pending;
    nextTick(() => {
        pending = null;
    });
}

function setAmount(amount) {
    quantity.value = amount;
}

const { control, openMenu, close, listeners, onClickCapture } = useQuantityHold({
    root: useTemplateRef('root'),
    enabled: () => true,
    amount: () => quantity.value,
    unit: () => props.unit,
    removable: () => true,
    onCommit: setAmount
});
</script>

<template>
    <div
        ref="root"
        class="add-tile"
        :class="{ 'add-tile--selected': selected, 'add-tile--held': control }"
        :data-category="category || undefined"
        v-on="listeners"
        @click.capture="onClickCapture"
    >
        <button type="button" class="add-tile__main" data-name="tile" :aria-label="label" @click="increase">
            <span class="add-tile__top">
                <ProductImage size="tile" :image-url="imageUrl" :icon="icon" :category="category" />
                <span v-if="metaText" class="add-tile__meta">{{ metaText }}</span>
            </span>
            <span class="add-tile__name">{{ name }}</span>
        </button>

        <span v-if="selected" class="add-tile__badge" aria-hidden="true">{{ badgeText }}</span>

        <button
            type="button"
            class="add-tile__more visually-hidden-focusable"
            aria-haspopup="dialog"
            :aria-label="t('quantityControl.open', { name })"
            @click="openMenu($event.currentTarget)"
        >
            {{ t('quantityControl.openShort') }}
        </button>

        <QuantityControl
            v-if="control"
            :state="control"
            :name="name"
            :amount="quantity"
            :unit="unit"
            removable
            @change="setAmount"
            @details="emit('open')"
            @remove="setAmount(0)"
            @close="close"
        />
    </div>
</template>

<style scoped>
/* Halten darf weder Text markieren noch das iOS-Menü für Bilder öffnen */
.add-tile {
    position: relative;
    height: 100%;
    min-height: var(--tile-min-height);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    touch-action: manipulation;
    user-select: none;
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    transition:
        box-shadow var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.add-tile:has(.add-tile__main:active) {
    transform: scale(var(--press-scale));
}

.add-tile--selected {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

.add-tile--held {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent), var(--shadow-drag);
}

.add-tile :deep(img) {
    pointer-events: none;
    -webkit-user-drag: none;
}

.add-tile__main {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    height: 100%;
    padding: var(--space-2);
    border-radius: inherit;
    color: inherit;
    font: inherit;
    text-align: left;
    -webkit-tap-highlight-color: transparent;
}

.add-tile__main:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: calc(-1 * var(--focus-ring-width));
}

/* Keine Silbentrennung: Ein Wort, das nicht in die Kachel passt, endet mit „…“.
   Das geht nur als Block, `line-clamp` kürzt lange Wörter ohne Auslassungszeichen */
.add-tile__name {
    display: block;
    width: 100%;
    max-height: calc(2 * var(--line-height-note));
    margin-top: var(--space-1);
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
}

.add-tile__top {
    display: flex;
    align-items: flex-end;
    gap: var(--space-2);
    width: 100%;
    min-width: 0;
}

/* unten am Bild: oben rechts steht das Mengen-Badge */
.add-tile__meta {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-caption);
    line-height: var(--line-height-label);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.add-tile__badge {
    position: absolute;
    top: var(--space-2);
    right: var(--space-2);
    min-width: var(--tile-badge-size);
    max-width: calc(100% - var(--thumb-size-tile) - var(--space-4));
    padding: 0 var(--space-2);
    overflow: hidden;
    border-radius: var(--radius-pill);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-small);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--tile-badge-size);
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    pointer-events: none;
}

/* erst mit dem Fokus sichtbar: am Finger ist die ganze Kachel der Knopf */
.add-tile__more:focus-visible {
    position: absolute;
    right: var(--space-1);
    bottom: var(--space-1);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    outline: var(--focus-ring-width) solid var(--color-accent);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font-size: var(--font-size-small);
}

@media (prefers-reduced-motion: reduce) {
    .add-tile:has(.add-tile__main:active) {
        transform: none;
    }
}
</style>
