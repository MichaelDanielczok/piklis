<script setup>
/**
 * Fläche für Listenzeilen: eine Karte mit Trennlinien zwischen den Zeilen
 * statt einzelner Karten. Inhalt: ListRow bzw. SkeletonRow.
 */
</script>

<template>
    <ul class="row-group">
        <slot />
    </ul>
</template>

<style scoped>
.row-group {
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}
</style>
