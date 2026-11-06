<script setup>
/**
 * Vorschläge der Eingabeleiste. `@mousedown.prevent` an der ganzen Liste hält
 * den Fokus im Feld, die Tastatur bleibt beim Tippen auf einen Vorschlag offen.
 *
 * items: [{ product, meta }] · label · emptyText (ohne Vorschläge)
 * Emits: pick(product)
 */
import { Plus } from '@lucide/vue';

defineProps({
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
    }
});

defineEmits(['pick']);
</script>

<template>
    <ul class="add-bar-list" :aria-label="label" @mousedown.prevent>
        <li v-for="{ product, meta } in items" :key="product.id">
            <button type="button" class="add-bar-list__item" @click="$emit('pick', product)">
                <span class="add-bar-list__name">{{ product.name }}</span>
                <span v-if="meta" class="add-bar-list__meta">{{ meta }}</span>
                <Plus class="add-bar-list__icon" aria-hidden="true" />
            </button>
        </li>
        <li v-if="items.length === 0" class="add-bar-list__empty">{{ emptyText }}</li>
    </ul>
</template>

<style scoped>
.add-bar-list {
    overflow-y: auto;
    overscroll-behavior: contain;
    background: var(--color-surface-1);
}

.add-bar-list__item {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--space-14);
    padding: 0 var(--space-3) 0 var(--space-4);
    border-top: 1px solid var(--color-border-subtle);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    text-align: left;
}

.add-bar-list__item:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: calc(var(--focus-ring-width) * -1);
}

.add-bar-list__empty {
    display: flex;
    align-items: center;
    min-height: var(--space-14);
    padding: var(--space-2) var(--space-4);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
}

.add-bar-list__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.add-bar-list__meta {
    flex: none;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
}

.add-bar-list__icon {
    flex: none;
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
    color: var(--color-accent-text);
}
</style>
