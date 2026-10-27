<script setup>
/**
 * Produktbild: Handyfoto oder Ersatzicon, beide mit gleicher Größe und
 * Rundung, damit gemischte Listen ruhig wirken. Fotos quadratisch
 * zugeschnitten (object-fit: cover), ohne Kontur; Ersatzicons als Strich auf
 * Icon-Feld. Lädt ein Foto nicht, erscheint das Ersatzicon.
 *
 * Dekorativ (alt=""): Der Produktname steht immer daneben.
 *
 * size: row (44 px, Listenzeile) · tile (48 px, Hinzufügen-Kachel) · lg (80 px)
 * muted: erledigte Zeile (Foto mit Überlagerung, Icon gedämpft)
 */
import { ref, watch } from 'vue';
import ProductIcon from './ProductIcon.vue';

const props = defineProps({
    imageUrl: {
        type: String,
        default: null
    },
    icon: {
        type: String,
        default: undefined
    },
    size: {
        type: String,
        default: 'row',
        validator: (value) => ['row', 'tile', 'lg'].includes(value)
    },
    muted: {
        type: Boolean,
        default: false
    }
});

const failed = ref(false);

watch(() => props.imageUrl, () => {
    failed.value = false;
});
</script>

<template>
    <span
        class="product-image"
        :class="[
            'product-image--' + size,
            {
                'product-image--photo': imageUrl && !failed,
                'product-image--muted': muted
            }
        ]"
    >
        <img
            v-if="imageUrl && !failed"
            class="product-image__photo"
            :src="imageUrl"
            alt=""
            loading="lazy"
            decoding="async"
            @error="failed = true"
        >
        <ProductIcon v-else class="product-image__icon" :name="icon" />
    </span>
</template>

<style scoped>
.product-image {
    --product-image-size: var(--thumb-size);
    position: relative;
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--product-image-size);
    height: var(--product-image-size);
    overflow: hidden;
    border-radius: var(--radius-sm);
    background: var(--color-surface-2);
    color: var(--color-icon);
}

.product-image--tile {
    --product-image-size: var(--thumb-size-tile);
}

.product-image--lg {
    --product-image-size: var(--thumb-size-lg);
    border-radius: var(--radius-md);
}

.product-image__photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image__icon {
    width: calc(var(--product-image-size) * var(--thumb-icon-ratio));
    height: calc(var(--product-image-size) * var(--thumb-icon-ratio));
}

/* erledigt: Foto mit Überlagerung im Seitenton, Icon gedämpft */
.product-image--muted.product-image--photo::after {
    content: "";
    position: absolute;
    inset: 0;
    background: var(--photo-done-overlay);
}

.product-image--muted:not(.product-image--photo) {
    color: var(--color-text-3);
}
</style>
