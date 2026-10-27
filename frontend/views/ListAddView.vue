<script setup>
/**
 * Vorläufiges „Produkte hinzufügen“: einfache Suche im geladenen Katalog
 * (ohne Tastatur beim Öffnen), darunter alle Produkte alphabetisch. Plus
 * fügt mit Menge 1 am Ende der Liste hinzu; steht das Produkt schon offen auf
 * der Liste, erhöht Plus die Menge (kein zweiter Eintrag). Die Ansicht
 * bleibt für mehrere Hinzufügungen offen, „Fertig“ führt zurück.
 *
 * Die richtige Auswahl mit Kacheln und Vorschlägen kommt in Schritt 6.
 */
import { computed, ref } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Plus } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import SearchField from '@/components/ui/SearchField.vue';
import ProductImage from '@/components/products/ProductImage.vue';
import ProductRowSkeleton from '@/components/products/ProductRowSkeleton.vue';
import { activeEntryOfProduct, addProduct, listView, loadList } from '@/composables/useLists.js';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { iconOf } from '@/products/catalog.js';
import { formatQuantity } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const SEARCH_LIMIT = 60;
const SKELETON_ROWS = 8;

const route = useRoute();
const ionRouter = useIonRouter();

const listId = String(route.params.id ?? '');
const backTo = '/lists/' + encodeURIComponent(listId);

const view = listView(listId);
const { catalog, status: productStatus, search } = useProducts();

const query = ref('');
const announcement = ref('');

const searching = computed(() => query.value.trim() !== '');

const shown = computed(() => (searching.value ? search(query.value, { limit: SEARCH_LIMIT }) : catalog.value));

const loading = computed(() => productStatus.value !== 'ready' && productStatus.value !== 'error');

onIonViewWillEnter(() => {
    if (view.value.status !== 'ready') {
        loadList(listId).catch((error) => console.error(error));
    }

    loadProducts().catch((error) => console.error(error));
});

function onList(product) {
    return activeEntryOfProduct(view.value.entries, product.id);
}

async function add(product) {
    const { added, entryId } = await addProduct(listId, product.id);
    const entry = view.value.entries?.get(entryId);
    const quantity = entry ? formatQuantity(entry.quantity.amount, entry.quantity.unit) : '';

    announcement.value = added
        ? t('addProducts.added', { name: product.name })
        : t('addProducts.increased', { name: product.name, quantity });
}

function done() {
    navigateBack(ionRouter, backTo);
}
</script>

<template>
    <AppPage :title="t('addProducts.shortTitle')" :subtitle="view.list?.name ?? '\u00a0'" back :back-to="backTo" width="list" :user-menu="false">
        <template #actions>
            <button type="button" class="add__done" @click="done">{{ t('common.done') }}</button>
        </template>

        <div class="add">
            <SearchField v-model="query" :label="t('addProducts.searchLabel')" :placeholder="t('addProducts.searchPlaceholder')" />
            <p class="add__hint">{{ t('addProducts.hint') }}</p>

            <ul v-if="loading" class="add__list" aria-busy="true">
                <li v-for="index in SKELETON_ROWS" :key="index" class="add__item">
                    <ProductRowSkeleton />
                </li>
            </ul>

            <ul v-else-if="shown.length > 0" class="add__list">
                <li v-for="product in shown" :key="product.id" class="add__item">
                    <div class="add__row" :class="{ 'add__row--selected': onList(product) }">
                        <ProductImage :image-url="product.imageUrl" :icon="iconOf(product)" />
                        <span class="add__text">
                            <span class="add__name">{{ product.name }}</span>
                            <span v-if="onList(product)" class="add__meta add__meta--selected">
                                {{ t('addProducts.onList', { quantity: formatQuantity(onList(product).quantity.amount, onList(product).quantity.unit) }) }}
                            </span>
                            <span v-else-if="product.packageSize" class="add__meta">{{ product.packageSize }}</span>
                        </span>
                        <button
                            type="button"
                            class="add__plus"
                            :aria-label="onList(product) ? t('addProducts.increase', { name: product.name }) : t('addProducts.add', { name: product.name })"
                            @click="add(product)"
                        >
                            <Plus aria-hidden="true" />
                        </button>
                    </div>
                </li>
            </ul>

            <p v-else class="add__empty">
                {{ searching ? t('addProducts.noResults', { query: query.trim() }) : t('addProducts.emptyCatalog') }}
            </p>

            <p class="visually-hidden" aria-live="polite">{{ announcement }}</p>
        </div>
    </AppPage>
</template>

<style scoped>
.add {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-2) var(--page-gutter) 0;
}

.add__done {
    min-height: var(--tap-size);
    padding: 0 var(--space-4);
    border-radius: var(--radius-pill);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    transition: transform var(--duration-fast) var(--ease-out);
}

.add__done:active {
    transform: scale(var(--press-scale));
}

.add__hint,
.add__empty {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.add__list {
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.add__item + .add__item {
    border-top: 1px solid var(--color-border-subtle);
}

.add__row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-2) var(--space-2) var(--space-3);
}

.add__row--selected {
    box-shadow: inset 3px 0 0 var(--color-accent);
}

.add__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.add__name {
    overflow: hidden;
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-medium);
    line-height: var(--line-height-name);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.add__meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.add__meta--selected {
    color: var(--color-accent-text);
    font-weight: var(--font-weight-semibold);
}

.add__plus {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    background: var(--color-accent);
    color: var(--color-on-accent);
    transition: transform var(--duration-fast) var(--ease-out);
}

.add__plus:active {
    transform: scale(0.92);
}

.add__plus :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}
</style>
