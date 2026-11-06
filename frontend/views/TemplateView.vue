<script setup>
/**
 * Eine Vorlage (nicht entworfen, im Stil der Einkaufsliste).
 *
 * Ansehen: Produkte mit Mengen (und gespeicherten Notizen) in ihrer
 * Reihenfolge, ohne Griff und ohne Abhaken; oben eine Karte mit Herkunft,
 * wer zuletzt gespeichert hat, „Neue Liste daraus“ und „Vorlage
 * bearbeiten“. „…“ öffnet „Vorlage verwalten“ (Name, in den Haushalt
 * verschieben oder kopieren, löschen).
 *
 * Bearbeiten (ausdrücklich, Konzept: „Dauerhafte Anpassungen erfolgen
 * ausdrücklich über ‚Vorlage bearbeiten‘“): ein Entwurf mit Name, Sortieren
 * am Griff (`useDragSort`, Pfeiltasten), Menge über das Mengen-Control
 * (Halten auf der Zeile) oder mit der Notiz im Sheet,
 * Herausnehmen mit „Rückgängig“, „Produkte hinzufügen“ über die Auswahl
 * (`/templates/:id/add`). „Speichern“ schickt alles zusammen mit der
 * Revision, von der der Entwurf ausging; hat jemand anderes inzwischen
 * gespeichert, fragt ein Sheet, welche Fassung gelten soll. „Verwerfen“
 * fragt nach, wenn sich etwas geändert hat. Der Entwurf überlebt den Weg in
 * die Auswahl und zurück.
 */
import { logError } from '@/api/index.js';
import { computed, nextTick, ref, useId, useTemplateRef } from 'vue';
import { onIonViewWillEnter, useIonRouter } from '@ionic/vue';
import { CircleAlert, CloudOff, Ellipsis, Files, History, Pencil, Plus, SearchX, Trash2, TriangleAlert, UserX } from '@lucide/vue';
import { useRoute } from 'vue-router';
import AppPage from '@/components/layout/AppPage.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import ListRow from '@/components/list/ListRow.vue';
import SkeletonRow from '@/components/list/SkeletonRow.vue';
import EntrySheet from '@/components/lists/EntrySheet.vue';
import ListFormSheet from '@/components/lists/ListFormSheet.vue';
import TemplateFormSheet from '@/components/templates/TemplateFormSheet.vue';
import { useDragSort } from '@/composables/useDragSort.js';
import { useConnection } from '@/composables/useConnection.js';
import { createListWith } from '@/composables/useLists.js';
import { navigateBack } from '@/composables/usePageTransition.js';
import { loadProducts, useProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { showToast } from '@/composables/useToast.js';
import {
    copyTemplateTo,
    deleteTemplate,
    discardDraft,
    draftFor,
    insertDraftItem,
    isTemplateChanged,
    loadTemplate,
    moveDraftItem,
    moveTemplateToHousehold,
    removeDraftItem,
    renameDraft,
    renameTemplate,
    saveDraft,
    startEditing,
    takeCurrent,
    templateDetail,
    updateDraftItem
} from '@/composables/useTemplates.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatDay } from '@/utils/shopDate.js';
import { t } from '@/i18n/index.js';

const SKELETON_WIDTHS = [0.55, 0.35, 0.3, 0.6, 0.45, 0.3];
const NAME_MAX = 100;
const NBSP = ' ';

const route = useRoute();
const ionRouter = useIonRouter();

// Ionic hält Seiten im Verlauf; die ID gehört zu dieser Seite
const templateId = String(route.params.id ?? '');
const addPath = '/templates/' + encodeURIComponent(templateId) + '/add';

const { findProduct, status: productStatus } = useProducts();
const { user } = useSession();

const ids = {
    name: useId(),
    nameError: useId()
};

const rowsRef = useTemplateRef('rowList');
const manageOpen = ref(false);
const managePending = ref(false);
const manageError = ref('');
const newListOpen = ref(false);
const entryOpen = ref(false);
const editedProductId = ref(null);
const confirm = ref(null); // null · 'discard' · { current } (inzwischen geändert)
const saving = ref(false);
const saveError = ref('');
const nameSubmitted = ref(false);
const announcement = ref('');

const { online } = useConnection();
const detail = computed(() => templateDetail(templateId));
const template = computed(() => detail.value.template);
const draft = computed(() => draftFor(templateId));
const editing = computed(() => draft.value !== null);
const changed = computed(() => isTemplateChanged(templateId));

const loading = computed(() => {
    if (detail.value.status === 'missing' || detail.value.status === 'error') {
        return false;
    }

    return !template.value || (productStatus.value !== 'ready' && productStatus.value !== 'error');
});

/** Meldung, wenn die Vorlage nicht (mehr) da ist. */
const missing = computed(() => {
    switch (detail.value.reason) {
        case 'no_access':
            return { icon: UserX, title: t('template.noAccessTitle'), text: t('template.noAccessText') };
        case 'template_deleted':
            return { icon: Trash2, title: t('template.deletedTitle'), text: t('template.deletedText') };
        default:
            return { icon: SearchX, title: t('template.notFoundTitle'), text: t('template.notFoundText') };
    }
});

const items = computed(() => (editing.value ? draft.value.items : template.value?.entries ?? []));

const rows = computed(() => items.value.map((item) => {
    const product = findProduct(item.productId);

    return {
        key: item.productId,
        item,
        name: product?.name ?? t('entry.unknownProduct'),
        packageSize: product?.packageSize ?? null,
        imageUrl: product?.imageUrl ?? null,
        icon: product ? iconOf(product) : undefined,
        category: categoryOf(product)
    };
}));

const subtitle = computed(() => {
    if (!template.value) {
        return '';
    }

    const origin = template.value.householdId ? t('lists.originHousehold') : t('lists.originPersonal');

    return origin + ' · ' + t('templates.count', { count: items.value.length });
});

const updatedText = computed(() => {
    const value = template.value;

    if (!value) {
        return '';
    }

    const day = formatDay(value.updatedAt);
    const name = value.updatedBy === user.value?.id ? t('template.byMe') : value.updatedByName;

    return t('template.updated', { name, day });
});

const nameError = computed(() => {
    if (!editing.value || !nameSubmitted.value) {
        return '';
    }

    const name = draft.value.name.trim();

    if (name === '') {
        return t('templateForm.nameRequired');
    }

    return name.length > NAME_MAX ? t('listForm.nameTooLong', { count: NAME_MAX }) : '';
});

const editedEntry = computed(() => draft.value?.items.find((item) => item.productId === editedProductId.value) ?? null);
const editedName = computed(() => (editedEntry.value ? findProduct(editedEntry.value.productId)?.name ?? '' : ''));

/** Neue Liste: Name, Zugehörigkeit und Produkte wie in der Vorlage. */
const sourceTemplate = computed(() => (template.value
    ? { id: template.value.id, name: template.value.name, householdId: template.value.householdId, entries: template.value.entries }
    : null));

function reload() {
    loadTemplate(templateId).catch(logError);
    loadProducts().catch(logError);
}

onIonViewWillEnter(() => {
    // mit offenem Entwurf (zurück aus „Produkte hinzufügen“) nicht neu laden: der Stand ist der des Entwurfs
    if (!editing.value || !template.value) {
        reload();
    }
});

function openProduct(productId) {
    ionRouter.push('/products/' + encodeURIComponent(productId));
}

function edit() {
    nameSubmitted.value = false;
    saveError.value = '';
    startEditing(templateId);
}

function addProducts() {
    if (!editing.value) {
        startEditing(templateId);
    }

    ionRouter.push(addPath);
}

function onNameInput(event) {
    renameDraft(templateId, event.target.value);
}

function openEntry(productId) {
    editedProductId.value = productId;
    entryOpen.value = true;
}

function onEntrySave(changes) {
    if (editedProductId.value) {
        updateDraftItem(templateId, editedProductId.value, changes);
    }
}

/** Mengen-Control: neue Menge im Entwurf, die Einheit bleibt. */
function changeAmount(row, amount) {
    updateDraftItem(templateId, row.key, { quantity: { ...row.item.quantity, amount } });
}

function remove(row, index) {
    removeDraftItem(templateId, row.item.productId);
    announcement.value = t('template.removed', { name: row.name });
    showToast(t('template.removed', { name: row.name }), {
        icon: 'info',
        action: {
            text: t('common.undo'),
            handler: () => insertDraftItem(templateId, row.item, index)
        }
    });
}

function announceMove(productId) {
    const index = draft.value?.items.findIndex((item) => item.productId === productId) ?? -1;
    const row = rows.value[index];

    if (row) {
        announcement.value = t('list.moved', { name: row.name, position: index + 1, count: rows.value.length });
    }
}

const { start: startDrag } = useDragSort({
    list: rowsRef,
    async onDrop(productId, from, to) {
        moveDraftItem(templateId, from, to);
        await nextTick();
        announceMove(productId);
    }
});

async function moveByKey(row, index, direction) {
    const to = index + direction;

    if (to < 0 || to >= rows.value.length) {
        return;
    }

    moveDraftItem(templateId, index, to);
    await nextTick();

    const element = rowsRef.value?.querySelector(`:scope > [data-entry-id="${CSS.escape(row.key)}"] [data-name="handle"]`);

    element?.focus();
    announceMove(row.key);
}

function saveErrorText(error) {
    if (error?.status === 0) {
        return t('template.saveOffline');
    }

    if (error?.code === 'unknown_product') {
        return t('template.saveUnknownProduct');
    }

    return t('template.saveError');
}

async function save(revision = null) {
    nameSubmitted.value = true;

    if (nameError.value || saving.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';

    try {
        const result = await saveDraft(templateId, { revision });

        if (result.status === 'changed') {
            confirm.value = { current: result.current };
            return;
        }

        confirm.value = null;
        showToast(t('template.saved', { name: result.template?.name ?? '' }), { icon: 'done' });
    } catch (error) {
        console.error(error);
        saveError.value = saveErrorText(error);
        confirm.value = null;
    } finally {
        saving.value = false;
    }
}

function cancelEditing() {
    if (changed.value) {
        confirm.value = 'discard';
        return;
    }

    discardDraft(templateId);
}

function discard() {
    confirm.value = null;
    discardDraft(templateId);
    saveError.value = '';
}

function takeTheirs() {
    const { current } = confirm.value;

    confirm.value = null;
    takeCurrent(templateId, current);
    showToast(t('template.tookTheirs'), { icon: 'info' });
}

function keepMine() {
    save(confirm.value.current.revision);
}

const confirmOpen = computed({
    get: () => confirm.value !== null,
    set: (value) => {
        if (!value) {
            confirm.value = null;
        }
    }
});

const conflictName = computed(() => {
    const current = confirm.value?.current;

    if (!current) {
        return '';
    }

    return current.updatedBy === user.value?.id ? t('template.byMe') : current.updatedByName;
});

function openManage() {
    manageError.value = '';
    manageOpen.value = true;
}

async function runManage(action) {
    managePending.value = true;
    manageError.value = '';

    try {
        await action();
        manageOpen.value = false;
    } catch (error) {
        console.error(error);
        manageError.value = error?.status === 0 ? t('templateSave.offline') : t('listForm.ownershipError');
    } finally {
        managePending.value = false;
    }
}

function onRename({ name }) {
    if (name === template.value?.name) {
        manageOpen.value = false;
        return;
    }

    runManage(async () => {
        await renameTemplate(templateId, name);
        showToast(t('template.saved', { name }), { icon: 'done' });
    });
}

function onMove() {
    const name = template.value?.name ?? '';

    runManage(async () => {
        await moveTemplateToHousehold(templateId);
        showToast(t('templateForm.movedToast', { name }), { icon: 'info' });
    });
}

function onCopy() {
    const name = template.value?.name ?? '';

    runManage(async () => {
        await copyTemplateTo(templateId, 'household');
        showToast(t('templateForm.copiedToast', { name }), { icon: 'info' });
    });
}

function onDelete() {
    const name = template.value?.name ?? '';

    runManage(async () => {
        await deleteTemplate(templateId);
        showToast(t('templateForm.deletedToast', { name }), { icon: 'info' });
        navigateBack(ionRouter, '/templates');
    });
}

async function onCreateList(fields) {
    const listId = await createListWith(fields);

    ionRouter.push('/lists/' + encodeURIComponent(listId));
}
</script>

<template>
    <AppPage
        :title="(editing ? draft.name.trim() : template?.name) || NBSP"
        :subtitle="subtitle || NBSP"
        back
        back-to="/templates"
        width="list"
        :user-menu="false"
    >
        <template v-if="template && detail.status !== 'missing'" #actions>
            <button v-if="editing" type="button" class="template__save" :disabled="saving" @click="save()">
                {{ t('common.save') }}
            </button>
            <button
                v-else
                type="button"
                class="template__more"
                :aria-label="t('template.actions')"
                aria-haspopup="dialog"
                @click="openManage"
            >
                <Ellipsis aria-hidden="true" />
            </button>
        </template>

        <div class="template">
            <EmptyState
                v-if="detail.status === 'missing'"
                :icon="missing.icon"
                :title="missing.title"
                :text="missing.text"
            >
                <AppButton variant="secondary" @click="navigateBack(ionRouter, '/templates')">{{ t('template.toOverview') }}</AppButton>
            </EmptyState>

            <EmptyState
                v-else-if="detail.status === 'error' && !online"
                :icon="CloudOff"
                :title="t('templates.offlineTitle')"
                :text="t('templates.offlineText')"
            >
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <EmptyState v-else-if="detail.status === 'error'" :icon="TriangleAlert" :title="t('template.loadError')">
                <AppButton variant="secondary" @click="reload">{{ t('common.retry') }}</AppButton>
            </EmptyState>

            <template v-else-if="loading">
                <div class="template__card template__card--skeleton" aria-hidden="true">
                    <span class="template__skeleton-line"></span>
                    <span class="template__skeleton-actions">
                        <span class="template__skeleton-button"></span>
                        <span class="template__skeleton-button"></span>
                    </span>
                </div>
                <section class="template__section">
                    <SectionLabel :label="t('template.products')" />
                    <ul class="template__rows" aria-busy="true">
                        <SkeletonRow v-for="(width, index) in SKELETON_WIDTHS" :key="index" :width="width" trailing="none" />
                    </ul>
                </section>
            </template>

            <template v-else-if="editing">
                <section class="template__card template__card--edit" :aria-label="t('template.editTitle')">
                    <p class="template__card-title">
                        <Pencil class="template__card-icon" aria-hidden="true" />
                        {{ t('template.editTitle') }}
                    </p>
                    <label class="form-field" :for="ids.name">
                        <span class="form-field__label">{{ t('templateForm.name') }}</span>
                        <input
                            :id="ids.name"
                            class="form-field__input"
                            type="text"
                            :value="draft.name"
                            :maxlength="NAME_MAX"
                            autocomplete="off"
                            enterkeyhint="done"
                            :aria-invalid="nameError ? 'true' : 'false'"
                            :aria-describedby="nameError ? ids.nameError : undefined"
                            @input="onNameInput"
                        >
                        <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                            <CircleAlert aria-hidden="true" />
                            {{ nameError }}
                        </span>
                    </label>
                    <p class="template__card-text">{{ t('template.editHint') }}</p>
                </section>

                <h2 class="visually-hidden">{{ t('template.products') }}</h2>
                <div class="template__sortable">
                    <ul v-if="rows.length > 0" ref="rowList" class="template__rows sort-rows">
                        <ListRow
                            v-for="(row, index) in rows"
                            :key="row.key"
                            :data-entry-id="row.key"
                            :name="row.name"
                            :package-size="row.packageSize"
                            :note="row.item.note"
                            :amount="row.item.quantity.amount"
                            :unit="row.item.quantity.unit"
                            :image-url="row.imageUrl"
                            :icon="row.icon"
                            :category="row.category"
                            status="open"
                            trailing="remove"
                            quantity-control
                            @details="openEntry(row.key)"
                            @quantity="changeAmount(row, $event)"
                            @remove="remove(row, index)"
                            @handle-pointerdown="startDrag($event, row.key)"
                            @move="moveByKey(row, index, $event)"
                        />
                    </ul>
                </div>

                <button type="button" class="template__add-row" @click="addProducts">
                    <span class="template__add-row-icon">
                        <Plus aria-hidden="true" />
                    </span>
                    {{ t('list.add') }}
                </button>

                <p v-if="saveError" class="form-field__error" role="alert">
                    <CircleAlert aria-hidden="true" />
                    {{ saveError }}
                </p>

                <div class="template__edit-actions">
                    <AppButton variant="secondary" @click="cancelEditing">{{ t('template.discard') }}</AppButton>
                    <AppButton :disabled="saving" @click="save()">{{ t('common.save') }}</AppButton>
                </div>
            </template>

            <template v-else>
                <section class="template__card" :aria-label="t('template.summary')">
                    <p class="template__card-text">{{ updatedText }}</p>
                    <div class="template__actions">
                        <AppButton :disabled="rows.length === 0" @click="newListOpen = true">
                            <History aria-hidden="true" />
                            {{ t('template.startList') }}
                        </AppButton>
                        <AppButton variant="secondary" @click="edit">
                            <Pencil aria-hidden="true" />
                            {{ t('template.edit') }}
                        </AppButton>
                    </div>
                </section>

                <EmptyState
                    v-if="rows.length === 0"
                    :icon="Files"
                    :title="t('template.emptyTitle')"
                    :text="t('template.emptyText')"
                >
                    <AppButton @click="addProducts">
                        <Plus aria-hidden="true" />
                        {{ t('list.add') }}
                    </AppButton>
                </EmptyState>

                <section v-else class="template__section">
                    <SectionLabel :label="t('template.products')" :count="rows.length" />
                    <ul class="template__rows">
                        <ListRow
                            v-for="row in rows"
                            :key="row.key"
                            :name="row.name"
                            :package-size="row.packageSize"
                            :note="row.item.note"
                            :amount="row.item.quantity.amount"
                            :unit="row.item.quantity.unit"
                            :image-url="row.imageUrl"
                            :icon="row.icon"
                            :category="row.category"
                            status="open"
                            readonly
                            trailing="none"
                            @details="openProduct(row.key)"
                        />
                    </ul>
                </section>
            </template>

            <p class="visually-hidden" aria-live="polite">{{ announcement }}</p>
        </div>

        <TemplateFormSheet
            v-if="template"
            v-model:open="manageOpen"
            :template="template"
            :pending="managePending"
            :error="manageError"
            @submit="onRename"
            @move="onMove"
            @copy="onCopy"
            @delete="onDelete"
        />

        <ListFormSheet v-if="sourceTemplate" v-model:open="newListOpen" :source-template="sourceTemplate" @submit="onCreateList" />

        <EntrySheet
            v-model:open="entryOpen"
            :entry="editedEntry"
            :product-name="editedName"
            :subtitle="t('template.entrySubtitle', { name: draft?.name ?? '' })"
            :note-hint="t('template.noteHint')"
            @save="onEntrySave"
        />

        <AppSheet
            v-model:open="confirmOpen"
            :title="confirm === 'discard' ? t('template.discardTitle') : t('template.changedTitle')"
        >
            <p v-if="confirm === 'discard'" class="template__confirm">{{ t('template.discardText') }}</p>
            <div v-else-if="confirm" class="template__confirm-stack">
                <p class="template__confirm">
                    {{ t('template.changedText', { name: conflictName, day: formatDay(confirm.current.updatedAt) }) }}
                </p>
                <p class="template__card-text">{{ t('template.changedHint') }}</p>
            </div>

            <template #footer>
                <template v-if="confirm === 'discard'">
                    <AppButton variant="secondary" @click="confirm = null">{{ t('template.keepEditing') }}</AppButton>
                    <AppButton variant="danger-solid" @click="discard">{{ t('template.discardConfirm') }}</AppButton>
                </template>
                <template v-else>
                    <AppButton variant="secondary" @click="takeTheirs">{{ t('template.takeTheirs') }}</AppButton>
                    <AppButton :disabled="saving" @click="keepMine">{{ t('template.keepMine') }}</AppButton>
                </template>
            </template>
        </AppSheet>
    </AppPage>
</template>

<style scoped>
.template {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: 0 var(--list-gutter);
}

.template__more,
.template__save {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    min-width: var(--tap-size);
    height: var(--tap-size);
    border-radius: var(--radius-pill);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.template__more {
    width: var(--tap-size);
    color: var(--color-text-1);
}

.template__more :deep(svg) {
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
}

.template__save {
    padding: 0 var(--space-5);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
}

.template__save:disabled {
    opacity: 0.6;
}

.template__more:active,
.template__save:active {
    transform: scale(var(--press-scale));
}

@media (hover: hover) {
    .template__more:hover {
        background: var(--color-surface-2);
    }

    .template__save:hover {
        background: var(--color-accent-hover);
    }
}

.template__card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

.template__card--edit {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

.template__card-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.template__card-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-accent-text);
}

.template__card-text {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.template__actions,
.template__edit-actions {
    display: grid;
    gap: var(--space-2);
}

@media (min-width: 30em) {
    .template__actions,
    .template__edit-actions {
        grid-template-columns: 1fr 1fr;
    }
}

.template__edit-actions {
    padding-top: var(--space-3);
}

.template__section {
    display: flex;
    flex-direction: column;
}

.template__sortable {
    position: relative;
}

.template__rows {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-1);
}

/* während des Ziehens darf die angehobene Zeile über den Rand */
.template__rows.is-sorting {
    overflow: visible;
    user-select: none;
    -webkit-user-select: none;
}

.template__add-row {
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
    .template__add-row:hover {
        background: var(--color-surface-1);
    }
}

.template__add-row-icon {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-accent-soft);
}

.template__add-row-icon :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.template__confirm {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.template__confirm-stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

/* Skeleton in Endhöhe der Karte (Text 18 px, Knöpfe) */
.template__card--skeleton {
    gap: var(--space-3);
}

.template__skeleton-line {
    display: block;
    width: 60%;
    height: var(--line-height-note);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.template__skeleton-actions {
    display: grid;
    gap: var(--space-2);
}

@media (min-width: 30em) {
    .template__skeleton-actions {
        grid-template-columns: 1fr 1fr;
    }
}

.template__skeleton-button {
    display: block;
    height: var(--button-height);
    border-radius: var(--radius-md);
    background: var(--color-skeleton);
}

@media (min-width: 64em) {
    .template {
        padding: 0 var(--page-gutter);
    }
}
</style>
