<script setup>
/**
 * Produktbild: Handyfoto oder Ersatzicon, beide mit gleicher Größe und
 * Rundung, damit gemischte Listen ruhig wirken. Fotos quadratisch
 * zugeschnitten (object-fit: cover), ohne Kontur; Ersatzicons als Strich auf
 * Icon-Feld. Lädt ein Foto nicht, erscheint das Ersatzicon.
 *
 * Dekorativ (alt=""): Der Produktname steht immer daneben.
 *
 * category: färbt Feld und Linie des Ersatzicons dezent nach der Kategorie
 * (`data-category`, Tokens `--cat-…` in tokens.css); ohne Kategorie neutral.
 *
 * size: row (44 px, Listenzeile) · tile (48 px, Hinzufügen-Kachel) · lg (80 px) ·
 * hero (Produktdetail: quadratisch, volle Breite bis 240 px)
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
    category: {
        type: String,
        default: null
    },
    size: {
        type: String,
        default: 'row',
        validator: (value) => ['row', 'tile', 'lg', 'hero'].includes(value)
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
        :data-category="category || undefined"
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
    /* Kategorienfarbe aus data-category (hier oder an der Kachel), sonst neutral */
    background: var(--category-bg, var(--color-surface-2));
    color: var(--category-line, var(--color-icon));
}

.product-image--tile {
    --product-image-size: var(--thumb-size-tile);
}

.product-image--lg {
    --product-image-size: var(--thumb-size-lg);
    border-radius: var(--radius-md);
}

.product-image--hero {
    width: 100%;
    max-width: var(--product-hero-size);
    height: auto;
    aspect-ratio: 1;
    border-radius: var(--radius-md);
}

.product-image--hero .product-image__icon {
    width: var(--product-hero-icon-ratio);
    height: var(--product-hero-icon-ratio);
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

/* erledigt: Foto mit Überlagerung im Seitenton, Icon gedämpft und ohne Kategorienfarbe */
.product-image--muted.product-image--photo::after {
    content: "";
    position: absolute;
    inset: 0;
    background: var(--photo-done-overlay);
}

.product-image--muted:not(.product-image--photo) {
    background: var(--color-surface-2);
    color: var(--color-text-3);
}
</style>
