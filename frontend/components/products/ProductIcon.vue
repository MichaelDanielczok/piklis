<script setup>
/**
 * Ein Ersatzicon aus dem Sprite (icons/sprite.js). Größe und Farbe vom
 * umgebenden Element (width/height, color). Rein dekorativ.
 */
import { computed } from 'vue';
import { DEFAULT_ICON, FALLBACK_ICONS } from '@/icons/sprite.js';

const props = defineProps({
    name: {
        type: String,
        default: DEFAULT_ICON
    }
});

const href = computed(() => '#pk-' + (FALLBACK_ICONS.includes(props.name) ? props.name : DEFAULT_ICON));
</script>

<template>
    <svg class="product-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <use :href="href" />
    </svg>
</template>

<style scoped>
.product-icon {
    display: block;
    flex: none;
}
</style>
