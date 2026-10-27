<script setup>
/**
 * Suchfeld mit Lupe und Leeren-Knopf (Designpaket 09: grüne Kontur beim
 * Fokus). Die Beschriftung ist für Screenreader da, sichtbar ist der
 * Platzhalter. Escape leert das Feld.
 *
 * v-model · label (Pflicht) · placeholder
 */
import { Search, X } from '@lucide/vue';
import { ref, useId } from 'vue';
import { t } from '@/i18n/index.js';

defineProps({
    label: {
        type: String,
        required: true
    },
    placeholder: {
        type: String,
        default: ''
    }
});

const model = defineModel({ type: String, default: '' });
const id = useId();
const input = ref(null);

function clear() {
    model.value = '';
    input.value?.focus();
}

function onEscape(event) {
    if (model.value !== '') {
        event.preventDefault();
        model.value = '';
    }
}

defineExpose({
    focus: () => input.value?.focus()
});
</script>

<template>
    <div class="search-field">
        <label :for="id" class="visually-hidden">{{ label }}</label>
        <Search class="search-field__icon" aria-hidden="true" />
        <input
            :id="id"
            ref="input"
            v-model="model"
            class="search-field__input"
            type="search"
            :placeholder="placeholder"
            autocomplete="off"
            autocapitalize="none"
            spellcheck="false"
            enterkeyhint="search"
            @keydown.esc="onEscape"
        >
        <button
            v-if="model"
            type="button"
            class="search-field__clear"
            :aria-label="t('products.clearSearch')"
            @click="clear"
        >
            <X aria-hidden="true" />
        </button>
    </div>
</template>

<style scoped>
.search-field {
    position: relative;
    display: flex;
    align-items: center;
}

.search-field__icon {
    position: absolute;
    left: var(--space-4);
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-accent-text);
    pointer-events: none;
}

.search-field__input {
    width: 100%;
    height: var(--input-height);
    padding: 0 var(--tap-size) 0 calc(var(--space-4) + var(--icon-size-md) + var(--space-3));
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font: inherit;
    font-size: var(--font-size-title);
    -webkit-appearance: none;
    appearance: none;
}

.search-field__input::placeholder {
    color: var(--color-text-2);
    opacity: 1;
}

/* eigener Leeren-Knopf statt dem des Browsers */
.search-field__input::-webkit-search-cancel-button {
    display: none;
}

.search-field__input:focus-visible {
    border-color: var(--color-accent);
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 0;
}

.search-field__clear {
    position: absolute;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    color: var(--color-text-2);
}

.search-field__clear :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    padding: var(--space-1);
    border-radius: 50%;
    background: var(--color-surface-3);
}
</style>
