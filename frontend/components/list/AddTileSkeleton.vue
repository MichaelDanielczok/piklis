<script setup>
/**
 * Ladekachel für „Produkte hinzufügen“: genau so hoch wie `AddTile`
 * (124 px), flach ohne Schimmer; Bildfeld, zwei Namenszeilen, Plus-Kreis.
 */
</script>

<template>
    <div class="add-tile-skeleton" aria-hidden="true">
        <div class="add-tile-skeleton__head">
            <span class="add-tile-skeleton__image"></span>
            <span class="add-tile-skeleton__text">
                <span class="add-tile-skeleton__line"></span>
                <span class="add-tile-skeleton__line add-tile-skeleton__line--short"></span>
            </span>
        </div>
        <span class="add-tile-skeleton__plus"></span>
    </div>
</template>

<style scoped>
.add-tile-skeleton {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: var(--tile-height);
    padding: var(--space-3);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.add-tile-skeleton__head {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
}

.add-tile-skeleton__image {
    flex: none;
    width: var(--thumb-size-tile);
    height: var(--thumb-size-tile);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.add-tile-skeleton__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-2);
    padding-top: var(--space-1);
}

.add-tile-skeleton__line {
    width: 80%;
    height: var(--space-3);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.add-tile-skeleton__line--short {
    width: 50%;
}

.add-tile-skeleton__plus {
    align-self: flex-end;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border-radius: 50%;
    background: var(--color-skeleton);
}
</style>
