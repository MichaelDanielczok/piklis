<script setup>
/**
 * Karte einer aktiven Liste in der Übersicht (Designpaket 01): Name und
 * Datum, Herkunft als Chip („nur ich“, „Haushalt“ oder „geteilt“, mit Symbol und
 * ausgeschriebener Bedeutung für Screenreader), Fortschritt als Balken
 * mit „8 von 30“, darunter die Bilder der ersten offenen Produkte und
 * „+ N“ für die übrigen offenen.
 *
 * Der Name ist der Knopf (Überschrift), seine Fläche reicht über die ganze
 * Karte. Feste Höhe, damit der Ladezustand (ListCardSkeleton) passt.
 * Emits: open
 */
import { computed } from 'vue';
import { House, Share2, User } from '@lucide/vue';
import ProductImage from '@/components/products/ProductImage.vue';
import { useProducts } from '@/composables/useProducts.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatShopDate } from '@/utils/shopDate.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    list: {
        type: Object,
        required: true
    },
    headingLevel: {
        type: Number,
        default: 3
    }
});

defineEmits(['open']);

const { findProduct } = useProducts();

const counts = computed(() => props.list.counts);
const date = computed(() => formatShopDate(props.list.shopDate));
const percent = computed(() => (counts.value.total > 0 ? Math.round((counts.value.done / counts.value.total) * 100) : 0));

const thumbs = computed(() => (props.list.preview ?? []).map((productId) => {
    const product = findProduct(productId);

    return {
        id: productId,
        imageUrl: product?.imageUrl ?? null,
        icon: product ? iconOf(product) : undefined,
        category: categoryOf(product)
    };
}));

const isHousehold = computed(() => Boolean(props.list.householdId));

/** Herkunft: mit mir geteilt (Gast) · Haushalt · nur ich */
const origin = computed(() => {
    if (props.list.role === 'guest') {
        return {
            kind: 'shared',
            icon: Share2,
            text: t('lists.originShared'),
            label: t('lists.originSharedLabel', { name: props.list.sharedBy?.name ?? '' })
        };
    }

    return isHousehold.value
        ? { kind: 'household', icon: House, text: t('lists.originHousehold'), label: t('lists.originHouseholdLabel') }
        : { kind: 'personal', icon: User, text: t('lists.originPersonal'), label: t('lists.originPersonalLabel') };
});

const more = computed(() => Math.max(0, counts.value.open - thumbs.value.length));
</script>

<template>
    <article class="list-card">
        <div class="list-card__head">
            <component :is="'h' + headingLevel" class="list-card__title">
                <button type="button" class="list-card__open" @click="$emit('open')">
                    <span class="list-card__name">{{ list.name }}</span>
                    <span v-if="date" class="list-card__date">{{ date }}</span>
                </button>
            </component>
            <span class="list-card__origin" :class="'list-card__origin--' + origin.kind">
                <component :is="origin.icon" class="list-card__origin-icon" aria-hidden="true" />
                <span aria-hidden="true">{{ origin.text }}</span>
                <span class="visually-hidden">{{ origin.label }}</span>
            </span>
        </div>

        <div class="list-card__progress">
            <span class="list-card__bar" aria-hidden="true">
                <span class="list-card__fill" :style="{ width: percent + '%' }"></span>
            </span>
            <span class="list-card__count">
                <span aria-hidden="true">{{ t('lists.progress', { done: counts.done, total: counts.total }) }}</span>
                <span class="visually-hidden">{{ t('lists.progressLabel', { done: counts.done, total: counts.total }) }}</span>
            </span>
        </div>

        <div class="list-card__preview">
            <template v-if="thumbs.length > 0">
                <ProductImage
                    v-for="thumb in thumbs"
                    :key="thumb.id"
                    :image-url="thumb.imageUrl"
                    :icon="thumb.icon"
                    :category="thumb.category"
                />
                <span v-if="more > 0" class="list-card__more">
                    <span aria-hidden="true">{{ t('lists.more', { count: more }) }}</span>
                    <span class="visually-hidden">{{ t('lists.morePreview', { count: more }) }}</span>
                </span>
            </template>
            <span v-else class="list-card__empty">
                {{ counts.total > 0 ? t('lists.allDonePreview') : t('lists.emptyPreview') }}
            </span>
        </div>
    </article>
</template>

<style scoped>
.list-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.list-card:has(.list-card__open:active) {
    transform: scale(var(--press-scale));
}

@media (hover: hover) {
    .list-card:has(.list-card__open:hover) {
        background: var(--color-accent-tint);
    }
}

.list-card__head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--line-height-h2);
}

.list-card__title {
    flex: 1;
    min-width: 0;
}

.list-card__open {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    max-width: 100%;
    text-align: left;
}

/* ganze Karte ist die Fläche des Knopfs */
.list-card__open::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
}

.list-card__open:focus-visible {
    outline: none;
}

.list-card:has(.list-card__open:focus-visible) {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: var(--focus-ring-offset);
}

.list-card__name {
    overflow: hidden;
    color: var(--color-text-1);
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.list-card__date {
    flex: none;
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.list-card__origin {
    display: inline-flex;
    flex: none;
    align-items: center;
    gap: var(--space-1);
    min-height: var(--line-height-h2);
    padding: 0 var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
    color: var(--color-text-1);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-note);
}

.list-card__origin--household {
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
}

/* geteilt: eigene, ruhige Farbe; die Bedeutung tragen Symbol und Text */
.list-card__origin--shared {
    background: var(--cat-lilac-bg);
    color: var(--cat-lilac-line);
}

.list-card__origin-icon {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.list-card__progress {
    display: flex;
    align-items: center;
    gap: var(--space-4);
    min-height: var(--line-height-qty);
}

.list-card__bar {
    flex: 1;
    height: var(--progress-height);
    overflow: hidden;
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
}

.list-card__fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--color-accent);
    transition: width var(--duration-medium) var(--ease-out);
}

.list-card__count {
    flex: none;
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.list-card__preview {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    height: var(--thumb-size);
    overflow: hidden;
}

.list-card__more {
    padding-left: var(--space-1);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-qty);
    white-space: nowrap;
}

.list-card__empty {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
