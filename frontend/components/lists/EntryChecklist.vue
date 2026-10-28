<script setup>
/**
 * Produkte zum Übernehmen an- und abwählen (Designpaket 07): Kästchen, Bild
 * oder Icon, Name mit Notiz bzw. Zustand, Menge rechts. Die ganze Zeile ist
 * die Beschriftung des Kästchens.
 *
 * items: `{ id, name, imageUrl, icon, note, meta, quantity: { amount, unit } }`
 * v-model:selected: Array der gewählten IDs
 * loading: so viele Zeilen in Endhöhe, wie erwartet (`skeletonRows`)
 */
import ProductImage from '@/components/products/ProductImage.vue';
import QuantityLabel from '@/components/list/QuantityLabel.vue';

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    label: {
        type: String,
        required: true
    },
    loading: {
        type: Boolean,
        default: false
    },
    skeletonRows: {
        type: Number,
        default: 3
    }
});

const selected = defineModel('selected', { type: Array, default: () => [] });

function toggle(id, checked) {
    const current = new Set(selected.value);

    if (checked) {
        current.add(id);
    } else {
        current.delete(id);
    }

    // Reihenfolge der Liste behalten
    selected.value = props.items.map((item) => item.id).filter((itemId) => current.has(itemId));
}
</script>

<template>
    <ul v-if="loading" class="checklist" aria-busy="true">
        <li v-for="index in skeletonRows" :key="index" class="checklist__row checklist__row--skeleton" aria-hidden="true">
            <span class="checklist__box"></span>
            <span class="checklist__skeleton-image"></span>
            <span class="checklist__skeleton-line"></span>
        </li>
    </ul>

    <ul v-else class="checklist" :aria-label="label">
        <li v-for="item in items" :key="item.id">
            <label class="checklist__row">
                <input
                    class="checklist__box"
                    type="checkbox"
                    :checked="selected.includes(item.id)"
                    @change="toggle(item.id, $event.target.checked)"
                >
                <ProductImage :image-url="item.imageUrl" :icon="item.icon" />
                <span class="checklist__text">
                    <span class="checklist__name">{{ item.name }}</span>
                    <span v-if="item.meta" class="checklist__meta">{{ item.meta }}</span>
                    <span v-else-if="item.note" class="checklist__meta">{{ item.note }}</span>
                </span>
                <QuantityLabel class="checklist__quantity" :amount="item.quantity.amount" :unit="item.quantity.unit" />
            </label>
        </li>
    </ul>
</template>

<style scoped>
.checklist {
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.checklist > li + li {
    border-top: 1px solid var(--color-border-subtle);
}

.checklist__row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-4);
    cursor: pointer;
}

.checklist__row--skeleton {
    cursor: default;
}

.checklist__box {
    flex: none;
    display: grid;
    place-content: center;
    width: var(--space-6);
    height: var(--space-6);
    margin: 0;
    border: 2px solid var(--color-ring);
    border-radius: var(--radius-xs);
    background: var(--color-surface-1);
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}

.checklist__box:checked {
    border-color: var(--color-accent);
    background: var(--color-accent);
}

/* Haken als Rahmenecke, ohne Bild */
.checklist__box:checked::after {
    content: "";
    width: 0.375rem;
    height: 0.75rem;
    margin-top: -0.125rem;
    border: solid var(--color-on-accent);
    border-width: 0 3px 3px 0;
    transform: rotate(45deg);
}

.checklist__box:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
}

.checklist__row--skeleton .checklist__box {
    border-color: var(--color-skeleton);
}

.checklist__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.checklist__name {
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
}

.checklist__meta {
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.checklist__quantity {
    flex: none;
}

.checklist__skeleton-image {
    flex: none;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.checklist__skeleton-line {
    width: 40%;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}
</style>
