<script setup>
/**
 * Einkaufsliste (Designpaket 03–06): Fortschritt, offene Einträge in
 * Listenreihenfolge, darunter „Nicht bekommen“ und der einklappbare Bereich
 * „Erledigt“.
 *
 * - Sortieren nur am Ziehgriff (`useDragSort`, mit der Tastatur Pfeil
 *   hoch/runter am Griff); neue Einträge stehen am Ende.
 * - Abhaken über die eigene Fläche rechts: Die Zeile schließt sich mit einer
 *   kurzen Höhen-Animation (nichts springt), dann wandert sie nach
 *   „Erledigt“; ein Toast bietet „Rückgängig“. Zurückgeholt (Toast oder
 *   Haken in „Erledigt“) steht sie wieder an ihrer bisherigen Stelle, weil
 *   der Sortierschlüssel bleibt.
 * - Tippen auf Bild oder Name öffnet das Produkt im Listenkontext.
 * - „…“ öffnet „Liste bearbeiten“ (Name, Datum, Löschen).
 * - Nach links wischen: „Nicht bekommen“ (eigener Abschnitt, mit
 *   „Rückgängig“ im Toast); dort wischen: „Wieder offen“. Der Pfeil in der
 *   Zeile oder „Alle übernehmen …“ übernimmt in eine andere Liste
 *   (`TransferSheet`), im Ursprung steht dann „übernommen nach …“.
 * - „Einkauf abschließen“ unten: Angebot, Offenes und Nicht bekommenes zu
 *   übernehmen; danach liegt die Liste im Archiv.
 * - Abgeschlossene Liste (Archiv): nur lesen, oben „Wieder öffnen“ und
 *   „Neue Liste daraus“ (früheren Einkauf übernehmen).
 *
 * Alle Änderungen laufen als Operationen über `useLists`.
 */
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { Archive, ChevronDown, CloudOff, Ellipsis, History, ListPlus, Plus, RotateCcw, SearchX, TriangleAlert } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import ListRow from '@/components/list/ListRow.vue';
import SkeletonRow from '@/components/list/SkeletonRow.vue';
import ListFormSheet from '@/components/lists/ListFormSheet.vue';
import TransferSheet from '@/components/lists/TransferSheet.vue';
import {
    checkEntry,
    completeList,
    copyListTo,
    createListWith,
    deleteList,
    findEntry,
    isEntryUnsynced,
    listView,
    loadList,
    loadLists,
    markNotGot,
    markOpen,
    moveListToHousehold,
    moveOpenEntry,
    reopenList,
    uncheckEntry,
    updateList,
    useLists
} from '@/composables/useLists.js';
import { useDragSort } from '@/composables/useDragSort.js';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { showToast } from '@/composables/useToast.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatDay, formatShopDate } from '@/utils/shopDate.js';
import { formatQuantity } from '@/utils/quantity.js';
import { mergeTakeOver } from '@/utils/takeOver.js';
import { t } from '@/i18n/index.js';

const SKELETON_WIDTHS = [0.55, 0.35, 0.3, 0.6, 0.45, 0.3, 0.4, 0.65, 0.5, 0.45];
const ROW_ANIMATION_MS = 200;
const DONE_OPEN_KEY = 'pk.listDoneOpen';
// hält Titel und Unterzeile in Endhöhe, solange der Name noch lädt
const NBSP = '\u00a0';

const route = useRoute();
const ionRouter = useIonRouter();

// Ionic hält Seiten im Verlauf; die ID gehört zu dieser Seite
const listId = String(route.params.id ?? '');
const view = listView(listId);
const { pendingOps, syncTrouble, activeLists, archivedLists, overview } = useLists();
const { findProduct, status: productStatus } = useProducts();

const openListRef = useTemplateRef('openList');
const doneListRef = useTemplateRef('doneList');

const formOpen = ref(false);
const newFromOpen = ref(false);
const transferOpen = ref(false);
const transferMode = ref('transfer');
const transferEntries = ref([]);
const announcement = ref('');
const doneOpen = ref(readDoneOpen());
const busy = new Set();

const list = computed(() => view.value.list);
const counts = computed(() => view.value.counts);
const isCompleted = computed(() => list.value?.status === 'completed');
const completedDay = computed(() => formatDay(list.value?.completedAt));

const loading = computed(() => {
    if (view.value.status === 'missing' || view.value.status === 'error') {
        return false;
    }

    const entriesReady = view.value.entries !== null && view.value.status !== 'idle';
    const productsReady = productStatus.value === 'ready' || productStatus.value === 'error';

    return !entriesReady || !productsReady;
});

const subtitle = computed(() => {
    if (!list.value) {
        return '';
    }

    const parts = [list.value.householdId ? t('lists.originHousehold') : t('lists.originPersonal')];
    const date = formatShopDate(list.value.shopDate);

    if (date) {
        parts.push(date);
    }

    return parts.join(' · ');
});

const offlineCount = computed(() => (syncTrouble.value ? pendingOps.value.filter((op) => op.listId === listId).length : 0));

const percent = computed(() => (counts.value.total > 0 ? Math.round((counts.value.done / counts.value.total) * 100) : 0));

/** Name einer Zielliste für „übernommen nach …“; '' wenn unbekannt, null ohne Übernahme. */
function transferName(entry) {
    if (!entry.transferredTo) {
        return null;
    }

    const lists = [...activeLists.value, ...archivedLists.value, ...overview.lists];

    return lists.find((item) => item.id === entry.transferredTo)?.name ?? '';
}

function rowOf(entry) {
    const product = findProduct(entry.productId);

    return {
        entry,
        name: product?.name ?? t('entry.unknownProduct'),
        imageUrl: product?.imageUrl ?? null,
        icon: product ? iconOf(product) : undefined,
        category: categoryOf(product),
        transferredTo: entry.status === 'notGot' ? transferName(entry) : null
    };
}

const swipeNotGot = { label: t('list.swipeNotGot'), variant: 'notgot' };
const swipeReopen = { label: t('list.swipeReopen'), variant: 'accent' };

const openRows = computed(() => view.value.open.map(rowOf));
const notGotRows = computed(() => view.value.notGot.map(rowOf));
const doneRows = computed(() => view.value.done.map(rowOf));

function readDoneOpen() {
    try {
        return localStorage.getItem(DONE_OPEN_KEY) !== '0';
    } catch {
        return true;
    }
}

function toggleDone() {
    doneOpen.value = !doneOpen.value;

    try {
        localStorage.setItem(DONE_OPEN_KEY, doneOpen.value ? '1' : '0');
    } catch {
        // nur Bequemlichkeit
    }
}

function reload() {
    loadList(listId).catch((error) => console.error(error));
    loadProducts().catch((error) => console.error(error));
    // Namen der Ziellisten für „nicht bekommen · nach …“ (ETag, meist 304)
    loadLists().catch((error) => console.error(error));
}

onIonViewWillEnter(reload);

function reducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

function rowElement(container, entryId) {
    const element = container?.$el ?? container;

    return element?.querySelector(`:scope > [data-entry-id="${CSS.escape(entryId)}"]`) ?? null;
}

/** Zeile schließt sich (Höhe → 0); die folgenden rücken gleichmäßig nach. */
async function collapse(row) {
    if (!row || reducedMotion()) {
        return null;
    }

    const height = row.offsetHeight;

    row.style.overflow = 'hidden';
    row.style.pointerEvents = 'none';

    const animation = row.animate(
        [
            { height: height + 'px', minHeight: '0px', opacity: 1 },
            { height: '0px', minHeight: '0px', borderTopWidth: '0px', opacity: 0 }
        ],
        { duration: ROW_ANIMATION_MS, easing: 'cubic-bezier(0.2, 0, 0, 1)', fill: 'forwards' }
    );

    // im Hintergrund laufen Animationen nicht: dann nicht ewig warten
    await Promise.race([
        animation.finished.catch(() => {}),
        new Promise((resolve) => window.setTimeout(resolve, ROW_ANIMATION_MS + 100))
    ]);

    return animation;
}

/** Nach dem Rendern: neue Zeile öffnet sich (0 → Höhe), nichts springt. */
async function expand(entryId) {
    await nextTick();

    if (reducedMotion()) {
        return;
    }

    const row = rowElement(openListRef.value, entryId) ?? (doneOpen.value ? rowElement(doneListRef.value, entryId) : null);

    if (!row) {
        return;
    }

    const height = row.offsetHeight;

    row.style.overflow = 'hidden';
    row.animate(
        [
            { height: '0px', minHeight: '0px', opacity: 0 },
            { height: height + 'px', minHeight: '0px', opacity: 1 }
        ],
        { duration: ROW_ANIMATION_MS, easing: 'cubic-bezier(0.2, 0, 0, 1)' }
    ).finished
        .catch(() => {})
        .finally(() => {
            row.style.overflow = '';
        });
}

/** Animation zurücksetzen, falls die Zeile doch stehen bleibt (z. B. abgelehnt). */
async function release(row, animation) {
    await nextTick();

    if (row && row.isConnected) {
        animation?.cancel();
        row.style.overflow = '';
        row.style.pointerEvents = '';
    }
}

async function check(entry) {
    if (busy.has(entry.id)) {
        return;
    }

    busy.add(entry.id);

    const row = rowElement(openListRef.value, entry.id);
    const { name } = rowOf(entry);

    try {
        const animation = await collapse(row);

        await checkEntry(entry);
        await release(row, animation);
        expand(entry.id);
    } finally {
        busy.delete(entry.id);
    }

    showToast(t('row.checked', { name }), {
        icon: 'done',
        action: { text: t('common.undo'), handler: () => undoCheck(entry.id) }
    });
}

async function reopen(entry, container) {
    const row = rowElement(container, entry.id);
    const animation = await collapse(row);

    await uncheckEntry(entry);
    await release(row, animation);
    expand(entry.id);
}

async function undoCheck(entryId) {
    const entry = findEntry(listId, entryId);

    if (!entry || entry.status !== 'done' || busy.has(entryId)) {
        return;
    }

    busy.add(entryId);

    try {
        await reopen(entry, doneOpen.value ? doneListRef.value : null);
    } finally {
        busy.delete(entryId);
    }
}

async function uncheck(entry) {
    if (busy.has(entry.id)) {
        return;
    }

    busy.add(entry.id);

    try {
        await reopen(entry, doneListRef.value);
    } finally {
        busy.delete(entry.id);
    }
}

function announceMove(entryId) {
    const index = view.value.open.findIndex((entry) => entry.id === entryId);
    const entry = view.value.open[index];

    if (entry) {
        announcement.value = t('list.moved', { name: rowOf(entry).name, position: index + 1, count: view.value.open.length });
    }
}

const { start: startDrag } = useDragSort({
    list: openListRef,
    async onDrop(entryId, from, to) {
        await moveOpenEntry(listId, from, to);
        await nextTick();
        announceMove(entryId);
    }
});

async function moveByKey(entry, index, direction) {
    const to = index + direction;

    if (to < 0 || to >= view.value.open.length) {
        return;
    }

    await moveOpenEntry(listId, index, to);
    await nextTick();

    rowElement(openListRef.value, entry.id)?.querySelector('[data-name="handle"]')?.focus();
    announceMove(entry.id);
}

function openEntry(entry) {
    ionRouter.push('/lists/' + encodeURIComponent(listId) + '/entries/' + encodeURIComponent(entry.id));
}

function addProducts() {
    ionRouter.push('/lists/' + encodeURIComponent(listId) + '/add');
}

/** Wischen „Nicht bekommen“: Zeile schließt sich wie beim Abhaken, Toast mit „Rückgängig“. */
async function notGot(entry) {
    if (busy.has(entry.id)) {
        return;
    }

    busy.add(entry.id);

    const row = rowElement(openListRef.value, entry.id);
    const { name } = rowOf(entry);

    try {
        const animation = await collapse(row);

        await markNotGot(entry);
        await release(row, animation);
    } finally {
        busy.delete(entry.id);
    }

    showToast(t('list.notGotToast', { name }), {
        icon: 'notgot',
        action: {
            text: t('common.undo'),
            handler: () => {
                const current = findEntry(listId, entry.id);

                if (current?.status === 'notGot') {
                    markOpen(current).then(() => expand(entry.id));
                }
            }
        }
    });
}

async function backToOpen(entry) {
    await markOpen(entry);
    expand(entry.id);
}

function openTransfer(entries, mode = 'transfer') {
    transferEntries.value = entries;
    transferMode.value = mode;
    transferOpen.value = true;
}

function startCompletion() {
    openTransfer([...view.value.open, ...view.value.notGot], 'complete');
}

function transferText(result, targetName) {
    if (!result || result.count === 0) {
        return '';
    }

    const first = rowOf(result.plan[0].entry).name;
    const parts = [result.count === 1
        ? t('transfer.transferredOne', { name: first, list: targetName })
        : t('transfer.transferredMany', { count: result.count, list: targetName })];

    if (result.summed.length === 1 && result.count === 1) {
        const { quantity } = mergeTakeOver(result.plan[0].existing, result.plan[0].entry);

        parts.push(t('transfer.summedOne', { quantity: formatQuantity(quantity.amount, quantity.unit) }));
    } else if (result.summed.length > 0) {
        parts.push(t('transfer.summedMany', { count: result.summed.length }));
    }

    return parts.join(' · ');
}

async function onTransferFinished({ result, targetName }) {
    const text = transferText(result, targetName);

    if (transferMode.value !== 'complete') {
        showToast(text, { icon: 'info' });
        return;
    }

    const name = list.value?.name ?? '';
    const moved = result?.count ? t('complete.movedToast', { count: result.count, list: targetName }) : '';

    await completeList(listId);
    showToast([t('lists.completedToast', { name }), moved].filter(Boolean).join(' · '), {
        icon: 'done',
        action: { text: t('list.reopen'), handler: () => reopenList(listId) }
    });
    navigateBack(ionRouter, '/');
}

async function reopenPurchase() {
    await reopenList(listId);
    showToast(t('lists.reopenedToast', { name: list.value?.name ?? '' }), { icon: 'info' });
}

async function onCreateFrom(fields) {
    const newId = await createListWith(fields);

    ionRouter.push('/lists/' + encodeURIComponent(newId));
}

function onSave(fields) {
    const changes = {};

    if (fields.name !== list.value.name) {
        changes.name = fields.name;
    }

    if ((fields.shopDate ?? null) !== (list.value.shopDate ?? null)) {
        changes.shopDate = fields.shopDate;
    }

    if (Object.keys(changes).length > 0) {
        updateList(listId, changes);
    }
}

async function onMove() {
    const name = list.value?.name ?? '';

    try {
        await moveListToHousehold(listId);
        showToast(t('listForm.movedToast', { name }), { icon: 'info' });
    } catch (error) {
        console.error(error);
        showToast(t('listForm.ownershipError'), { icon: 'notgot' });
    }
}

async function onCopy() {
    const name = list.value?.name ?? '';

    try {
        await copyListTo(listId, 'household');
        showToast(t('listForm.copiedToast', { name }), { icon: 'info' });
    } catch (error) {
        console.error(error);
        showToast(t('listForm.ownershipError'), { icon: 'notgot' });
    }
}

async function onDelete() {
    const name = list.value?.name ?? '';

    await deleteList(listId);
    showToast(t('listForm.deletedToast', { name }), { icon: 'info' });
    navigateBack(ionRouter, '/');
}
</script>

<template>
    <AppPage :title="list?.name ?? NBSP" :subtitle="subtitle || NBSP" back back-to="/" width="list" :user-menu="false">
        <template v-if="list && view.status !== 'missing' && !isCompleted" #actions>
            <button type="button" class="list__add" :aria-label="t('list.add')" @click="addProducts">
                <Plus aria-hidden="true" />
            </button>
            <button
                type="button"
                class="list__more"
                :aria-label="t('list.actions')"
                aria-haspopup="dialog"
                @click="formOpen = true"
            >
                <Ellipsis aria-hidden="true" />
            </button>
        </template>

        <div class="list">
            <section v-if="isCompleted && view.status !== 'missing' && view.status !== 'error'" class="list__archived" :aria-label="t('archive.title')">
                <p class="list__archived-title">
                    <Archive class="list__archived-icon" aria-hidden="true" />
                    {{ t('list.completedTitle', { date: completedDay }) }}
                </p>
                <p class="list__archived-text">{{ t('list.completedText') }}</p>
                <p v-if="list.changedAfterCompletion" class="list__archived-changed">{{ t('list.changedAfter') }}</p>
                <div class="list__archived-actions">
                    <AppButton variant="secondary" @click="reopenPurchase">
                        <RotateCcw aria-hidden="true" />
                        {{ t('list.reopen') }}
                    </AppButton>
                    <AppButton @click="newFromOpen = true">
                        <History aria-hidden="true" />
                        {{ t('list.startFrom') }}
                    </AppButton>
                </div>
            </section>

            <EmptyState
                v-if="view.status === 'missing'"
                :icon="SearchX"
                :title="t('lists.notFoundTitle')"
                :text="t('lists.notFoundText')"
            >
                <AppButton variant="secondary" @click="navigateBack(ionRouter, '/')">{{ t('lists.toOverview') }}</AppButton>
            </EmptyState>

            <EmptyState v-else-if="view.status === 'error'" :icon="TriangleAlert" :title="t('list.loadError')">
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <template v-else-if="loading">
                <div class="list__progress list__progress--skeleton" aria-hidden="true">
                    <span class="list__progress-line">
                        <span class="list__skeleton-bar list__skeleton-bar--wide"></span>
                        <span class="list__skeleton-bar"></span>
                    </span>
                    <span class="list__bar"></span>
                </div>
                <ul class="list__rows" aria-busy="true">
                    <SkeletonRow v-for="(width, index) in SKELETON_WIDTHS" :key="index" :width="width" />
                </ul>
            </template>

            <template v-else>


                <p v-if="offlineCount > 0" class="list__offline" role="status">
                    <CloudOff class="list__offline-icon" aria-hidden="true" />
                    <span><strong>{{ t('list.offlineTitle') }}</strong> {{ t('list.offline', { count: offlineCount }) }}</span>
                </p>

                <div v-if="counts.total > 0" class="list__progress">
                    <p class="list__progress-line">
                        <span class="list__progress-done">{{ t('list.progress', { done: counts.done, total: counts.total }) }}</span>
                        <span class="list__progress-open">{{ t('list.openCount', { count: counts.open }) }}</span>
                    </p>
                    <span class="list__bar" aria-hidden="true">
                        <span class="list__fill" :style="{ width: percent + '%' }"></span>
                    </span>
                </div>

                <EmptyState
                    v-if="counts.total === 0"
                    :icon="ListPlus"
                    :title="t('list.emptyTitle')"
                    :text="isCompleted ? '' : t('list.emptyText')"
                >
                    <AppButton v-if="!isCompleted" @click="addProducts">
                        <Plus aria-hidden="true" />
                        {{ t('list.add') }}
                    </AppButton>
                </EmptyState>

                <template v-else-if="isCompleted">
                    <section v-if="openRows.length > 0" class="list__section">
                        <SectionLabel :label="t('list.leftOpen')" :count="openRows.length" />
                        <ul class="list__rows">
                            <ListRow
                                v-for="row in openRows"
                                :key="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="open"
                                readonly
                                @details="openEntry(row.entry)"
                            />
                        </ul>
                    </section>

                    <section v-if="notGotRows.length > 0" class="list__section">
                        <SectionLabel :label="t('list.notGot')" :count="notGotRows.length" />
                        <ul class="list__rows">
                            <ListRow
                                v-for="row in notGotRows"
                                :key="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="notGot"
                                :transferred-to="row.transferredTo"
                                readonly
                                @details="openEntry(row.entry)"
                            />
                        </ul>
                    </section>

                    <section v-if="doneRows.length > 0" class="list__section">
                        <SectionLabel :label="t('list.done')" :count="doneRows.length" />
                        <ul class="list__rows">
                            <ListRow
                                v-for="row in doneRows"
                                :key="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="done"
                                readonly
                                @details="openEntry(row.entry)"
                            />
                        </ul>
                    </section>
                </template>

                <template v-else>
                    <h2 class="visually-hidden">{{ t('list.sectionOpen') }}</h2>
                    <div class="list__sortable">
                        <ul v-if="openRows.length > 0" ref="openList" class="list__rows">
                            <ListRow
                                v-for="(row, index) in openRows"
                                :key="row.entry.id"
                                :data-entry-id="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="open"
                                :unsynced="isEntryUnsynced(row.entry.id)"
                                :swipe="swipeNotGot"
                                @details="openEntry(row.entry)"
                                @swipe-action="notGot(row.entry)"
                                @toggle="check(row.entry)"
                                @handle-pointerdown="startDrag($event, row.entry.id)"
                                @move="moveByKey(row.entry, index, $event)"
                            />
                        </ul>
                        <p v-else class="list__all-done">
                            <strong>{{ t('list.allDone') }}</strong>
                            {{ t('list.allDoneText') }}
                        </p>
                    </div>

                    <button type="button" class="list__add-row" @click="addProducts">
                        <span class="list__add-row-icon">
                            <Plus aria-hidden="true" />
                        </span>
                        {{ t('list.add') }}
                    </button>

                    <section v-if="notGotRows.length > 0" class="list__section">
                        <div class="list__section-bar">
                            <SectionLabel class="list__section-bar-label" :label="t('list.notGot')" :count="notGotRows.length" />
                            <button
                                type="button"
                                class="list__section-action list__section-button"
                                :aria-label="t('list.transferAllLabel')"
                                aria-haspopup="dialog"
                                @click="openTransfer(view.notGot)"
                            >
                                {{ t('list.transferAll') }}
                            </button>
                        </div>
                        <ul class="list__rows">
                            <ListRow
                                v-for="row in notGotRows"
                                :key="row.entry.id"
                                :data-entry-id="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="notGot"
                                :transferred-to="row.transferredTo"
                                :unsynced="isEntryUnsynced(row.entry.id)"
                                :swipe="swipeReopen"
                                @details="openEntry(row.entry)"
                                @swipe-action="backToOpen(row.entry)"
                                @transfer="openTransfer([row.entry])"
                            />
                        </ul>
                    </section>

                    <section v-if="doneRows.length > 0" class="list__section">
                        <h2 class="list__section-head">
                            <button
                                type="button"
                                class="list__section-toggle"
                                :aria-expanded="doneOpen ? 'true' : 'false'"
                                @click="toggleDone"
                            >
                                <span class="list__section-label">
                                    {{ t('section.count', { label: t('list.done'), count: doneRows.length }) }}
                                </span>
                                <span class="list__section-action">
                                    {{ doneOpen ? t('list.collapse') : t('list.expand') }}
                                    <ChevronDown class="list__section-chevron" aria-hidden="true" />
                                </span>
                            </button>
                        </h2>
                        <ul v-if="doneOpen" ref="doneList" class="list__rows">
                            <ListRow
                                v-for="row in doneRows"
                                :key="row.entry.id"
                                :data-entry-id="row.entry.id"
                                :name="row.name"
                                :note="row.entry.note"
                                :amount="row.entry.quantity.amount"
                                :unit="row.entry.quantity.unit"
                                :image-url="row.imageUrl"
                                :icon="row.icon"
                                :category="row.category"
                                status="done"
                                @details="openEntry(row.entry)"
                                @toggle="uncheck(row.entry)"
                            />
                        </ul>
                    </section>

                    <div class="list__complete">
                        <AppButton variant="secondary" block class="list__complete-button" aria-haspopup="dialog" @click="startCompletion">
                            <Archive aria-hidden="true" />
                            {{ t('list.complete') }}
                        </AppButton>
                        <p class="list__complete-hint">{{ t('list.completeHint') }}</p>
                    </div>
                </template>
            </template>

            <p class="visually-hidden" aria-live="polite">{{ announcement }}</p>
        </div>

        <ListFormSheet
            v-if="list"
            v-model:open="formOpen"
            :list="list"
            :entry-count="counts.total"
            @submit="onSave"
            @delete="onDelete"
            @move="onMove"
            @copy="onCopy"
        />

        <ListFormSheet v-if="list" v-model:open="newFromOpen" :source-list-id="listId" @submit="onCreateFrom" />

        <TransferSheet
            v-if="list"
            v-model:open="transferOpen"
            :list="list"
            :entries="transferEntries"
            :mode="transferMode"
            @finished="onTransferFinished"
        />
    </AppPage>
</template>

<style scoped>
.list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: 0 var(--list-gutter);
}

.list__add,
.list__more {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.list__add {
    width: var(--tap-size-main);
    height: var(--tap-size-main);
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.list__more {
    color: var(--color-text-1);
}

.list__add:active,
.list__more:active {
    transform: scale(var(--press-scale));
}

@media (hover: hover) {
    .list__add:hover {
        background: var(--color-accent-hover);
    }

    .list__more:hover {
        background: var(--color-surface-2);
    }
}

.list__add :deep(svg),
.list__more :deep(svg) {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.list__offline {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    margin: 0 var(--space-2);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-offline-bg);
    color: var(--color-offline-text);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.list__offline-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.list__progress {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-2) var(--space-1);
}

.list__progress-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    min-height: var(--line-height-qty);
}

.list__progress-done {
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.list__progress-open {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-qty);
}

.list__bar {
    display: block;
    height: var(--progress-height);
    overflow: hidden;
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
}

.list__progress--skeleton .list__bar {
    background: var(--color-skeleton-2);
}

.list__fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--color-accent);
    transition: width var(--duration-medium) var(--ease-out);
}

.list__skeleton-bar {
    display: block;
    width: 5rem;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.list__skeleton-bar--wide {
    width: 8rem;
    background: var(--color-skeleton);
}

.list__sortable {
    position: relative;
}

.list__rows {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

/* während des Ziehens darf die angehobene Zeile über den Rand */
.list__rows.is-sorting {
    overflow: visible;
    user-select: none;
    -webkit-user-select: none;
}

.list__all-done {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.list__all-done strong {
    color: var(--color-done);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.list__add-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-3) var(--space-2) var(--handle-width);
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-md);
    color: var(--color-accent-text);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
    text-align: left;
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .list__add-row:hover {
        background: var(--color-surface-1);
    }
}

.list__add-row-icon {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-accent-soft);
}

.list__add-row-icon :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.list__section {
    display: flex;
    flex-direction: column;
}

.list__section-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    width: 100%;
    min-height: var(--tap-size);
    padding: var(--space-2) var(--space-2) var(--space-1);
}

.list__section-label {
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.list__section-action {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    color: var(--color-accent-text);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.list__section-chevron {
    width: var(--icon-size-sm);
    height: var(--icon-size-sm);
    transition: transform var(--duration-fast) var(--ease-out);
}

.list__section-toggle[aria-expanded="true"] .list__section-chevron {
    transform: rotate(180deg);
}

.list__section-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
}

.list__section-bar-label {
    min-width: 0;
}

.list__section-button {
    min-height: var(--tap-size);
    padding: 0 var(--space-2);
    border-radius: var(--radius-sm);
}

@media (hover: hover) {
    .list__section-button:hover {
        background: var(--color-surface-2);
    }
}

.list__complete {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding-top: var(--space-3);
}

.list__complete-button {
    border: 1px solid var(--color-accent);
    background: transparent;
    color: var(--color-accent-text);
}

.list__complete-hint {
    padding: 0 var(--space-2);
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-align: center;
}

.list__archived {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.list__archived-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.list__archived-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-done);
}

.list__archived-text,
.list__archived-changed {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-qty);
}

.list__archived-changed {
    color: var(--color-unsynced);
    font-weight: var(--font-weight-semibold);
}

.list__archived-actions {
    display: grid;
    gap: var(--space-2);
    padding-top: var(--space-2);
}

@media (min-width: 30em) {
    .list__archived-actions {
        grid-template-columns: 1fr 1fr;
    }
}

@media (min-width: 64em) {
    .list {
        padding: 0 var(--page-gutter);
    }
}
</style>

<style>
/* Sortieren (useDragSort setzt die Klassen direkt am DOM) */
.list__rows.is-sorting > .list-row {
    transition: transform var(--duration-medium) var(--ease-out);
}

.list__rows.is-sorting > .list-row.is-lifted {
    position: relative;
    z-index: 2;
    transition: none;
    box-shadow: var(--shadow-drag);
}

.list__rows > .list-row.is-lifted {
    position: relative;
    z-index: 2;
    border-radius: var(--radius-md);
    outline: 2px solid var(--color-accent);
    outline-offset: -2px;
}

.drag-placeholder {
    position: absolute;
    left: 0;
    right: 0;
    z-index: 1;
    border: 2px dashed var(--color-accent);
    border-radius: var(--radius-md);
    background: var(--color-accent-tint);
    pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
    .list__rows.is-sorting > .list-row {
        transition: none;
    }
}
</style>
