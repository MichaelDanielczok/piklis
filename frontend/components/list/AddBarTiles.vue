<script setup>
/**
 * Vorschläge der Eingabeleiste als Kacheln (Variante C), dieselben wie in
 * „Produkte hinzufügen“. `@mousedown.prevent` an der ganzen Fläche hält den Fokus
 * im Feld, die Tastatur bleibt beim Tippen auf eine Kachel offen. Erst das Menü
 * des Mengen-Controls nimmt den Fokus; danach bleibt die Tastatur zu, bis man ins
 * Feld tippt.
 *
 * items: [{ product, amount, unit, meta }] · label · emptyText (ohne Kacheln)
 * query: Suchtext, ändert er sich, geht es nach oben
 * freeName: Name für die Zeile „… zur Liste“, leer = keine Zeile
 * Emits: change(product, amount), increase, details(product), free, more (das
 * Ende ist nah)
 */
import { useTemplateRef, watch } from 'vue';
import { Plus } from '@lucide/vue';
import AddTile from './AddTile.vue';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { t } from '@/i18n/index.js';

const NEAR_END_PX = 600;

const props = defineProps({
    items: {
        type: Array,
        required: true
    },
    label: {
        type: String,
        required: true
    },
    emptyText: {
        type: String,
        default: ''
    },
    query: {
        type: String,
        default: ''
    },
    freeName: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['change', 'increase', 'details', 'free', 'more']);

const scroller = useTemplateRef('scroller');

watch(() => props.query, () => {
    if (scroller.value) {
        scroller.value.scrollTop = 0;
    }
});

function onScroll({ target }) {
    if (target.scrollHeight - target.scrollTop - target.clientHeight < NEAR_END_PX) {
        emit('more');
    }
}
</script>

<template>
    <div ref="scroller" class="add-bar-tiles" @mousedown.prevent @scroll.passive="onScroll">
        <button v-if="freeName" type="button" class="add-bar-tiles__free" @click="$emit('free')">
            <Plus class="add-bar-tiles__free-icon" aria-hidden="true" />
            <span class="add-bar-tiles__free-name">{{ t('addBar.freeAdd', { name: freeName }) }}</span>
            <span class="add-bar-tiles__free-hint">{{ t('addBar.freeHint') }}</span>
        </button>

        <ul v-if="items.length > 0" class="add-bar-tiles__grid" :aria-label="label">
            <li v-for="{ product, amount, unit, meta } in items" :key="product.id">
                <AddTile
                    :model-value="amount"
                    :name="product.name"
                    :image-url="product.imageUrl"
                    :icon="iconOf(product)"
                    :category="categoryOf(product)"
                    :unit="unit"
                    :meta="meta"
                    @update:model-value="(value) => $emit('change', product, value)"
                    @increase="$emit('increase')"
                    @open="$emit('details', product)"
                />
            </li>
        </ul>

        <p v-else class="add-bar-tiles__empty">{{ emptyText }}</p>
    </div>
</template>

<style scoped>
.add-bar-tiles {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-height: 0;
    padding: var(--space-2) var(--page-gutter) calc(var(--keyboard-height) + var(--space-2));
    overflow-y: auto;
    overscroll-behavior: contain;
}

.add-bar-tiles__grid {
    flex: none;
    display: grid;
    grid-template-columns: repeat(var(--tile-columns), minmax(0, 1fr));
    gap: var(--space-2);
}

.add-bar-tiles__free {
    flex: none;
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--tap-size-main);
    padding: 0 var(--space-3);
    border: 1.5px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    color: var(--color-text-1);
    text-align: left;
}

.add-bar-tiles__free:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.add-bar-tiles__free-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-accent-text);
}

.add-bar-tiles__free-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.add-bar-tiles__free-hint {
    flex: none;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
}

.add-bar-tiles__empty {
    padding: var(--space-2) var(--space-1);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
