<script setup>
/**
 * Abschnittslabel in Versalien („ERLEDIGT · 8“). Versalien nur per CSS,
 * Screenreader lesen den Text normal. Überschrift, Ebene über `level`.
 */
import { computed } from 'vue';
import { t } from '@/i18n/index.js';

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    count: {
        type: Number,
        default: null
    },
    level: {
        type: Number,
        default: 2
    }
});

const text = computed(() => (props.count === null ? props.label : t('section.count', { label: props.label, count: props.count })));
</script>

<template>
    <component :is="'h' + level" class="section-label">{{ text }}</component>
</template>

<style scoped>
.section-label {
    padding: var(--space-4) var(--space-2) var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}
</style>
