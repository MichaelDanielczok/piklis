<script setup>
/**
 * Produktdetail als eigene Seite (Handy, oder direkt aufgerufen):
 * großes Bild bzw. Icon, Kategorie, Name, Beschreibung und die erlaubten
 * Aktionen. Am Desktop zeigt die Produktpflege dasselbe rechts neben der Liste.
 */
import { computed } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { SearchX } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ProductDetail from '@/components/products/ProductDetail.vue';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { t } from '@/i18n/index.js';

const route = useRoute();
const ionRouter = useIonRouter();
const { status, findProduct } = useProducts();

// Ionic hält Seiten im Verlauf; die ID gehört zu dieser Seite, nicht zur aktuellen Route
const productId = String(route.params.id ?? '');
const product = computed(() => findProduct(productId));

onIonViewWillEnter(() => {
    loadProducts().catch((error) => console.error(error));
});

function edit() {
    ionRouter.push('/products/' + encodeURIComponent(productId) + '/edit');
}
</script>

<template>
    <AppPage :title="t('products.title')" back back-to="/products" width="narrow">
        <div class="view-body">
            <ProductDetail v-if="product" :product="product" @edit="edit" />

            <div v-else-if="status !== 'ready' && status !== 'error'" class="detail-skeleton" aria-busy="true">
                <span class="detail-skeleton__image"></span>
                <span class="detail-skeleton__line detail-skeleton__line--chip"></span>
                <span class="detail-skeleton__line detail-skeleton__line--title"></span>
                <span class="detail-skeleton__line"></span>
            </div>

            <EmptyState v-else :icon="SearchX" :title="t('products.notFoundTitle')" :text="t('products.notFoundText')" />
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    padding: var(--space-2) var(--page-gutter) 0;
}

.detail-skeleton {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.detail-skeleton__image {
    align-self: center;
    width: 100%;
    max-width: var(--product-hero-size);
    aspect-ratio: 1;
    border-radius: var(--radius-md);
    background: var(--color-skeleton);
}

.detail-skeleton__line {
    width: 70%;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.detail-skeleton__line--chip {
    width: 8rem;
    height: var(--space-8);
    border-radius: var(--radius-pill);
}

.detail-skeleton__line--title {
    height: var(--line-height-h2);
    background: var(--color-skeleton);
}
</style>
