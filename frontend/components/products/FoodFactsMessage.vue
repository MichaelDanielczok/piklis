<script setup>
/**
 * Meldung rund um Open Food Facts: Symbol und Text auf einer ruhigen Fläche.
 * `role` (status · alert) geht ans Wurzelelement.
 *
 * Props: icon (Lucide-Komponente) · Slot: default (Text)
 */
defineProps({
    icon: {
        type: [Object, Function],
        required: true
    }
});
</script>

<template>
    <div class="off-message">
        <component :is="icon" class="off-message__icon" aria-hidden="true" />
        <p><slot /></p>
    </div>
</template>

<style scoped>
.off-message {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-message__icon {
    flex: none;
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
    color: var(--color-text-2);
}
</style>
