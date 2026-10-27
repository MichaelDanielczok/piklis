<script setup>
/**
 * Produkte: gemeinsamer Katalog durchsuchen, ansehen und nach den eigenen
 * Rechten pflegen.
 *
 * - Handy: Suche, Filter und Liste; ein Produkt öffnet die Detailseite
 *   (`/products/:id`), „Neues Produkt“ die Seite `/products/new`.
 * - Desktop (ab 64em): Produktpflege in zwei Spalten wie Designpaket 11,
 *   links die Liste, rechts Detail bzw. Formular ohne Seitenwechsel.
 *
 * Gesucht wird lokal im geladenen Katalog (Vorschläge beim Tippen). Unter
 * den Treffern steht immer „„…“ neu anlegen“: ein vorhandenes Produkt kann
 * man öffnen oder bewusst ein neues anlegen, ähnliche Namen werden nie
 * zusammengeführt.
 */
import { computed, ref, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { LayoutGrid, PackagePlus, Plus, TriangleAlert } from '@lucide/vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SearchField from '@/components/ui/SearchField.vue';
import ProductDetail from '@/components/products/ProductDetail.vue';
import ProductForm from '@/components/products/ProductForm.vue';
import ProductRow from '@/components/products/ProductRow.vue';
import ProductRowSkeleton from '@/components/products/ProductRowSkeleton.vue';
import { DESKTOP_QUERY, useMediaQuery } from '@/composables/useMediaQuery.js';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { searchProducts } from '@/utils/productSearch.js';
import { t } from '@/i18n/index.js';

const SKELETON_ROWS = 8;
const SEARCH_LIMIT = 100;

const ionRouter = useIonRouter();
const isDesktop = useMediaQuery(DESKTOP_QUERY);
const { products, catalog, status, canManageProducts, findProduct, isOwn } = useProducts();

const query = ref('');
const filter = ref('all'); // all · own · hidden

// Desktop: was rechts steht (null · detail · edit · new)
const panel = ref({ mode: null, productId: null, name: '' });

const filters = computed(() => {
    const list = [
        { key: 'all', label: t('products.filterAll') },
        { key: 'own', label: t('products.filterOwn') }
    ];

    if (canManageProducts.value) {
        list.push({ key: 'hidden', label: t('products.filterHidden') });
    }

    return list;
});

watch(canManageProducts, (allowed) => {
    if (!allowed && filter.value === 'hidden') {
        filter.value = 'all';
    }
});

const source = computed(() => {
    if (filter.value === 'hidden') {
        return products.value.filter((product) => product.hidden);
    }

    if (filter.value === 'own') {
        return catalog.value.filter((product) => isOwn(product));
    }

    return catalog.value;
});

const searching = computed(() => query.value.trim() !== '');

const shown = computed(() => {
    if (!searching.value) {
        return source.value;
    }

    return searchProducts(query.value, source.value, {
        limit: SEARCH_LIMIT,
        includeHidden: filter.value === 'hidden'
    });
});

// Beim Suchen zeigt die Unterzeile die Treffer statt der Anzahl: nichts verschiebt sich
const resultText = computed(() => (searching.value ? t('products.results', { count: shown.value.length }) : ''));

const subtitle = computed(() => {
    if (status.value !== 'ready') {
        return '';
    }

    return resultText.value || t('products.count', { count: catalog.value.length });
});

const selected = computed(() => (panel.value.productId ? findProduct(panel.value.productId) : null));

const empty = computed(() => {
    if (filter.value === 'own') {
        return { title: t('products.emptyOwnTitle'), text: t('products.emptyOwnText') };
    }

    if (filter.value === 'hidden') {
        return { title: t('products.emptyHiddenTitle'), text: t('products.emptyHiddenText') };
    }

    return { title: t('products.emptyTitle'), text: t('products.emptyText') };
});

function reload() {
    loadProducts().catch((error) => console.error(error));
}

onIonViewWillEnter(reload);

function open(product) {
    if (isDesktop.value) {
        panel.value = { mode: 'detail', productId: product.id, name: '' };
        return;
    }

    ionRouter.push('/products/' + encodeURIComponent(product.id));
}

function startNew(name = '') {
    if (isDesktop.value) {
        panel.value = { mode: 'new', productId: null, name };
        return;
    }

    ionRouter.push('/products/new' + (name ? '?name=' + encodeURIComponent(name) : ''));
}

function onSaved(product) {
    panel.value = { mode: 'detail', productId: product.id, name: '' };
}

function onCancel() {
    panel.value = panel.value.mode === 'edit'
        ? { ...panel.value, mode: 'detail' }
        : { mode: null, productId: null, name: '' };
}
</script>

<template>
    <AppPage :title="t('products.title')" :subtitle="subtitle">
        <template #actions>
            <button type="button" class="products__new" @click="startNew()">
                <Plus class="products__new-icon" aria-hidden="true" />
                <span class="products__new-label">{{ t('products.new') }}</span>
            </button>
        </template>

        <div class="products">
            <section class="products__list-pane" :aria-label="t('products.title')">
                <SearchField
                    v-model="query"
                    :label="t('products.searchLabel')"
                    :placeholder="t('products.searchPlaceholder', { count: catalog.length })"
                />

                <div class="products__filters" role="group" :aria-label="t('products.filter')">
                    <button
                        v-for="item in filters"
                        :key="item.key"
                        type="button"
                        class="products__filter"
                        :aria-pressed="filter === item.key ? 'true' : 'false'"
                        @click="filter = item.key"
                    >
                        {{ item.label }}
                    </button>
                </div>
                <p class="visually-hidden" aria-live="polite">{{ status === 'ready' ? resultText : '' }}</p>

                <ul v-if="status === 'loading' || status === 'idle'" class="products__list" aria-busy="true">
                    <li v-for="index in SKELETON_ROWS" :key="index" class="products__item">
                        <ProductRowSkeleton />
                    </li>
                </ul>

                <EmptyState
                    v-else-if="status === 'error'"
                    :icon="TriangleAlert"
                    :title="t('products.loadError')"
                >
                    <AppButton variant="secondary" @click="reload">{{ t('products.retry') }}</AppButton>
                </EmptyState>

                <template v-else>
                    <ul v-if="shown.length > 0" class="products__list">
                        <li v-for="product in shown" :key="product.id" class="products__item">
                            <ProductRow
                                :product="product"
                                :selected="isDesktop && panel.mode !== 'new' && panel.productId === product.id"
                                @open="open"
                            />
                        </li>
                    </ul>

                    <p v-else-if="searching" class="products__no-results">
                        {{ t('products.noResults', { query: query.trim() }) }}
                    </p>

                    <EmptyState v-else :icon="LayoutGrid" :title="empty.title" :text="empty.text" />

                    <button
                        v-if="searching && filter !== 'hidden'"
                        type="button"
                        class="products__create"
                        @click="startNew(query.trim())"
                    >
                        <span class="products__create-icon">
                            <PackagePlus aria-hidden="true" />
                        </span>
                        <span class="products__create-text">
                            <span class="products__create-title">{{ t('products.createNamed', { name: query.trim() }) }}</span>
                            <span class="products__create-hint">{{ t('products.createNamedHint') }}</span>
                        </span>
                    </button>
                </template>
            </section>

            <aside v-if="isDesktop" class="products__panel" :aria-label="t('products.editTitle')">
                <template v-if="panel.mode === 'new'">
                    <h2 class="products__panel-title">{{ t('products.newTitle') }}</h2>
                    <ProductForm
                        :key="'new-' + panel.name"
                        :initial-name="panel.name"
                        autofocus
                        @saved="onSaved"
                        @cancel="onCancel"
                        @open="open"
                    />
                </template>

                <template v-else-if="panel.mode === 'edit' && selected">
                    <h2 class="products__panel-title">{{ t('products.editTitle') }}</h2>
                    <ProductForm
                        :key="'edit-' + selected.id"
                        :product="selected"
                        @saved="onSaved"
                        @cancel="onCancel"
                    />
                </template>

                <ProductDetail
                    v-else-if="panel.mode === 'detail' && selected"
                    :key="selected.id"
                    :product="selected"
                    @edit="panel = { ...panel, mode: 'edit' }"
                />

                <div v-else class="products__panel-empty">
                    <LayoutGrid class="products__panel-empty-icon" aria-hidden="true" />
                    <h2 class="products__panel-empty-title">{{ t('products.selectTitle') }}</h2>
                    <p class="products__panel-empty-text">{{ t('products.selectText') }}</p>
                </div>
            </aside>
        </div>
    </AppPage>
</template>

<style scoped>
.products {
    padding: var(--space-2) var(--page-gutter) 0;
}

.products__list-pane {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-width: 0;
}

.products__new {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .products__new:hover {
        background: var(--color-accent-hover);
    }
}

.products__new:active {
    transform: scale(var(--press-scale));
}

.products__new-icon {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

/* auf dem Handy nur das Plus, der Text bleibt für Screenreader */
.products__new-label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.products__filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.products__filter {
    min-height: var(--tap-size);
    padding: 0 var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-pill);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .products__filter:hover {
        background: var(--color-surface-2);
    }
}

.products__filter[aria-pressed="true"] {
    border-color: var(--color-accent);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-weight: var(--font-weight-semibold);
}

.products__list {
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.products__item + .products__item {
    border-top: 1px solid var(--color-border-subtle);
}

.products__no-results {
    padding: var(--space-4) var(--space-2) 0;
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.products__create {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--row-min-height);
    padding: var(--space-3);
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-md);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .products__create:hover {
        background: var(--color-surface-1);
    }
}

.products__create-icon {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-accent-soft);
    color: var(--color-accent-text);
}

.products__create-icon :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.products__create-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.products__create-title {
    overflow-wrap: anywhere;
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.products__create-hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

@media (min-width: 64em) {
    .products {
        display: grid;
        grid-template-columns: var(--product-list-width) minmax(0, 1fr);
        align-items: start;
        gap: var(--space-6);
    }

    .products__new {
        width: auto;
        height: var(--tap-size-main);
        padding: 0 var(--space-5);
        border-radius: var(--radius-md);
    }

    .products__new-label {
        position: static;
        width: auto;
        height: auto;
        overflow: visible;
        clip-path: none;
    }

    .products__panel {
        position: sticky;
        top: var(--space-4);
        display: flex;
        flex-direction: column;
        gap: var(--space-5);
        padding: var(--space-6);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-md);
        background: var(--color-surface-1);
    }

    .products__panel-title {
        font-size: var(--font-size-h2);
        font-weight: var(--font-weight-semibold);
        line-height: var(--line-height-h2);
    }

    .products__panel-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: var(--space-2);
        padding: var(--space-14) var(--space-6);
        color: var(--color-text-2);
        text-align: center;
    }

    .products__panel-empty-icon {
        width: var(--icon-size-lg);
        height: var(--icon-size-lg);
        margin-bottom: var(--space-2);
    }

    .products__panel-empty-title {
        color: var(--color-text-1);
        font-size: var(--font-size-name);
        font-weight: var(--font-weight-semibold);
        line-height: var(--line-height-name);
    }

    .products__panel-empty-text {
        max-width: 24rem;
        font-size: var(--font-size-text);
        line-height: var(--line-height-text);
    }
}
</style>
