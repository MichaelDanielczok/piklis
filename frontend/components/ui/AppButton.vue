<script setup>
/**
 * Knopf der App. variant: primary (grün, Hauptaktion) · secondary (Fläche 2).
 * Höhe 52 px, Rundung 14 px. `block`: volle Breite.
 */
defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary'].includes(value)
    },
    type: {
        type: String,
        default: 'button'
    },
    block: {
        type: Boolean,
        default: false
    }
});
</script>

<template>
    <button :type="type" class="app-button" :class="['app-button--' + variant, { 'app-button--block': block }]">
        <slot />
    </button>
</template>

<style scoped>
.app-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--button-height);
    padding: 0 var(--space-6);
    border-radius: var(--radius-md);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.app-button:active {
    transform: scale(var(--press-scale));
}

.app-button:disabled {
    opacity: 0.6;
    cursor: default;
}

.app-button--block {
    width: 100%;
}

.app-button--primary {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.app-button--secondary {
    background: var(--color-surface-2);
    color: var(--color-text-1);
}

@media (hover: hover) {
    .app-button--primary:hover {
        background: var(--color-accent-hover);
    }

    .app-button--secondary:hover {
        background: var(--color-surface-3);
    }
}

.app-button :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}
</style>
