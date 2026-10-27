<script setup>
/**
 * Produkt im Kontext einer Einkaufsliste (Designpaket 10): großes Bild,
 * Name und Beschreibung wie im Katalog, darunter „In der Liste …“ mit
 * Menge und Einkaufsnotiz dieses Einkaufs. Ändern im Sheet (EntrySheet),
 * „Von der Liste entfernen“ mit „Rückgängig“ im Toast.
 *
 * Fehlt das Produkt im Katalog (gelöscht), bleibt der Eintrag bedienbar und
 * heißt „Unbekanntes Produkt“.
 */
import { computed, ref } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Check, PackageX, Pencil, SearchX, Trash2 } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ProductDetail from '@/components/products/ProductDetail.vue';
import QuantityLabel from '@/components/list/QuantityLabel.vue';
import EntrySheet from '@/components/lists/EntrySheet.vue';
import {
    findEntry,
    isVisible,
    listView,
    loadList,
    removeEntry,
    restoreEntry,
    setEntry
} from '@/composables/useLists.js';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { showToast } from '@/composables/useToast.js';
import { t } from '@/i18n/index.js';

const route = useRoute();
const ionRouter = useIonRouter();

const listId = String(route.params.id ?? '');
const entryId = String(route.params.entryId ?? '');
const backTo = '/lists/' + encodeURIComponent(listId);

const view = listView(listId);
const { findProduct, status: productStatus } = useProducts();

const sheetOpen = ref(false);

const entry = computed(() => {
    const current = view.value.entries?.get(entryId) ?? null;

    return current && isVisible(current) ? current : null;
});

const product = computed(() => (entry.value ? findProduct(entry.value.productId) : null));
const productName = computed(() => product.value?.name ?? t('entry.unknownProduct'));
const listName = computed(() => view.value.list?.name ?? '');

const loading = computed(() => {
    const listReady = view.value.entries !== null && view.value.status !== 'idle' && view.value.status !== 'loading';
    const productsReady = productStatus.value === 'ready' || productStatus.value === 'error';

    return view.value.status !== 'missing' && (!listReady || !productsReady);
});

onIonViewWillEnter(() => {
    if (view.value.status !== 'ready') {
        loadList(listId).catch((error) => console.error(error));
    }

    loadProducts().catch((error) => console.error(error));
});

function editProduct() {
    ionRouter.push('/products/' + encodeURIComponent(entry.value.productId) + '/edit');
}

function save(changes) {
    if (entry.value) {
        setEntry(entry.value, changes);
    }
}

async function remove() {
    const current = entry.value;
    const name = productName.value;

    if (!current) {
        return;
    }

    await removeEntry(current);
    showToast(t('entry.removedToast', { name }), {
        icon: 'info',
        action: {
            text: t('common.undo'),
            handler: () => {
                const removed = findEntry(listId, entryId);

                if (removed?.removed) {
                    restoreEntry(removed);
                }
            }
        }
    });
    navigateBack(ionRouter, backTo);
}
</script>

<template>
    <AppPage :title="listName" back :back-to="backTo" width="narrow">
        <div class="view-body">
            <div v-if="loading" class="detail-skeleton" aria-busy="true">
                <span class="detail-skeleton__image"></span>
                <span class="detail-skeleton__line detail-skeleton__line--chip"></span>
                <span class="detail-skeleton__line detail-skeleton__line--title"></span>
                <span class="detail-skeleton__line"></span>
            </div>

            <EmptyState
                v-else-if="!entry"
                :icon="SearchX"
                :title="t('entry.notFoundTitle')"
                :text="t('entry.notFoundText')"
            />

            <template v-else>
                <ProductDetail v-if="product" :product="product" @edit="editProduct">
                    <template #context>
                        <section class="entry-card" :aria-label="t('entry.context', { name: listName })">
                            <h3 class="entry-card__label">{{ t('entry.context', { name: listName }) }}</h3>
                            <dl class="entry-card__facts">
                                <div class="entry-card__fact">
                                    <dt>{{ t('entry.quantity') }}</dt>
                                    <dd><QuantityLabel :amount="entry.quantity.amount" :unit="entry.quantity.unit" /></dd>
                                </div>
                                <div class="entry-card__fact">
                                    <dt>{{ t('entry.note') }}</dt>
                                    <dd :class="{ 'entry-card__empty': !entry.note }">{{ entry.note || t('entry.noNote') }}</dd>
                                </div>
                                <div v-if="entry.status === 'done'" class="entry-card__fact">
                                    <dt class="visually-hidden">{{ t('entry.status') }}</dt>
                                    <dd class="entry-card__done">
                                        <Check aria-hidden="true" />
                                        {{ t('entry.statusDone') }}
                                    </dd>
                                </div>
                            </dl>
                            <AppButton variant="secondary" block @click="sheetOpen = true">
                                <Pencil aria-hidden="true" />
                                {{ t('entry.edit') }}
                            </AppButton>
                        </section>
                    </template>
                </ProductDetail>

                <template v-else>
                    <EmptyState :icon="PackageX" :title="productName" :text="t('entry.unknownProductText')" />
                    <section class="entry-card" :aria-label="t('entry.context', { name: listName })">
                        <h3 class="entry-card__label">{{ t('entry.context', { name: listName }) }}</h3>
                        <dl class="entry-card__facts">
                            <div class="entry-card__fact">
                                <dt>{{ t('entry.quantity') }}</dt>
                                <dd><QuantityLabel :amount="entry.quantity.amount" :unit="entry.quantity.unit" /></dd>
                            </div>
                            <div class="entry-card__fact">
                                <dt>{{ t('entry.note') }}</dt>
                                <dd :class="{ 'entry-card__empty': !entry.note }">{{ entry.note || t('entry.noNote') }}</dd>
                            </div>
                        </dl>
                        <AppButton variant="secondary" block @click="sheetOpen = true">
                            <Pencil aria-hidden="true" />
                            {{ t('entry.edit') }}
                        </AppButton>
                    </section>
                </template>

                <AppButton variant="danger" block class="entry-remove" @click="remove">
                    <Trash2 aria-hidden="true" />
                    {{ t('entry.remove') }}
                </AppButton>

                <EntrySheet
                    v-model:open="sheetOpen"
                    :entry="entry"
                    :product-name="productName"
                    :list-name="listName"
                    @save="save"
                />
            </template>
        </div>
    </AppPage>
</template>

<style scoped>
.view-body {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-2) var(--page-gutter) 0;
}

.entry-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.entry-card__label {
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.entry-card__facts {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
}

.entry-card__fact {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-4);
}

.entry-card__fact dt {
    flex: none;
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.entry-card__fact dd {
    min-width: 0;
    margin: 0;
    font-size: var(--font-size-name);
    line-height: var(--line-height-name);
    text-align: right;
    overflow-wrap: anywhere;
}

.entry-card__empty {
    color: var(--color-text-2);
}

.entry-card__done {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    color: var(--color-done);
    font-weight: var(--font-weight-semibold);
}

.entry-card__done :deep(svg) {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    stroke-width: 3;
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
