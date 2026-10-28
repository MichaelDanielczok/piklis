<script setup>
/**
 * Zeile im Produktkatalog: Bild bzw. Ersatzicon, Name (eine Zeile) und
 * darunter Kategorie und Packungsgröße; ausgeblendete Produkte mit Symbol
 * und Text. Ganze Zeile ist ein Knopf (öffnet das Produkt); `selected`
 * markiert das geöffnete Produkt am Desktop (aria-current).
 * Feste Höhe 64 px wie die Listenzeile.
 */
import { computed } from 'vue';
import { ChevronRight, EyeOff } from '@lucide/vue';
import ProductImage from './ProductImage.vue';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    selected: {
        type: Boolean,
        default: false
    }
});

defineEmits(['open']);

const meta = computed(() => {
    const parts = [];

    if (props.product.category) {
        parts.push(t('categories.' + props.product.category));
    }

    if (props.product.packageSize) {
        parts.push(props.product.packageSize);
    }

    return parts.join(' · ');
});
</script>

<template>
    <button
        type="button"
        class="product-row"
        :aria-current="selected ? 'true' : undefined"
        @click="$emit('open', product)"
    >
        <ProductImage
            :image-url="product.imageUrl"
            :icon="iconOf(product)"
            :category="categoryOf(product)"
            :muted="product.hidden"
        />
        <span class="product-row__text">
            <span class="product-row__name">{{ product.name }}</span>
            <span v-if="product.hidden" class="product-row__meta product-row__meta--hidden">
                <EyeOff class="product-row__meta-icon" aria-hidden="true" />
                {{ t('products.hidden') }}
            </span>
            <span v-else-if="meta" class="product-row__meta">{{ meta }}</span>
        </span>
        <ChevronRight class="product-row__chevron" aria-hidden="true" />
    </button>
</template>

<style scoped>
.product-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    height: var(--row-min-height);
    padding: 0 var(--space-3) 0 var(--space-3);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .product-row:hover {
        background: var(--color-surface-done);
    }
}

.product-row:active {
    background: var(--color-surface-2);
}

.product-row:focus-visible {
    outline-offset: calc(-1 * var(--focus-ring-width));
}

.product-row[aria-current="true"] {
    background: var(--color-accent-tint);
    box-shadow: inset var(--space-1) 0 0 var(--color-accent);
}

.product-row__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.product-row__name,
.product-row__meta {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.product-row__name {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-name);
}

.product-row__meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.product-row__meta--hidden {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    color: var(--color-notgot);
    font-weight: var(--font-weight-medium);
}

.product-row__meta-icon {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.product-row__chevron {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-3);
}
</style>
