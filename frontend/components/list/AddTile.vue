<script setup>
/**
 * Kachel in „Produkte hinzufügen“: feste Höhe (124 px), Bild 48 px, Name
 * max. 2 Zeilen. Unten rechts ein runder Plus-Knopf; nach dem ersten Tipp
 * wird daraus „− 1 +“, das Plus bleibt an genau derselben Stelle, damit
 * schnelle Wiederholungstipps immer dasselbe Produkt treffen.
 * Ausgewählt: 2-px-Innenkontur in Grün (Größe ändert sich nicht).
 * Minus bei 1 entfernt das Produkt wieder (Menge 0).
 *
 * v-model: Menge (0 = nicht ausgewählt).
 * `meta`: kurzer Text unten links, solange nicht ausgewählt (Packungsgröße,
 * „erledigt“); ohne ihn die Einheit.
 * `openLabel`: Bild und Name werden ein eigener Knopf (Ereignis `open`, z. B.
 * Produktdetail), getrennt vom Hinzufügen.
 */
import { computed, nextTick } from 'vue';
import { Minus, Plus } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import { DECIMAL_UNITS, unitName } from '@/utils/quantity.js';
import { formatNumber, t } from '@/i18n/index.js';

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
    unit: {
        type: String,
        default: null
    },
    meta: {
        type: String,
        default: ''
    },
    openLabel: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['open']);

const quantity = defineModel({ type: Number, default: 0 });

const selected = computed(() => quantity.value > 0);
const unitLabel = computed(() => unitName(props.unit));
const footText = computed(() => props.meta || unitLabel.value);
const quantityText = computed(() => formatNumber(quantity.value, {
    maximumFractionDigits: DECIMAL_UNITS.includes(props.unit) ? 3 : 0
}));

// Mehrere Tipps vor dem nächsten Rendern zählen alle: Ausgangswert ist der
// zuletzt gemeldete, nicht der noch alte Wert der Eigenschaft
let pending = null;

function change(delta) {
    pending = Math.max(0, (pending ?? quantity.value) + delta);
    quantity.value = pending;
    nextTick(() => {
        pending = null;
    });
}

function increase() {
    change(1);
}

function decrease() {
    change(-1);
}
</script>

<template>
    <div class="add-tile" :class="{ 'add-tile--selected': selected }">
        <button v-if="openLabel" type="button" class="add-tile__head add-tile__head--button" :aria-label="openLabel" @click="emit('open')">
            <ProductImage size="tile" :image-url="imageUrl" :icon="icon" />
            <span class="add-tile__name">{{ name }}</span>
        </button>
        <div v-else class="add-tile__head">
            <ProductImage size="tile" :image-url="imageUrl" :icon="icon" />
            <span class="add-tile__name">{{ name }}</span>
        </div>

        <div class="add-tile__foot">
            <span v-if="!selected && footText" class="add-tile__unit">{{ footText }}</span>

            <div class="add-tile__stepper">
                <template v-if="selected">
                    <button
                        type="button"
                        class="add-tile__button add-tile__button--minus"
                        :aria-label="quantity === 1 ? t('tile.remove', { name }) : t('tile.decrease', { name })"
                        @click="decrease"
                    >
                        <Minus class="add-tile__icon" aria-hidden="true" />
                    </button>
                    <output class="add-tile__quantity" aria-live="polite">
                        {{ quantityText }}<span v-if="unitLabel" class="visually-hidden"> {{ unitLabel }}</span>
                    </output>
                </template>
                <button
                    type="button"
                    class="add-tile__button add-tile__button--plus"
                    :aria-label="selected ? t('tile.increase', { name }) : t('tile.add', { name })"
                    @click="increase"
                >
                    <Plus class="add-tile__icon" aria-hidden="true" />
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.add-tile {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: var(--tile-height);
    padding: var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    transition: box-shadow var(--duration-fast) var(--ease-out);
}

.add-tile--selected {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

/* Abstand Bild–Name 8 px statt 12: in der schmalen Kachel (Handy) passen
   Wörter wie „Vollkornbrot“ so öfter ohne Trennung in eine Zeile */
.add-tile__head {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    min-width: 0;
}

/* Bild und Name als Knopf: gleiche Fläche, Fokus sichtbar, Text linksbündig */
.add-tile__head--button {
    margin: calc(-1 * var(--space-1));
    padding: var(--space-1);
    border-radius: var(--radius-sm);
    color: inherit;
    font: inherit;
    text-align: left;
}

.add-tile__head--button:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 0;
}

.add-tile__name {
    display: -webkit-box;
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
    hyphens: auto;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}

.add-tile__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    min-width: 0;
}

.add-tile__unit {
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Plus immer ganz rechts: Minus und Zahl kommen links dazu */
.add-tile__stepper {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    margin-left: auto;
}

.add-tile__button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border-radius: 50%;
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.add-tile__button:active {
    transform: scale(0.92);
}

.add-tile__button--plus {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.add-tile__button--minus {
    border: 2px solid var(--color-accent);
    color: var(--color-accent-text);
}

@media (hover: hover) {
    .add-tile__button--plus:hover {
        background: var(--color-accent-hover);
    }

    .add-tile__button--minus:hover {
        background: var(--color-accent-tint);
    }
}

.add-tile__icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}

.add-tile__quantity {
    min-width: var(--space-8);
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    text-align: center;
}
</style>
