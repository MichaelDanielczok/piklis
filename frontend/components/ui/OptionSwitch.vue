<script setup>
/**
 * Auswahl aus wenigen Möglichkeiten als Segmente (Radiogruppe), etwa in den
 * Einstellungen. Pfeiltasten wechseln wie bei Radioknöpfen.
 *
 * v-model · options: [{ value, label }] · label: Name der Gruppe
 */
import { useTemplateRef } from 'vue';

const props = defineProps({
    options: {
        type: Array,
        required: true
    },
    label: {
        type: String,
        required: true
    }
});

const model = defineModel({ type: String, required: true });
const root = useTemplateRef('root');

function onKeydown(event, index) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

    if (!step) {
        return;
    }

    event.preventDefault();

    const next = (index + step + props.options.length) % props.options.length;

    model.value = props.options[next].value;
    root.value?.children[next]?.focus();
}
</script>

<template>
    <div ref="root" class="option-switch" role="radiogroup" :aria-label="label">
        <button
            v-for="(option, index) in options"
            :key="option.value"
            type="button"
            class="option-switch__option"
            role="radio"
            :aria-checked="model === option.value"
            :tabindex="model === option.value ? 0 : -1"
            :data-value="option.value"
            @click="model = option.value"
            @keydown="onKeydown($event, index)"
        >
            {{ option.label }}
        </button>
    </div>
</template>

<style scoped>
.option-switch {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-1);
    padding: var(--space-1);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.option-switch__option {
    min-height: var(--tap-size);
    padding: var(--space-1);
    border-radius: var(--radius-sm);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        color var(--duration-fast) var(--ease-out);
}

.option-switch__option[aria-checked="true"] {
    background: var(--color-surface-1);
    color: var(--color-accent-text);
    box-shadow: var(--shadow-1), 0 0 0 1px var(--color-border);
}

.option-switch__option:focus-visible {
    outline-offset: 0;
}
</style>
