<script setup>
/**
 * „Produkte hinzufügen“ (Designpaket 08 und 09, docs/architecture.md,
 * „Adding products“).
 *
 * - Öffnet ohne Fokus im Suchfeld (keine Tastatur), mit Kacheln (Spaltenzahl:
 *   Token `--tile-columns`): hier angelegte · häufig gekauft (Vorschläge vom
 *   Server) · übriger Katalog alphabetisch. Die Reihenfolge wird beim ersten
 *   Laden festgehalten und bleibt, solange die Ansicht offen ist. Erst 48
 *   Kacheln, beim Scrollen je 24 mehr.
 * - Ein Tipp auf die Kachel: Menge 1, jeder weitere +1, ein Badge zeigt die
 *   Menge. Halten öffnet das Mengen-Control mit „Details“ und „Entfernen“ (auf
 *   der Liste mit Menge und Notiz, sonst die Produktseite). Kein Produkt
 *   doppelt: Tippen erhöht den offenen Eintrag. Erledigte und nicht bekommene
 *   Einträge zählen nicht („noch mal Milch“ legt einen zweiten, offenen an),
 *   die Kachel bleibt mit „erledigt“ bzw. „nicht bekommen“ sichtbar.
 * - Was beim Öffnen schon offen auf der Liste steht, ist ausgeblendet; was
 *   danach angetippt wird, bleibt stehen, bis man die Ansicht verlässt
 *   (Kacheln springen nicht). Die Suche findet auch Ausgeblendetes, sonst
 *   entstünden über „neu anlegen“ Dubletten.
 * - Kopf und Suchfeld kleben oben: Eingabe und Rückweg bleiben beim Scrollen
 *   und bei offener Tastatur sichtbar.
 * - Suche erst beim Antippen; Treffer als Kacheln, am Ende immer
 *   „„Begriff“ neu anlegen“ (Produkt und Eintrag als Operationen). Die Suche
 *   beginnt oben, auch wenn weiter unten gescrollt war.
 * - „Fertig“ führt zur Liste.
 *
 * Dieselbe Auswahl füllt beim Bearbeiten einer Vorlage deren Entwurf
 * (Route `/templates/:id/add`, Schritt 12): Kacheln und Mengen-Control ändern
 * nur den Entwurf, gespeichert wird erst in der Vorlage. Dort gibt es kein
 * „erledigt“ und kein „nicht bekommen“; „Details“ öffnet das Produkt.
 */
import { logError } from '@/api/index.js';
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Plus, TriangleAlert } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AddTile from '@/components/list/AddTile.vue';
import AddTileSkeleton from '@/components/list/AddTileSkeleton.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SearchField from '@/components/ui/SearchField.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import { createProductOnList, createQuickProduct, isVisible, listView, loadList, setProductQuantity } from '@/composables/useLists.js';
import { draftFor, ensureTemplateLoaded, setDraftQuantity, startEditing, templateDetail } from '@/composables/useTemplates.js';
import { loadProducts, loadSuggestions, useProducts } from '@/composables/useProducts.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { showToast } from '@/composables/useToast.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatQuantity } from '@/utils/quantity.js';
import { countOrder, currentOrder, FIRST_PAGE, freezeOrder, nextLimit, sliceOrder } from '@/utils/pickerOrder.js';
import { t } from '@/i18n/index.js';

const SEARCH_LIMIT = 60;
const SKELETON_TILES = 8;
const NAME_MAX = 120;

const route = useRoute();
const ionRouter = useIonRouter();

// Ionic hält Seiten im Verlauf; die ID gehört zu dieser Seite, nicht zur aktuellen Route
const isTemplate = route.name === 'template-add';
const listId = String(route.params.id ?? '');
const backTo = (isTemplate ? '/templates/' : '/lists/') + encodeURIComponent(listId);

const listState = isTemplate ? null : listView(listId);

/**
 * Ziel der Auswahl im Format des Listenstands: `{ status, list: { name },
 * entries }`. Bei einer Vorlage der Entwurf (ein offener Eintrag je Produkt).
 */
const view = computed(() => {
    if (!isTemplate) {
        return listState.value;
    }

    const detail = templateDetail(listId);
    const draft = draftFor(listId);

    if (detail.status === 'missing' || detail.status === 'error') {
        return { status: detail.status, list: null, entries: null };
    }

    if (!draft) {
        return { status: 'loading', list: null, entries: null };
    }

    const entries = new Map(draft.items.map((item) => [item.productId, {
        id: item.productId,
        productId: item.productId,
        quantity: item.quantity,
        status: 'open'
    }]));

    return { status: 'ready', list: { name: draft.name }, entries };
});
const { catalog, status: productStatus, search, findProduct } = useProducts();

const page = ref(null);
const query = ref('');
const searchField = ref(null);
const announcement = ref('');
const suggestionsLoaded = ref(false);
const limit = ref(FIRST_PAGE);

/** Beim ersten vollständigen Laden festgehalten, danach unverändert. */
const frozen = shallowRef(null);
/** Hier angelegte Produkte, neueste zuerst. */
const createdHere = ref([]);
/** Beim Öffnen schon offene Produkte; was hier dazukommt, gehört nicht dazu und bleibt stehen. */
const openOnArrival = shallowRef(new Set());

let suggestions = null;

const listReady = computed(() => view.value.entries !== null && view.value.status === 'ready');
const catalogReady = computed(() => productStatus.value === 'ready');
const loading = computed(() => !frozen.value && view.value.status !== 'missing' && view.value.status !== 'error' && productStatus.value !== 'error');
const failed = computed(() => !frozen.value && (view.value.status === 'error' || productStatus.value === 'error'));

const searchText = computed(() => query.value.trim());
const searching = computed(() => searchText.value !== '');

// das Feld klebt oben: tippen geht auch von weiter unten, Treffer stehen aber oben
watch(searchText, () => {
    page.value?.scrollToTop();
});

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

/** Ausgeblendet: beim Öffnen offen und noch immer offen (sonst z. B. von jemandem erledigt). */
const hidden = computed(() => new Set([...openOnArrival.value].filter((id) => onList.value.active.has(id))));

function withoutHidden(ids) {
    return ids.filter((id) => !hidden.value.has(id));
}

/** Abschnitte der Auswahl ohne Suche und ohne Ausgeblendetes, noch nicht begrenzt. */
const order = computed(() => {
    if (!frozen.value) {
        return { created: [], suggested: [], rest: [] };
    }

    const current = currentOrder(frozen.value, catalog.value, createdHere.value);

    return {
        created: withoutHidden(current.created),
        suggested: withoutHidden(current.suggested),
        rest: withoutHidden(current.rest)
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

    return search(searchText.value, { limit: SEARCH_LIMIT }).map((product) => product.id);
});

const canCreate = computed(() => searchText.value.length > 0 && searchText.value.length <= NAME_MAX);

const info = computed(() => (searching.value ? t('addProducts.results', { count: results.value.length }) : t('addProducts.orderHint')));
const hiddenNote = computed(() => (!searching.value && hidden.value.size > 0 ? t('addProducts.hiddenSelected', { count: hidden.value.size }) : ''));

// Reihenfolge festhalten, sobald Liste, Katalog und Vorschläge da sind
watch(
    [listReady, catalogReady, suggestionsLoaded],
    ([listOk, catalogOk, suggestionsOk]) => {
        if (!frozen.value && listOk && catalogOk && suggestionsOk) {
            frozen.value = freezeOrder(catalog.value, suggestions);
            openOnArrival.value = new Set(onList.value.active.keys());
        }
    },
    { immediate: true }
);

// Vorschläge einmal je geöffneter Ansicht (die Reihenfolge bleibt danach stehen)
loadSuggestions().then((loaded) => {
    suggestions = loaded;
    suggestionsLoaded.value = true;
});

/** Ziel laden; eine Vorlage direkt aufgerufen (z. B. neu geladen): Entwurf beginnen. */
function loadTarget() {
    if (!isTemplate) {
        return loadList(listId);
    }

    return ensureTemplateLoaded(listId).then(() => {
        startEditing(listId);
    });
}

onIonViewWillEnter(() => {
    if (view.value.status !== 'ready') {
        loadTarget().catch(logError);
    }

    loadProducts().catch(logError);
});

function retry() {
    loadTarget().catch(logError);
    loadProducts().catch(logError);
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
    const unit = onList.value.active.get(productId)?.quantity.unit ?? null;

    if (isTemplate) {
        setDraftQuantity(listId, productId, amount);
    } else {
        setProductQuantity(listId, productId, amount);
    }

    if (before === 0 && amount > 0) {
        announcement.value = t('addProducts.added', { name: productName(productId) });
    } else if (before > 0 && amount <= 0) {
        announcement.value = t('addProducts.removed', { name: productName(productId) });
    } else if (amount > 0) {
        announcement.value = t('addProducts.quantity', { name: productName(productId), quantity: formatQuantity(amount, unit) });
    }
}

function openProduct(productId) {
    const entry = isTemplate ? null : onList.value.active.get(productId);

    if (entry) {
        ionRouter.push(backTo + '/entries/' + encodeURIComponent(entry.id));
        return;
    }

    ionRouter.push('/products/' + encodeURIComponent(productId));
}

function create() {
    if (!canCreate.value) {
        return;
    }

    const name = searchText.value;
    let productId;

    if (isTemplate) {
        productId = createQuickProduct(name);
        setDraftQuantity(listId, productId, 1);
    } else {
        productId = createProductOnList(listId, name);
    }

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
    <AppPage
        ref="page"
        class="add-page"
        :title="t('addProducts.shortTitle')"
        :subtitle="subtitle"
        back
        :back-to="backTo"
        width="list"
        :user-menu="false"
        sticky-head
    >
        <template #actions>
            <button type="button" class="picker__done" @click="done">{{ t('common.done') }}</button>
        </template>

        <template #head>
            <div class="picker__search">
                <SearchField ref="searchField" v-model="query" :label="t('addProducts.searchLabel')" :placeholder="t('addProducts.searchPlaceholder')" />
            </div>
        </template>

        <div class="picker">
            <div class="picker__bar">
                <span class="picker__info">{{ hiddenNote }}</span>
                <span class="picker__info picker__info--end">{{ info }}</span>
            </div>

            <EmptyState v-if="failed" :icon="TriangleAlert" :title="isTemplate ? t('template.loadError') : t('list.loadError')">
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
                            {{ canCreate
                                ? t(isTemplate ? 'addProducts.createHintTemplate' : 'addProducts.createHint')
                                : t('addProducts.nameTooLong', { max: NAME_MAX }) }}
                        </span>
                    </span>
                </button>
            </template>

            <template v-else>
                <p v-if="total === 0" class="picker__note">
                    {{ hidden.size > 0 ? t('addProducts.allSelected') : t('addProducts.emptyCatalog') }}
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

.picker__search {
    padding: var(--space-2) var(--page-gutter) var(--space-3);
}

.picker {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-3) var(--page-gutter) 0;
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
    justify-content: space-between;
    gap: var(--space-3);
    min-height: var(--line-height-note);
}

.picker__info {
    min-width: 0;
    overflow: hidden;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.picker__info--end {
    flex: none;
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
    grid-template-columns: repeat(var(--tile-columns), minmax(0, 1fr));
    gap: var(--space-2);
}

.picker__grid--first {
    padding-top: var(--space-1);
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
