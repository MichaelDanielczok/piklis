<script setup>
/**
 * Ladekachel für „Produkte hinzufügen“: so hoch wie eine `AddTile` mit
 * einzeiligem Namen (80 px), flach ohne Schimmer; Bildfeld und eine Namenszeile.
 */
</script>

<template>
    <div class="add-tile-skeleton" aria-hidden="true">
        <span class="add-tile-skeleton__image"></span>
        <span class="add-tile-skeleton__line"></span>
    </div>
</template>

<style scoped>
.add-tile-skeleton {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-height: var(--tile-min-height);
    padding: var(--space-2);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.add-tile-skeleton__image {
    flex: none;
    width: var(--thumb-size-tile);
    height: var(--thumb-size-tile);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.add-tile-skeleton__line {
    width: 80%;
    height: var(--space-3);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}
</style>
