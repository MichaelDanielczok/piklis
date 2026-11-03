<script setup>
/**
 * Blauer Offline-Hinweis oben in einer Ansicht (Designpaket 05): Wolke,
 * „Offline.“ und die Zahl der wartenden Änderungen bzw. ein eigener Text.
 * Status nie nur über Farbe: Symbol und Text.
 */
import { computed } from 'vue';
import { CloudOff } from '@lucide/vue';
import { t } from '@/i18n/index.js';

const props = defineProps({
    /** wartende Änderungen; 0 = „Du siehst den Stand auf diesem Gerät …“ */
    count: {
        type: Number,
        default: 0
    },
    /** eigener Text statt der Standardtexte */
    text: {
        type: String,
        default: ''
    }
});

const message = computed(() => {
    if (props.text) {
        return props.text;
    }

    return props.count > 0 ? t('list.offline', { count: props.count }) : t('list.offlineNone');
});
</script>

<template>
    <p class="offline-note" role="status">
        <CloudOff class="offline-note__icon" aria-hidden="true" />
        <span><strong>{{ t('list.offlineTitle') }}</strong> {{ message }}</span>
    </p>
</template>

<style scoped>
.offline-note {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-offline-bg);
    color: var(--color-offline-text);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.offline-note__icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}
</style>
