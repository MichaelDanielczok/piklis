<script setup>
/**
 * Treffer bei Open Food Facts: Vorschau, Name, Marke und Packungsgröße. Ohne
 * `result` ein Skeleton in derselben Höhe. Ohne Bild steht das Ersatzicon da.
 *
 * Props: result, icon, category · Emits: imageFailed
 */
import { computed, ref } from 'vue';
import ProductImage from './ProductImage.vue';
import { api } from '@/api/index.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    result: {
        type: Object,
        default: null
    },
    icon: {
        type: String,
        default: undefined
    },
    category: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['imageFailed']);

const failed = ref(false);

const previewUrl = computed(() => (props.result?.hasImage ? api.foodFactsImageUrl(props.result.barcode) : null));
const meta = computed(() => [props.result?.brand, props.result?.packageSize].filter(Boolean).join(' · '));

function onError() {
    failed.value = true;
    emit('imageFailed');
}
</script>

<template>
    <div v-if="!result" class="off-hit" aria-hidden="true">
        <span class="off-hit__skeleton-image"></span>
        <span class="off-hit__text">
            <span class="off-hit__skeleton-line off-hit__skeleton-line--title"></span>
            <span class="off-hit__skeleton-line"></span>
        </span>
    </div>

    <section v-else class="off-hit" :aria-label="t('foodFacts.hitLabel')">
        <span v-if="previewUrl && !failed" class="off-hit__image">
            <img :src="previewUrl" alt="" decoding="async" @error="onError">
        </span>
        <ProductImage v-else :icon="icon" :category="category" size="lg" />
        <span class="off-hit__text">
            <span class="off-hit__name">{{ result.name ?? t('foodFacts.noName') }}</span>
            <span v-if="meta" class="off-hit__meta">{{ meta }}</span>
        </span>
    </section>
</template>

<style scoped>
.off-hit {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.off-hit__image {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    width: var(--thumb-size-lg);
    height: var(--thumb-size-lg);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-hit__image img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.off-hit__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
}

.off-hit__name {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
    overflow-wrap: anywhere;
}

.off-hit__meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.off-hit__skeleton-image {
    flex: none;
    width: var(--thumb-size-lg);
    height: var(--thumb-size-lg);
    border-radius: var(--radius-md);
    background: var(--color-skeleton);
}

.off-hit__skeleton-line {
    width: 60%;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.off-hit__skeleton-line--title {
    width: 85%;
    height: var(--space-5);
    background: var(--color-skeleton);
}
</style>
