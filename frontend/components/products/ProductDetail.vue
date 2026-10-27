<script setup>
/**
 * Produktdetail (Designpaket 10): großes Bild bzw. Ersatzicon, Kategorie als
 * Chip, vollständiger Name, Packungsgröße, dauerhafte Beschreibung.
 * Darunter die Aktionen, die der Benutzer darf: Bearbeiten (eigene Produkte,
 * Produktpfleger alle) und Aus-/Einblenden (nur Produktpfleger). Ohne Recht
 * steht dort ein Hinweis statt der Knöpfe.
 *
 * Genutzt auf der Detailseite (Handy) und rechts in der Produktpflege
 * (Desktop). `headingLevel` passt die Überschrift an die Umgebung an.
 * Emits: edit
 */
import { computed, ref } from 'vue';
import { Eye, EyeOff, Pencil } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import ProductImage from './ProductImage.vue';
import { canEditProduct, setProductHidden, useProducts } from '@/composables/useProducts.js';
import { showToast } from '@/composables/useToast.js';
import { iconOf } from '@/products/catalog.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    headingLevel: {
        type: Number,
        default: 2
    }
});

defineEmits(['edit']);

const { canManageProducts, isOwn } = useProducts();

const canEdit = computed(() => canEditProduct(props.product));
const pending = ref(false);

async function toggleHidden(hidden) {
    pending.value = true;

    try {
        const product = await setProductHidden(props.product.id, hidden);

        showToast(t(hidden ? 'products.hiddenToast' : 'products.unhiddenToast', { name: product.name }), {
            icon: 'info',
            action: hidden
                ? { text: t('common.undo'), handler: () => toggleHidden(false) }
                : undefined
        });
    } catch (error) {
        showToast(t(error?.status === 403 ? 'products.forbidden' : 'products.saveError'), { icon: 'notgot' });
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <article class="product-detail">
        <ProductImage
            class="product-detail__image"
            :image-url="product.imageUrl"
            :icon="iconOf(product)"
            size="hero"
            :muted="product.hidden"
        />

        <div class="product-detail__head">
            <p class="product-detail__chips">
                <span class="product-detail__chip">
                    {{ product.category ? t('categories.' + product.category) : t('products.noCategory') }}
                </span>
                <span v-if="product.hidden" class="product-detail__chip product-detail__chip--hidden">
                    <EyeOff aria-hidden="true" />
                    {{ t('products.hidden') }}
                </span>
            </p>
            <component :is="'h' + headingLevel" class="product-detail__name">{{ product.name }}</component>
            <p v-if="product.packageSize" class="product-detail__size">{{ product.packageSize }}</p>
        </div>

        <p v-if="product.description" class="product-detail__description">{{ product.description }}</p>
        <p v-else class="product-detail__description product-detail__description--empty">
            {{ t('products.noDescription') }}
        </p>

        <p v-if="product.hidden" class="product-detail__note">{{ t('products.hiddenHint') }}</p>

        <div class="product-detail__actions">
            <AppButton v-if="canEdit" variant="primary" @click="$emit('edit')">
                <Pencil aria-hidden="true" />
                {{ t('products.edit') }}
            </AppButton>
            <template v-if="canManageProducts">
                <AppButton v-if="product.hidden" variant="secondary" :disabled="pending" @click="toggleHidden(false)">
                    <Eye aria-hidden="true" />
                    {{ t('products.unhide') }}
                </AppButton>
                <AppButton v-else variant="secondary" :disabled="pending" @click="toggleHidden(true)">
                    <EyeOff aria-hidden="true" />
                    {{ t('products.hide') }}
                </AppButton>
            </template>
        </div>

        <p v-if="!canEdit" class="product-detail__note">{{ t('products.readOnly') }}</p>
        <p v-else-if="isOwn(product) && !canManageProducts" class="product-detail__note">{{ t('products.own') }}</p>
    </article>
</template>

<style scoped>
.product-detail {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.product-detail__image {
    align-self: center;
}

.product-detail__head {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.product-detail__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.product-detail__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    min-height: var(--space-8);
    padding: 0 var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
    color: var(--color-text-1);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
}

.product-detail__chip--hidden {
    color: var(--color-notgot);
}

.product-detail__chip :deep(svg) {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}

.product-detail__name {
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
    overflow-wrap: anywhere;
}

.product-detail__size {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.product-detail__description {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.product-detail__description--empty {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
}

.product-detail__actions {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: var(--space-3);
}

.product-detail__actions:empty {
    display: none;
}

.product-detail__note {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
