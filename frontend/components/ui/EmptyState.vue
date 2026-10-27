<script setup>
/**
 * Leerer Zustand bzw. Platzhalter einer Ansicht: Symbol, Titel, Text.
 * `icon`: Lucide-Komponente.
 */
defineProps({
    icon: {
        type: [Object, Function],
        default: null
    },
    title: {
        type: String,
        required: true
    },
    text: {
        type: String,
        default: ''
    }
});
</script>

<template>
    <section class="empty-state">
        <span v-if="icon" class="empty-state__icon">
            <component :is="icon" aria-hidden="true" />
        </span>
        <h2 class="empty-state__title">{{ title }}</h2>
        <p v-if="text" class="empty-state__text">{{ text }}</p>
        <slot />
    </section>
</template>

<style scoped>
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-10) var(--space-6);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    text-align: center;
}

.empty-state__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--space-14);
    height: var(--space-14);
    margin-bottom: var(--space-2);
    border-radius: 50%;
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
}

.empty-state__icon :deep(svg) {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.empty-state__title {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.empty-state__text {
    max-width: 28rem;
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}
</style>
