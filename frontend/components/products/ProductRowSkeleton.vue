<script setup>
/**
 * Ladezeile des Produktkatalogs: genau so hoch wie `ProductRow` (64 px),
 * flach ohne Schimmer.
 */
</script>

<template>
    <div class="product-row-skeleton" aria-hidden="true">
        <span class="product-row-skeleton__image"></span>
        <span class="product-row-skeleton__text">
            <span class="product-row-skeleton__line"></span>
            <span class="product-row-skeleton__line product-row-skeleton__line--short"></span>
        </span>
    </div>
</template>

<style scoped>
.product-row-skeleton {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    height: var(--row-min-height);
    padding: 0 var(--space-3);
}

.product-row-skeleton__image {
    flex: none;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.product-row-skeleton__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-2);
}

.product-row-skeleton__line {
    width: 60%;
    height: var(--space-3);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton);
}

.product-row-skeleton__line--short {
    width: 35%;
    background: var(--color-skeleton-2);
}
</style>
