<script setup>
/**
 * Mengenanzeige: „2 ד, „2 Packungen“, „1,5 kg“. Halbfett mit gleich breiten
 * Ziffern, damit Mengen untereinander ruhig stehen.
 */
import { computed } from 'vue';
import { formatQuantity } from '@/utils/quantity.js';

const props = defineProps({
    amount: {
        type: Number,
        required: true
    },
    unit: {
        type: String,
        default: null
    }
});

const text = computed(() => formatQuantity(props.amount, props.unit));
</script>

<template>
    <span class="quantity">{{ text }}</span>
</template>

<style scoped>
.quantity {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
    white-space: nowrap;
}
</style>
