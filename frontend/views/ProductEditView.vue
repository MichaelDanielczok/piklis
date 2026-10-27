<script setup>
/**
 * Produkt anlegen (`/products/new?name=…`) oder bearbeiten
 * (`/products/:id/edit`) als eigene Seite (Handy). Ohne Recht zum
 * Bearbeiten erscheint ein Hinweis statt des Formulars; entscheidend ist
 * trotzdem der Server (403).
 */
import { computed } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Lock, SearchX } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ProductForm from '@/components/products/ProductForm.vue';
import { navigateBack } from '@/composables/usePageTransition.js';
import { canEditProduct, loadProducts, useProducts } from '@/composables/useProducts.js';
import { t } from '@/i18n/index.js';

const route = useRoute();
const ionRouter = useIonRouter();
const { status, findProduct } = useProducts();

const productId = route.params.id ? String(route.params.id) : null;
const initialName = typeof route.query.name === 'string' ? route.query.name : '';
const isNew = productId === null;

const product = computed(() => (isNew ? null : findProduct(productId)));
const backTo = isNew ? '/products' : '/products/' + encodeURIComponent(productId);

onIonViewWillEnter(() => {
    loadProducts().catch((error) => console.error(error));
});

function onSaved(saved) {
    if (isNew) {
        // Formular aus dem Verlauf nehmen: Zurück führt dann zur Liste
        ionRouter.navigate('/products/' + encodeURIComponent(saved.id), 'forward', 'replace');
        return;
    }

    navigateBack(ionRouter, backTo);
}

function onOpen(existing) {
    ionRouter.navigate('/products/' + encodeURIComponent(existing.id), 'forward', 'replace');
}
</script>

<template>
    <AppPage :title="t(isNew ? 'products.newTitle' : 'products.editTitle')" back :back-to="backTo" width="narrow">
        <div class="view-body">
            <ProductForm
                v-if="isNew"
                :initial-name="initialName"
                autofocus
                @saved="onSaved"
                @cancel="navigateBack(ionRouter, backTo)"
                @open="onOpen"
            />

            <template v-else-if="product">
                <ProductForm
                    v-if="canEditProduct(product)"
                    :product="product"
                    @saved="onSaved"
                    @cancel="navigateBack(ionRouter, backTo)"
                />
                <EmptyState v-else :icon="Lock" :title="t('products.forbidden')" :text="t('products.readOnly')" />
            </template>

            <EmptyState
                v-else-if="status === 'ready' || status === 'error'"
                :icon="SearchX"
                :title="t('products.notFoundTitle')"
                :text="t('products.notFoundText')"
            />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}
</style>
