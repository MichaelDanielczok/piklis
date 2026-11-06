<script setup>
/**
 * Abschnitt im Eintrags-Sheet: Steht das Produkt im Katalog? Ein Listenartikel
 * zeigt „In den Katalog aufnehmen“ (das dürfen alle mit Zugriff auf die Liste,
 * auch Gäste), ein Katalogprodukt den Hinweis „Schon im Katalog“. Gibt es im
 * Katalog schon ein Produkt mit genau diesem Namen, steht der Hinweis auch
 * beim Listenartikel; aufnehmen bleibt möglich, zusammengeführt wird nie
 * automatisch.
 *
 * product (null: nichts anzeigen, z. B. unbekanntes Produkt)
 */
import { computed, ref, watch } from 'vue';
import { Check, CircleAlert, ListPlus } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import { addProductToCatalog, useProducts } from '@/composables/useProducts.js';
import { normalizeSearchText } from '@/utils/productSearch.js';
import { t } from '@/i18n/index.js';

const props = defineProps({
    product: {
        type: Object,
        default: null
    }
});

const { catalog } = useProducts();

const pending = ref(false);
const error = ref('');

const hasNamesake = computed(() => {
    const name = normalizeSearchText(props.product?.name ?? '');

    return catalog.value.some((product) => normalizeSearchText(product.name) === name);
});

watch(() => props.product?.id, () => {
    error.value = '';
});

async function add() {
    pending.value = true;
    error.value = '';

    try {
        await addProductToCatalog(props.product.id);
    } catch (failure) {
        console.warn('Nicht in den Katalog aufgenommen', failure);
        error.value = t(failure?.status === 0 ? 'common.onlineOnly' : 'products.saveError');
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <div v-if="product" class="entry-catalog" aria-live="polite">
        <template v-if="product.listItem">
            <AppButton variant="secondary" block :disabled="pending || product.local" @click="add">
                <ListPlus aria-hidden="true" />
                {{ t('entry.addToCatalog') }}
            </AppButton>
            <p class="form-field__hint">{{ t(hasNamesake ? 'entry.namesakeInCatalog' : 'entry.addToCatalogHint') }}</p>
            <span v-if="error" class="form-field__error">
                <CircleAlert aria-hidden="true" />
                {{ error }}
            </span>
        </template>
        <p v-else class="entry-catalog__known">
            <Check aria-hidden="true" />
            {{ t('entry.inCatalog') }}
        </p>
    </div>
</template>

<style scoped>
.entry-catalog {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.entry-catalog__known {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.entry-catalog__known :deep(svg) {
    flex: none;
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
}
</style>
