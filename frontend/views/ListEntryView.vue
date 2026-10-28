<script setup>
/**
 * Produkt im Kontext einer Einkaufsliste (Designpaket 10): großes Bild,
 * Name und Beschreibung wie im Katalog, darunter „In der Liste …“ mit
 * Menge und Einkaufsnotiz dieses Einkaufs. Ändern im Sheet (EntrySheet),
 * „Von der Liste entfernen“ mit „Rückgängig“ im Toast.
 *
 * Fehlt das Produkt im Katalog (gelöscht), bleibt der Eintrag bedienbar und
 * heißt „Unbekanntes Produkt“.
 *
 * „Nicht bekommen“, „Wieder offen“ und „In andere Liste übernehmen“ stehen
 * hier als Knöpfe (in der Liste per Wischen). Ist der Einkauf abgeschlossen,
 * ist alles nur lesbar.
 */
import { computed, ref } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { PackageX, SearchX, Trash2 } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ProductDetail from '@/components/products/ProductDetail.vue';
import EntryContextCard from '@/components/lists/EntryContextCard.vue';
import EntrySheet from '@/components/lists/EntrySheet.vue';
import TransferSheet from '@/components/lists/TransferSheet.vue';
import {
    findEntry,
    isVisible,
    listView,
    loadList,
    loadLists,
    markNotGot,
    markOpen,
    removeEntry,
    restoreEntry,
    setEntry,
    useLists
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
const transferOpen = ref(false);
const { activeLists, archivedLists, overview } = useLists();

const entry = computed(() => {
    const current = view.value.entries?.get(entryId) ?? null;

    return current && isVisible(current) ? current : null;
});

const product = computed(() => (entry.value ? findProduct(entry.value.productId) : null));
const productName = computed(() => product.value?.name ?? t('entry.unknownProduct'));
const listName = computed(() => view.value.list?.name ?? '');
const readonly = computed(() => view.value.list?.status === 'completed');

const transferredTo = computed(() => {
    const target = entry.value?.transferredTo;

    if (!target || entry.value.status !== 'notGot') {
        return null;
    }

    const lists = [...activeLists.value, ...archivedLists.value, ...overview.lists];

    return lists.find((item) => item.id === target)?.name ?? '';
});

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
    loadLists().catch((error) => console.error(error));
});

function editProduct() {
    ionRouter.push('/products/' + encodeURIComponent(entry.value.productId) + '/edit');
}

function save(changes) {
    if (entry.value) {
        setEntry(entry.value, changes);
    }
}

async function notGot() {
    const current = entry.value;
    const name = productName.value;

    if (!current) {
        return;
    }

    await markNotGot(current);
    showToast(t('list.notGotToast', { name }), {
        icon: 'notgot',
        action: {
            text: t('common.undo'),
            handler: () => {
                const changed = findEntry(listId, entryId);

                if (changed?.status === 'notGot') {
                    markOpen(changed);
                }
            }
        }
    });
}

function reopenEntry() {
    if (entry.value) {
        markOpen(entry.value);
    }
}

function onTransferred({ result, targetName }) {
    if (result && result.count > 0) {
        showToast(t('transfer.transferredOne', { name: productName.value, list: targetName }), { icon: 'info' });
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
                        <EntryContextCard
                            :entry="entry"
                            :list-name="listName"
                            :transferred-to="transferredTo"
                            :readonly="readonly"
                            @edit="sheetOpen = true"
                            @not-got="notGot"
                            @reopen="reopenEntry"
                            @transfer="transferOpen = true"
                        />
                    </template>
                </ProductDetail>

                <template v-else>
                    <EmptyState :icon="PackageX" :title="productName" :text="t('entry.unknownProductText')" />
                    <EntryContextCard
                        :entry="entry"
                        :list-name="listName"
                        :transferred-to="transferredTo"
                        :readonly="readonly"
                        @edit="sheetOpen = true"
                        @not-got="notGot"
                        @reopen="reopenEntry"
                        @transfer="transferOpen = true"
                    />
                </template>

                <AppButton v-if="!readonly" variant="danger" block class="entry-remove" @click="remove">
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

                <TransferSheet
                    v-if="view.list"
                    v-model:open="transferOpen"
                    :list="view.list"
                    :entries="[entry]"
                    @finished="onTransferred"
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
