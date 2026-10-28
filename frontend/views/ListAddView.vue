<script setup>
/**
 * „Produkte hinzufügen“ (Designpaket 08 und 09, docs/architecture.md,
 * „Adding products“).
 *
 * - Öffnet ohne Fokus im Suchfeld (keine Tastatur), mit zwei Spalten
 *   Kacheln: hier angelegte · häufig gekauft (Vorschläge vom Server) ·
 *   übriger Katalog alphabetisch. Die Reihenfolge wird beim ersten Laden
 *   festgehalten und bleibt, solange die Ansicht offen ist. Erst 48 Kacheln,
 *   beim Scrollen je 24 mehr.
 * - Erster Tipp auf Plus: Menge 1; danach „− Menge +“, das Plus an derselben
 *   Stelle; Minus bei 1 nimmt das Produkt wieder von der Liste. Kein Produkt
 *   doppelt: Plus erhöht den offenen Eintrag. Erledigte und nicht bekommene
 *   Einträge zählen nicht („noch mal Milch“ legt einen zweiten, offenen an),
 *   die Kachel zeigt „erledigt“ bzw. „nicht bekommen“.
 * - „Nur nicht ausgewählte“ blendet aus, was beim Einschalten ausgewählt
 *   war; was danach dazukommt, bleibt stehen (Kacheln springen nicht).
 * - Suche erst beim Antippen; Treffer als Kacheln, am Ende immer
 *   „„Begriff“ neu anlegen“ (Produkt und Eintrag als Operationen).
 * - Bild oder Name öffnen das Produkt: auf der Liste mit Menge und Notiz,
 *   sonst die Produktseite. „Fertig“ führt zur Liste.
 */
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Check, Plus, TriangleAlert } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AddTile from '@/components/list/AddTile.vue';
import AddTileSkeleton from '@/components/list/AddTileSkeleton.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SearchField from '@/components/ui/SearchField.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import { createProductOnList, isVisible, listView, loadList, setProductQuantity } from '@/composables/useLists.js';
import { loadProducts, loadSuggestions, useProducts } from '@/composables/useProducts.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { showToast } from '@/composables/useToast.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { countOrder, currentOrder, FIRST_PAGE, freezeOrder, nextLimit, sliceOrder } from '@/utils/pickerOrder.js';
import { t } from '@/i18n/index.js';

const SEARCH_LIMIT = 60;
const SKELETON_TILES = 8;
const NAME_MAX = 120;

const route = useRoute();
const ionRouter = useIonRouter();

// Ionic hält Seiten im Verlauf; die ID gehört zu dieser Seite, nicht zur aktuellen Route
const listId = String(route.params.id ?? '');
const backTo = '/lists/' + encodeURIComponent(listId);

const view = listView(listId);
const { catalog, status: productStatus, search, findProduct } = useProducts();

const query = ref('');
const searchField = ref(null);
const onlyUnselected = ref(false);
const announcement = ref('');
const suggestionsLoaded = ref(false);
const limit = ref(FIRST_PAGE);

/** Beim ersten vollständigen Laden festgehalten, danach unverändert. */
const frozen = shallowRef(null);
/** Hier angelegte Produkte, neueste zuerst. */
const createdHere = ref([]);
/** Beim Einschalten des Filters ausgewählte Produkte. */
const filteredOut = shallowRef(new Set());

let suggestions = null;

const listReady = computed(() => view.value.entries !== null && view.value.status === 'ready');
const catalogReady = computed(() => productStatus.value === 'ready');
const loading = computed(() => !frozen.value && view.value.status !== 'missing' && view.value.status !== 'error' && productStatus.value !== 'error');
const failed = computed(() => !frozen.value && (view.value.status === 'error' || productStatus.value === 'error'));

const searchText = computed(() => query.value.trim());
const searching = computed(() => searchText.value !== '');

/** Offener Eintrag je Produkt, und Produkte mit erledigtem bzw. nicht bekommenem Eintrag. */
const onList = computed(() => {
    const active = new Map();
    const done = new Set();
    const notGot = new Set();

    for (const entry of view.value.entries?.values() ?? []) {
        if (!isVisible(entry)) {
            continue;
        }

        if (entry.status === 'done') {
            done.add(entry.productId);
        } else if (entry.status === 'notGot') {
            notGot.add(entry.productId);
        } else if (!active.has(entry.productId)) {
            active.set(entry.productId, entry);
        }
    }

    return { active, done, notGot };
});

const selectedCount = computed(() => onList.value.active.size);

const subtitle = computed(() => {
    const listName = view.value.list?.name;

    if (!listName) {
        return ' ';
    }

    return t('addProducts.subtitle', {
        list: listName,
        selected: t('addProducts.selected', { count: selectedCount.value })
    });
});

function quantityOf(productId) {
    return onList.value.active.get(productId)?.quantity.amount ?? 0;
}

function shownWithFilter(ids) {
    if (!onlyUnselected.value) {
        return ids;
    }

    return ids.filter((id) => !filteredOut.value.has(id));
}

/** Abschnitte der Auswahl ohne Suche, gefiltert, noch nicht begrenzt. */
const order = computed(() => {
    if (!frozen.value) {
        return { created: [], suggested: [], rest: [] };
    }

    const current = currentOrder(frozen.value, catalog.value, createdHere.value);

    return {
        created: shownWithFilter(current.created),
        suggested: shownWithFilter(current.suggested),
        rest: shownWithFilter(current.rest)
    };
});

const total = computed(() => countOrder(order.value));
const visible = computed(() => sliceOrder(order.value, limit.value));

const sections = computed(() => {
    const result = [];
    const hasSuggestions = frozen.value?.suggested.length > 0;

    if (visible.value.created.length > 0) {
        result.push({ key: 'created', label: t('addProducts.sectionCreated'), ids: visible.value.created });
    }

    if (visible.value.suggested.length > 0) {
        result.push({ key: 'suggested', label: t('addProducts.sectionFrequent'), ids: visible.value.suggested });
    }

    if (visible.value.rest.length > 0) {
        result.push({
            key: 'rest',
            label: hasSuggestions ? t('addProducts.sectionAllFrom') : t('addProducts.sectionAll'),
            ids: visible.value.rest
        });
    }

    return result;
});

const results = computed(() => {
    if (!searching.value) {
        return [];
    }

    const hits = search(searchText.value, { limit: SEARCH_LIMIT }).map((product) => product.id);

    return shownWithFilter(hits);
});

const canCreate = computed(() => searchText.value.length > 0 && searchText.value.length <= NAME_MAX);

const info = computed(() => (searching.value ? t('addProducts.results', { count: results.value.length }) : t('addProducts.orderHint')));

// Reihenfolge festhalten, sobald Liste, Katalog und Vorschläge da sind
watch(
    [listReady, catalogReady, suggestionsLoaded],
    ([listOk, catalogOk, suggestionsOk]) => {
        if (!frozen.value && listOk && catalogOk && suggestionsOk) {
            frozen.value = freezeOrder(catalog.value, suggestions);
        }
    },
    { immediate: true }
);

// Vorschläge einmal je geöffneter Ansicht (die Reihenfolge bleibt danach stehen)
loadSuggestions().then((loaded) => {
    suggestions = loaded;
    suggestionsLoaded.value = true;
});

onIonViewWillEnter(() => {
    if (view.value.status !== 'ready') {
        loadList(listId).catch((error) => console.error(error));
    }

    loadProducts().catch((error) => console.error(error));
});

function retry() {
    loadList(listId).catch((error) => console.error(error));
    loadProducts().catch((error) => console.error(error));
}

function productName(productId) {
    return findProduct(productId)?.name ?? '';
}

function metaOf(product) {
    if (onList.value.active.has(product.id)) {
        return product.packageSize ?? '';
    }

    if (onList.value.notGot.has(product.id)) {
        return t('addProducts.notGot');
    }

    if (onList.value.done.has(product.id)) {
        return t('addProducts.done');
    }

    return product.packageSize ?? '';
}

function setQuantity(productId, amount) {
    const before = quantityOf(productId);

    setProductQuantity(listId, productId, amount);

    if (before === 0 && amount > 0) {
        announcement.value = t('addProducts.added', { name: productName(productId) });
    } else if (before > 0 && amount <= 0) {
        announcement.value = t('addProducts.removed', { name: productName(productId) });
    }
}

function openProduct(productId) {
    const entry = onList.value.active.get(productId);

    if (entry) {
        ionRouter.push(backTo + '/entries/' + encodeURIComponent(entry.id));
        return;
    }

    ionRouter.push('/products/' + encodeURIComponent(productId));
}

watch(onlyUnselected, (on) => {
    filteredOut.value = on ? new Set(onList.value.active.keys()) : new Set();
});

function create() {
    if (!canCreate.value) {
        return;
    }

    const name = searchText.value;
    const productId = createProductOnList(listId, name);

    createdHere.value = [productId, ...createdHere.value];
    query.value = '';
    announcement.value = t('addProducts.created', { name });
    showToast(t('addProducts.created', { name }), { icon: 'done' });
    searchField.value?.focus();
}

function done() {
    navigateBack(ionRouter, backTo);
}

// Nachladen beim Scrollen: ein unsichtbarer Streifen über dem Ende der Kacheln
const sentinel = ref(null);
let observer = null;

function growWhenNearEnd(entries) {
    if (!entries.some((entry) => entry.isIntersecting) || limit.value >= total.value) {
        return;
    }

    limit.value = nextLimit(limit.value);

    // noch immer am Ende (große Bildschirme): erneut prüfen lassen
    nextTick(() => {
        if (observer && sentinel.value) {
            observer.unobserve(sentinel.value);
            observer.observe(sentinel.value);
        }
    });
}

watch(sentinel, (element, previous) => {
    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer ??= new IntersectionObserver(growWhenNearEnd);

    if (previous) {
        observer.unobserve(previous);
    }

    if (element) {
        observer.observe(element);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});
</script>

<template>
    <AppPage class="add-page" :title="t('addProducts.shortTitle')" :subtitle="subtitle" back :back-to="backTo" width="list" :user-menu="false">
        <template #actions>
            <button type="button" class="picker__done" @click="done">{{ t('common.done') }}</button>
        </template>

        <div class="picker">
            <SearchField ref="searchField" v-model="query" :label="t('addProducts.searchLabel')" :placeholder="t('addProducts.searchPlaceholder')" />

            <div class="picker__bar">
                <label class="picker__filter" :class="{ 'picker__filter--on': onlyUnselected }">
                    <input v-model="onlyUnselected" type="checkbox" class="picker__filter-input">
                    <span class="picker__filter-box" aria-hidden="true">
                        <Check class="picker__filter-check" />
                    </span>
                    {{ t('addProducts.onlyUnselected') }}
                </label>
                <span class="picker__info">{{ info }}</span>
            </div>

            <EmptyState v-if="failed" :icon="TriangleAlert" :title="t('list.loadError')">
                <AppButton variant="secondary" @click="retry">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <div v-else-if="loading" aria-busy="true">
                <!-- Platz des Abschnittslabels, damit die Kacheln nach dem Laden nicht springen -->
                <span class="picker__label-skeleton" aria-hidden="true"><span></span></span>
                <ul class="picker__grid" :aria-label="t('addProducts.tiles')">
                    <li v-for="index in SKELETON_TILES" :key="index">
                        <AddTileSkeleton />
                    </li>
                </ul>
            </div>

            <template v-else-if="searching">
                <ul v-if="results.length > 0" class="picker__grid picker__grid--first" :aria-label="t('addProducts.results', { count: results.length })">
                    <li v-for="productId in results" :key="productId">
                        <AddTile
                            v-if="findProduct(productId)"
                            :model-value="quantityOf(productId)"
                            :name="findProduct(productId).name"
                            :image-url="findProduct(productId).imageUrl"
                            :icon="iconOf(findProduct(productId))"
                            :category="categoryOf(findProduct(productId))"
                            :unit="onList.active.get(productId)?.quantity.unit ?? null"
                            :meta="metaOf(findProduct(productId))"
                            :open-label="t('addProducts.openDetail', { name: findProduct(productId).name })"
                            @update:model-value="(amount) => setQuantity(productId, amount)"
                            @open="openProduct(productId)"
                        />
                    </li>
                </ul>
                <p v-else class="picker__note">{{ t('addProducts.noResults', { query: searchText }) }}</p>

                <button type="button" class="picker__create" :disabled="!canCreate" @click="create">
                    <span class="picker__create-icon" aria-hidden="true"><Plus /></span>
                    <span class="picker__create-text">
                        <span class="picker__create-title">{{ t('addProducts.create', { name: searchText }) }}</span>
                        <span class="picker__create-hint">
                            {{ canCreate ? t('addProducts.createHint') : t('addProducts.nameTooLong', { max: NAME_MAX }) }}
                        </span>
                    </span>
                </button>
            </template>

            <template v-else>
                <p v-if="total === 0" class="picker__note">
                    {{ onlyUnselected && catalog.length > 0 ? t('addProducts.allSelected') : t('addProducts.emptyCatalog') }}
                </p>

                <div v-else class="picker__sections">
                    <section v-for="(section, index) in sections" :key="section.key" class="picker__section">
                        <SectionLabel :label="section.label" class="picker__label" :class="{ 'picker__label--first': index === 0 }" />
                        <ul class="picker__grid">
                            <li v-for="productId in section.ids" :key="productId">
                                <AddTile
                                    :model-value="quantityOf(productId)"
                                    :name="findProduct(productId).name"
                                    :image-url="findProduct(productId).imageUrl"
                                    :icon="iconOf(findProduct(productId))"
                                    :category="categoryOf(findProduct(productId))"
                                    :unit="onList.active.get(productId)?.quantity.unit ?? null"
                                    :meta="metaOf(findProduct(productId))"
                                    :open-label="t('addProducts.openDetail', { name: findProduct(productId).name })"
                                    @update:model-value="(amount) => setQuantity(productId, amount)"
                                    @open="openProduct(productId)"
                                />
                            </li>
                        </ul>
                    </section>

                    <div v-if="limit < total" ref="sentinel" class="picker__sentinel" aria-hidden="true"></div>
                </div>

                <p v-if="limit < total" class="picker__more" role="status">{{ t('addProducts.shown', { shown: Math.min(limit, total), total }) }}</p>
            </template>

            <p class="visually-hidden" aria-live="polite">{{ announcement }}</p>
        </div>
    </AppPage>
</template>

<style scoped>
/* Unterzeile immer einzeilig: die Zahl der Ausgewählten wächst beim Tippen,
   ein Umbruch schöbe sonst alle Kacheln nach unten */
.add-page :deep(.app-header__subtitle) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.picker {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-2) var(--page-gutter) 0;
}

.picker__done {
    min-height: var(--tap-size);
    padding: 0 var(--space-5);
    border-radius: var(--radius-pill);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    transition: transform var(--duration-fast) var(--ease-out);
}

.picker__done:active {
    transform: scale(var(--press-scale));
}

.picker__done:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.picker__bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    min-height: var(--tap-size);
}

/* Filter als Pille mit echtem Kontrollkästchen (Zustand für Screenreader) */
.picker__filter {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--tap-size);
    padding: 0 var(--space-4) 0 var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-pill);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font-size: var(--font-size-title);
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
}

.picker__filter--on {
    border-color: var(--color-accent);
    background: var(--color-accent-tint);
}

.picker__filter-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    border-radius: var(--radius-pill);
    opacity: 0;
    cursor: pointer;
}

.picker__filter:has(.picker__filter-input:focus-visible) {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.picker__filter-box {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid var(--color-text-2);
    border-radius: var(--radius-xs);
    color: transparent;
}

.picker__filter--on .picker__filter-box {
    border-color: var(--color-accent);
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.picker__filter-check {
    width: 0.875rem;
    height: 0.875rem;
    stroke-width: 3;
}

.picker__info {
    flex: none;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.picker__sections {
    position: relative;
}

.picker__label {
    padding-left: var(--space-1);
}

.picker__label--first {
    padding-top: var(--space-1);
}

/* so hoch wie das erste Abschnittslabel: 4 + 16 + 8 px */
.picker__label-skeleton {
    display: block;
    padding: var(--space-1) var(--space-2) var(--space-2) var(--space-1);
}

.picker__label-skeleton span {
    display: block;
    width: 7rem;
    height: var(--line-height-label);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.picker__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
}

.picker__grid--first {
    padding-top: var(--space-1);
}

/* ab Tablet drei Spalten: die Listenbreite (45 rem) gibt Kacheln von gut 200 px */
@media (min-width: 48em) {
    .picker__grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

/* Streifen über dem Ende der Kacheln: berührt er den sichtbaren Bereich, kommen 24 mehr */
.picker__sentinel {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 1px;
    height: 150vh;
    max-height: 100%;
    visibility: hidden;
    pointer-events: none;
}

.picker__more,
.picker__note {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.picker__more {
    padding: var(--space-2) 0;
    text-align: center;
}

.picker__create {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--row-min-height);
    padding: var(--space-3);
    border: 1.5px solid var(--color-border-strong);
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--color-text-1);
    text-align: left;
    transition: transform var(--duration-fast) var(--ease-out);
}

.picker__create:not(:disabled):active {
    transform: scale(var(--press-scale));
}

.picker__create:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.picker__create:disabled {
    opacity: 0.6;
}

.picker__create-icon {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-surface-2);
    color: var(--color-accent-text);
}

.picker__create-icon :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}

.picker__create-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.picker__create-title {
    overflow: hidden;
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    overflow-wrap: anywhere;
}

.picker__create-hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
