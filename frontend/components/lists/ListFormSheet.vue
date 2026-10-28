<script setup>
/**
 * Sheet „Neue Liste“ bzw. „Liste bearbeiten“: frei gewählter Name (Pflicht,
 * höchstens 100 Zeichen) und ein freiwilliges Datum (Ohne Datum · Heute ·
 * Morgen · anderes Datum). Beim Bearbeiten darunter „Liste löschen“ mit
 * Rückfrage im selben Sheet.
 *
 * Neue Liste (Designpaket 02): „Wie möchtest du starten?“ – leer oder aus
 * einem früheren Einkauf (die letzten abgeschlossenen). Mit einem früheren
 * Einkauf folgt ein zweiter Schritt: Produkte mit Mengen in der alten
 * Reihenfolge, alle vorgewählt, abwählbar („Alle“, „Nur nicht gekaufte“).
 * In der neuen Liste ist alles offen; Notizen bleiben im alten Einkauf.
 *
 * Zugehörigkeit (Schritt 9): Mit Haushalt ist eine neue Liste standardmäßig
 * für den Haushalt, „Nur für mich“ ist wählbar. Beim Bearbeiten einer
 * persönlichen Liste „In den Haushalt verschieben“ (Rückfrage im selben
 * Sheet, die Liste gehört danach dem Haushalt) und „In den Haushalt
 * kopieren“ (das Original bleibt privat).
 *
 * Teilen (Schritt 11): Besitzer und Mitglieder sehen „Liste teilen …“; das
 * öffnet `ListShareSheet` in der Listenansicht. Gäste bekommen dieses Sheet
 * nicht.
 *
 * v-model:open · `list` null = neu · `sourceListId`: gleich mit diesem
 * früheren Einkauf starten (aus dem Archiv)
 * Emits: submit({ name, shopDate, householdId, items }) (`items` null = leer)
 * · delete · move · copy · share
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert, Copy, House, Share2, Trash2, User } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import EntryChecklist from './EntryChecklist.vue';
import { ensureListLoaded, listEntries, useLists } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatDay, shopDateFromToday } from '@/utils/shopDate.js';
import { copyPlan } from '@/utils/takeOver.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 100;
const PREVIOUS_MAX = 5;

const props = defineProps({
    list: {
        type: Object,
        default: null
    },
    entryCount: {
        type: Number,
        default: 0
    },
    sourceListId: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['submit', 'delete', 'move', 'copy', 'share']);
const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    name: useId(),
    nameError: useId(),
    date: useId(),
    dateLabel: useId(),
    start: useId(),
    owner: useId(),
    ownerHeading: useId(),
    shareHeading: useId(),
    selectError: useId()
};

const { archivedLists } = useLists();
const { findProduct } = useProducts();
const { household } = useSession();

const nameInput = ref(null);
const name = ref('');
const shopDate = ref(null);
const submitted = ref(false);
const confirmDelete = ref(false);
const confirmMove = ref(false);
const owner = ref('household');
const step = ref('form');
const start = ref('empty');
const selected = ref([]);
const selectError = ref('');
const sourceError = ref(false);

// Einkauf, dessen Produkte schon vorgewählt wurden
let selectedFor = null;

const isNew = computed(() => props.list === null);

/** Gehört die (bestehende) Liste dem Haushalt? */
const isHouseholdList = computed(() => Boolean(props.list?.householdId));

/** Eigene persönliche Liste, die sich in den Haushalt verschieben oder kopieren lässt. */
const canMoveToHousehold = computed(() => Boolean(household.value)
    && !isNew.value
    && !isHouseholdList.value
    && props.list?.role === 'owner'
    && !props.list?.local);

/** Teilen dürfen Besitzer persönlicher Listen und Mitglieder bei Haushaltslisten. */
const canShareList = computed(() => !isNew.value && (props.list?.role === 'owner' || props.list?.role === 'member'));

/** Frühere Einkäufe zur Auswahl; ein vorgegebener steht immer dabei. */
const previous = computed(() => {
    const recent = archivedLists.value.slice(0, PREVIOUS_MAX);
    const given = archivedLists.value.find((item) => item.id === props.sourceListId);

    return given && !recent.includes(given) ? [given, ...recent] : recent;
});

const source = computed(() => (start.value === 'empty' ? null : start.value));
const sourceList = computed(() => archivedLists.value.find((item) => item.id === source.value) ?? null);

const sourceItems = computed(() => {
    const entries = source.value ? listEntries(source.value) : null;

    if (!entries) {
        return null;
    }

    return copyPlan(entries).map((item) => {
        const product = findProduct(item.productId);

        return {
            ...item,
            id: item.productId,
            name: product?.name ?? t('entry.unknownProduct'),
            imageUrl: product?.imageUrl ?? null,
            icon: product ? iconOf(product) : undefined,
            category: categoryOf(product),
            meta: item.status === 'done' ? '' : t(item.status === 'notGot' ? 'entry.statusNotGot' : 'entry.statusOpen')
        };
    });
});

function previousTitle(item) {
    const day = formatDay(item.completedAt) || item.shopDate;

    return day ? item.name + ' · ' + day : item.name;
}

const sheetTitle = computed(() => {
    if (confirmDelete.value) {
        return t('listForm.deleteTitle');
    }

    if (confirmMove.value) {
        return t('listForm.moveTitle');
    }

    if (step.value === 'products') {
        return t('listForm.chooseProducts');
    }

    return isNew.value ? t('listForm.newTitle') : t('listForm.editTitle');
});

const sheetSubtitle = computed(() => (step.value === 'products' && sourceList.value
    ? t('listForm.chooseProductsSubtitle', { name: previousTitle(sourceList.value) })
    : ''));

const submitLabel = computed(() => {
    if (!isNew.value) {
        return t('common.save');
    }

    return source.value && step.value === 'form' ? t('common.next') : t('listForm.create');
});
const today = computed(() => shopDateFromToday(0));
const tomorrow = computed(() => shopDateFromToday(1));

const dateChoice = computed(() => {
    if (!shopDate.value) {
        return 'none';
    }

    if (shopDate.value === today.value) {
        return 'today';
    }

    return shopDate.value === tomorrow.value ? 'tomorrow' : 'other';
});

const nameError = computed(() => {
    if (!submitted.value) {
        return '';
    }

    const trimmed = name.value.trim();

    if (trimmed === '') {
        return t('listForm.nameRequired');
    }

    return trimmed.length > NAME_MAX ? t('listForm.nameTooLong', { count: NAME_MAX }) : '';
});

watch(open, (isOpen) => {
    if (isOpen) {
        const given = props.sourceListId ? archivedLists.value.find((item) => item.id === props.sourceListId) : null;

        name.value = props.list?.name ?? given?.name ?? '';
        shopDate.value = props.list?.shopDate ?? null;
        submitted.value = false;
        confirmDelete.value = false;
        confirmMove.value = false;
        owner.value = household.value ? 'household' : 'personal';
        start.value = given ? given.id : 'empty';
        step.value = given ? 'products' : 'form';
        selectError.value = '';
        selectedFor = null;

        if (given) {
            ensureListLoaded(given.id).catch((error) => {
                console.error(error);
                sourceError.value = true;
            });
        }
    }
});

// früheren Einkauf gewählt: laden, alles vorwählen, leeren Namen vorschlagen
watch(source, (listId) => {
    sourceError.value = false;

    if (!listId) {
        return;
    }

    if (name.value.trim() === '') {
        name.value = sourceList.value?.name ?? '';
    }

    ensureListLoaded(listId).catch((error) => {
        console.error(error);
        sourceError.value = true;
    });
});

// sobald die Produkte eines (neu) gewählten Einkaufs da sind: alle vorwählen
watch([source, sourceItems], ([listId, items]) => {
    if (!listId) {
        selectedFor = null;
        return;
    }

    if (items && selectedFor !== listId) {
        selectedFor = listId;
        selected.value = items.map((item) => item.id);
    }
}, { immediate: true });

function selectAll() {
    selected.value = (sourceItems.value ?? []).map((item) => item.id);
}

function selectNotBought() {
    selected.value = (sourceItems.value ?? []).filter((item) => item.status !== 'done').map((item) => item.id);
}

function focusName() {
    if (isNew.value) {
        nameInput.value?.focus();
    }
}

function choose(value) {
    shopDate.value = value;
}

function onDateInput(event) {
    shopDate.value = event.target.value || null;
}

function submit() {
    submitted.value = true;

    if (nameError.value) {
        nameInput.value?.focus();
        return;
    }

    if (source.value && step.value === 'form') {
        step.value = 'products';
        return;
    }

    let items = null;

    if (source.value) {
        const chosen = new Set(selected.value);

        items = (sourceItems.value ?? [])
            .filter((item) => chosen.has(item.id))
            .map((item) => ({ productId: item.productId, quantity: item.quantity }));

        if (items.length === 0) {
            selectError.value = t('listForm.nothingSelected');
            return;
        }
    }

    const householdId = isNew.value && household.value && owner.value === 'household' ? household.value.id : null;

    emit('submit', { name: name.value.trim(), shopDate: shopDate.value, householdId, items });
    open.value = false;
}

function move() {
    emit('move');
    open.value = false;
}

function copy() {
    emit('copy');
    open.value = false;
}

function share() {
    emit('share');
    open.value = false;
}

function onSelect(value) {
    selected.value = value;
    selectError.value = '';
}

function remove() {
    emit('delete');
    open.value = false;
}
</script>

<template>
    <AppSheet
        v-model:open="open"
        :title="sheetTitle"
        :subtitle="sheetSubtitle"
        @presented="focusName"
    >
        <div v-if="confirmDelete" class="list-form__stack">
            <p class="list-form__confirm">
                {{ t('listForm.deleteText', { name: list?.name ?? '', count: entryCount }) }}
            </p>
            <p v-if="isHouseholdList" class="list-form__hint">{{ t('listForm.deleteHouseholdHint') }}</p>
        </div>

        <div v-else-if="confirmMove" class="list-form__stack">
            <p class="list-form__confirm">
                {{ t('listForm.moveText', { name: list?.name ?? '', household: household?.name ?? '' }) }}
            </p>
            <p class="list-form__hint">{{ t('listForm.moveHint') }}</p>
        </div>

        <form
            v-else-if="step === 'products'"
            :id="ids.name + '-form'"
            class="list-form"
            novalidate
            @submit.prevent="submit"
        >
            <p v-if="sourceError" class="form-field__error" role="alert">
                <CircleAlert aria-hidden="true" />
                {{ t('listForm.sourceLoadError') }}
            </p>
            <template v-else>
                <div class="list-form__select-bar">
                    <span class="list-form__select-count" aria-live="polite">
                        {{ t('listForm.selectedCount', { count: selected.length, total: sourceItems?.length ?? 0 }) }}
                    </span>
                    <span class="list-form__chips">
                        <button type="button" class="list-form__chip list-form__chip--small" @click="selectAll">
                            {{ t('listForm.selectAll') }}
                        </button>
                        <button type="button" class="list-form__chip list-form__chip--small" @click="selectNotBought">
                            {{ t('listForm.selectNotBought') }}
                        </button>
                    </span>
                </div>
                <EntryChecklist
                    :selected="selected"
                    :items="sourceItems ?? []"
                    :loading="sourceItems === null"
                    :skeleton-rows="Math.min(sourceList?.counts?.total ?? 3, 8) || 3"
                    :label="t('listForm.chooseProducts')"
                    @update:selected="onSelect"
                />
                <p v-if="selectError" :id="ids.selectError" class="form-field__error" role="alert">
                    <CircleAlert aria-hidden="true" />
                    {{ selectError }}
                </p>
                <p class="list-form__hint">{{ t('listForm.fromHint') }}</p>
            </template>
        </form>

        <form v-else :id="ids.name + '-form'" class="list-form" novalidate @submit.prevent="submit">
            <label class="form-field" :for="ids.name">
                <span class="form-field__label">{{ t('listForm.name') }}</span>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="name"
                    class="form-field__input"
                    type="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('listForm.namePlaceholder')"
                    autocomplete="off"
                    enterkeyhint="done"
                    :aria-invalid="nameError ? 'true' : 'false'"
                    :aria-describedby="nameError ? ids.nameError : undefined"
                >
                <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ nameError }}
                </span>
            </label>

            <fieldset class="list-form__dates">
                <legend class="form-field__label">
                    {{ t('listForm.date') }} <span class="form-field__label-hint">· {{ t('listForm.dateHint') }}</span>
                </legend>
                <div class="list-form__chips">
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'none' ? 'true' : 'false'"
                        @click="choose(null)"
                    >
                        {{ t('listForm.noDate') }}
                    </button>
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'today' ? 'true' : 'false'"
                        @click="choose(today)"
                    >
                        {{ t('listForm.today') }}
                    </button>
                    <button
                        type="button"
                        class="list-form__chip"
                        :aria-pressed="dateChoice === 'tomorrow' ? 'true' : 'false'"
                        @click="choose(tomorrow)"
                    >
                        {{ t('listForm.tomorrow') }}
                    </button>
                </div>
                <label class="form-field list-form__other" :for="ids.date">
                    <span class="visually-hidden">{{ t('listForm.dateChoice') }}</span>
                    <input
                        :id="ids.date"
                        class="form-field__input list-form__date"
                        :class="{ 'list-form__date--chosen': dateChoice === 'other' }"
                        type="date"
                        :value="shopDate ?? ''"
                        :aria-label="t('listForm.otherDate')"
                        @change="onDateInput"
                    >
                </label>
            </fieldset>

            <fieldset v-if="isNew && household" class="choice-list">
                <legend class="form-field__label list-form__legend">{{ t('listForm.owner') }}</legend>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="household">
                    <House class="list-form__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerHousehold', { name: household.name }) }}</span>
                        <span class="choice__hint">{{ t('listForm.ownerHouseholdHint') }}</span>
                    </span>
                </label>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="personal">
                    <User class="list-form__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerPersonal') }}</span>
                        <span class="choice__hint">{{ t('listForm.ownerPersonalHint') }}</span>
                    </span>
                </label>
            </fieldset>

            <section v-if="!isNew" class="list-form__owner" :aria-labelledby="ids.ownerHeading">
                <h3 :id="ids.ownerHeading" class="form-field__label">{{ t('listForm.owner') }}</h3>
                <p class="list-form__owner-text">
                    <component :is="isHouseholdList ? House : User" class="list-form__owner-icon" aria-hidden="true" />
                    <span>
                        {{ isHouseholdList
                            ? t('listForm.ownedByHousehold', { name: household?.name ?? t('lists.originHousehold') })
                            : t('listForm.ownedByMe') }}
                    </span>
                </p>
                <div v-if="canMoveToHousehold" class="list-form__owner-actions">
                    <AppButton variant="secondary" @click="confirmMove = true">
                        <House aria-hidden="true" />
                        {{ t('listForm.move') }}
                    </AppButton>
                    <AppButton variant="secondary" @click="copy">
                        <Copy aria-hidden="true" />
                        {{ t('listForm.copy') }}
                    </AppButton>
                    <p class="list-form__hint">{{ t('listForm.moveCopyHint') }}</p>
                </div>
            </section>

            <section v-if="canShareList" class="list-form__owner" :aria-labelledby="ids.shareHeading">
                <h3 :id="ids.shareHeading" class="form-field__label">{{ t('listForm.shareLabel') }}</h3>
                <p class="list-form__hint">
                    {{ list?.local ? t('listForm.shareLocal') : (isHouseholdList ? t('listForm.shareHint') : t('listForm.shareHintPersonal')) }}
                </p>
                <div class="list-form__owner-actions">
                    <AppButton variant="secondary" :disabled="Boolean(list?.local)" @click="share">
                        <Share2 aria-hidden="true" />
                        {{ t('listForm.share') }}
                    </AppButton>
                </div>
            </section>

            <fieldset v-if="isNew && previous.length > 0" class="choice-list">
                <legend class="form-field__label list-form__legend">{{ t('listForm.start') }}</legend>
                <label class="choice">
                    <input v-model="start" class="choice__radio" type="radio" :name="ids.start" value="empty">
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.startEmpty') }}</span>
                        <span class="choice__hint">{{ t('listForm.startEmptyHint') }}</span>
                    </span>
                </label>
                <p class="list-form__group-hint">
                    <strong>{{ t('listForm.startPrevious') }}</strong> · {{ t('listForm.startPreviousHint') }}
                </p>
                <label v-for="item in previous" :key="item.id" class="choice">
                    <input v-model="start" class="choice__radio" type="radio" :name="ids.start" :value="item.id">
                    <span class="choice__text">
                        <span class="choice__title">{{ previousTitle(item) }}</span>
                        <span class="choice__hint">{{ t('listForm.previousCount', { count: item.counts?.total ?? 0 }) }}</span>
                    </span>
                </label>
            </fieldset>

            <AppButton
                v-if="!isNew"
                variant="danger"
                class="list-form__delete"
                @click="confirmDelete = true"
            >
                <Trash2 aria-hidden="true" />
                {{ t('listForm.delete') }}
            </AppButton>
        </form>

        <template #footer>
            <template v-if="confirmDelete">
                <AppButton variant="secondary" @click="confirmDelete = false">{{ t('common.cancel') }}</AppButton>
                <AppButton variant="danger-solid" @click="remove">{{ t('listForm.deleteConfirm') }}</AppButton>
            </template>
            <template v-else-if="confirmMove">
                <AppButton variant="secondary" @click="confirmMove = false">{{ t('common.cancel') }}</AppButton>
                <AppButton @click="move">{{ t('listForm.moveConfirm') }}</AppButton>
            </template>
            <template v-else>
                <AppButton v-if="step === 'products'" variant="secondary" @click="step = 'form'">{{ t('common.back') }}</AppButton>
                <AppButton v-else variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
                <AppButton type="submit" :form="ids.name + '-form'">{{ submitLabel }}</AppButton>
            </template>
        </template>
    </AppSheet>
</template>

<style scoped>
.list-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.list-form__confirm {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.list-form__dates {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.list-form__dates legend {
    padding: 0;
    margin-bottom: var(--space-2);
}

.list-form__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.list-form__chip {
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

.list-form__chip[aria-pressed="true"] {
    border-color: var(--color-accent);
    background: var(--color-accent);
    color: var(--color-on-accent);
    font-weight: var(--font-weight-semibold);
}

@media (hover: hover) {
    .list-form__chip[aria-pressed="false"]:hover {
        background: var(--color-surface-2);
    }
}

.list-form__date {
    min-width: 0;
}

.list-form__date--chosen {
    border-color: var(--color-accent);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

.list-form__delete {
    align-self: flex-start;
}

.list-form__stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.list-form__owner {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.list-form__owner-text {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.list-form__owner-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-2);
}

.list-form__owner-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    padding-top: var(--space-1);
}

.list-form__legend {
    margin-bottom: var(--space-2);
    padding: 0;
}

.list-form__group-hint {
    padding: var(--space-2) var(--space-1) 0;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.list-form__group-hint strong {
    color: var(--color-text-1);
    font-weight: var(--font-weight-semibold);
}

.list-form__select-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
}

.list-form__select-count {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-qty);
}

.list-form__chip--small {
    padding: 0 var(--space-3);
}

.list-form__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
