<script setup>
/**
 * Sheet „Als Vorlage speichern“ (Konzept „Vorlagen“): eine Liste
 * ausdrücklich als benannte Vorlage speichern.
 *
 * 1. Name (vorgeschlagen: Name der Liste), mit Haushalt „Für wen?“
 *    (Haushalt voreingestellt) und, falls die Liste Einkaufsnotizen hat,
 *    „Einkaufsnotizen mitspeichern“ (aus: Notizen gelten nur für einen
 *    Einkauf).
 * 2. Produkte prüfen: alle Produkte der Liste in ihrer Reihenfolge mit
 *    Mengen, je Produkt einmal, auch abgehakte und nicht bekommene; alle
 *    vorgewählt und abwählbar.
 *
 * Gespeichert wird online (`POST /templates`); ohne Verbindung bleibt das
 * Sheet mit einer Meldung offen.
 *
 * v-model:open · `list` (angezeigter Stand mit `id`, `name`, `householdId`)
 * Emits: saved(template)
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert, House, User } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import EntryChecklist from '@/components/lists/EntryChecklist.vue';
import { listEntries } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { useSession } from '@/composables/useSession.js';
import { createTemplate } from '@/composables/useTemplates.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { templatePlan } from '@/utils/templates.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 100;

const props = defineProps({
    list: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['saved']);
const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    form: useId(),
    name: useId(),
    nameError: useId(),
    owner: useId(),
    notes: useId(),
    notesHint: useId()
};

const { findProduct } = useProducts();
const { household } = useSession();

const nameInput = ref(null);
const step = ref('form');
const name = ref('');
const owner = ref('household');
const withNotes = ref(false);
const submitted = ref(false);
const selected = ref([]);
const error = ref('');
const pending = ref(false);

/** Produkte der Liste beim Öffnen (danach unverändert, auch wenn andere ergänzen). */
const plan = ref([]);

const items = computed(() => plan.value.map((item) => {
    const product = findProduct(item.productId);
    let meta = '';

    if (item.status === 'done') {
        meta = t('templateSave.statusDone');
    } else if (item.status === 'notGot') {
        meta = t('templateSave.statusNotGot');
    }

    return {
        ...item,
        id: item.productId,
        name: product?.name ?? t('entry.unknownProduct'),
        imageUrl: product?.imageUrl ?? null,
        icon: product ? iconOf(product) : undefined,
        category: categoryOf(product),
        meta: withNotes.value && item.note ? '' : meta,
        note: withNotes.value ? item.note : ''
    };
}));

const hasNotes = computed(() => plan.value.some((item) => item.note));

const nameError = computed(() => {
    if (!submitted.value) {
        return '';
    }

    const trimmed = name.value.trim();

    if (trimmed === '') {
        return t('templateForm.nameRequired');
    }

    return trimmed.length > NAME_MAX ? t('listForm.nameTooLong', { count: NAME_MAX }) : '';
});

const title = computed(() => (step.value === 'products' ? t('templateSave.chooseProducts') : t('templateSave.title')));
const subtitle = computed(() => (step.value === 'products' ? t('templateSave.chooseSubtitle', { name: name.value.trim() }) : ''));

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    plan.value = templatePlan(listEntries(props.list.id));
    selected.value = plan.value.map((item) => item.productId);
    name.value = props.list.name ?? '';
    owner.value = household.value ? 'household' : 'personal';
    withNotes.value = false;
    submitted.value = false;
    step.value = 'form';
    error.value = '';
    pending.value = false;
});

function focusName() {
    nameInput.value?.select();
}

function selectAll() {
    selected.value = plan.value.map((item) => item.productId);
}

function selectNone() {
    selected.value = [];
}

function errorText(failure) {
    if (failure?.status === 0) {
        return t('templateSave.offline');
    }

    return t('templateSave.error');
}

async function submit() {
    submitted.value = true;

    if (nameError.value) {
        nameInput.value?.focus();
        return;
    }

    if (step.value === 'form') {
        step.value = 'products';
        return;
    }

    if (pending.value) {
        return;
    }

    const chosen = new Set(selected.value);
    const householdId = household.value && owner.value === 'household' ? household.value.id : null;

    pending.value = true;
    error.value = '';

    try {
        const template = await createTemplate({
            name: name.value.trim(),
            householdId,
            items: plan.value.filter((item) => chosen.has(item.productId)),
            withNotes: withNotes.value
        });

        open.value = false;
        emit('saved', template);
    } catch (failure) {
        console.error(failure);
        error.value = errorText(failure);
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <AppSheet v-model:open="open" :title="title" :subtitle="subtitle" @presented="focusName">
        <form v-if="step === 'products'" :id="ids.form" class="template-save" novalidate @submit.prevent="submit">
            <div class="template-save__select-bar">
                <span class="template-save__count" aria-live="polite">
                    {{ t('listForm.selectedCount', { count: selected.length, total: items.length }) }}
                </span>
                <span class="template-save__chips">
                    <button type="button" class="template-save__chip" @click="selectAll">{{ t('listForm.selectAll') }}</button>
                    <button type="button" class="template-save__chip" @click="selectNone">{{ t('templateSave.selectNone') }}</button>
                </span>
            </div>
            <p v-if="items.length === 0" class="template-save__hint">{{ t('templateSave.emptyList') }}</p>
            <EntryChecklist v-else v-model:selected="selected" :items="items" :label="t('templateSave.chooseProducts')" />
            <p class="template-save__hint">{{ t('templateSave.productsHint') }}</p>
        </form>

        <form v-else :id="ids.form" class="template-save" novalidate @submit.prevent="submit">
            <label class="form-field" :for="ids.name">
                <span class="form-field__label">{{ t('templateForm.name') }}</span>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="name"
                    class="form-field__input"
                    type="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('templateForm.namePlaceholder')"
                    autocomplete="off"
                    enterkeyhint="next"
                    :aria-invalid="nameError ? 'true' : 'false'"
                    :aria-describedby="nameError ? ids.nameError : undefined"
                >
                <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ nameError }}
                </span>
            </label>

            <fieldset v-if="household" class="choice-list">
                <legend class="form-field__label template-save__legend">{{ t('listForm.owner') }}</legend>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="household">
                    <House class="template-save__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerHousehold', { name: household.name }) }}</span>
                        <span class="choice__hint">{{ t('templateForm.ownerHouseholdHint') }}</span>
                    </span>
                </label>
                <label class="choice">
                    <input v-model="owner" class="choice__radio" type="radio" :name="ids.owner" value="personal">
                    <User class="template-save__owner-icon" aria-hidden="true" />
                    <span class="choice__text">
                        <span class="choice__title">{{ t('listForm.ownerPersonal') }}</span>
                        <span class="choice__hint">{{ t('listForm.ownerPersonalHint') }}</span>
                    </span>
                </label>
            </fieldset>

            <label v-if="hasNotes" class="choice template-save__notes" :for="ids.notes">
                <input
                    :id="ids.notes"
                    v-model="withNotes"
                    class="template-save__check"
                    type="checkbox"
                    :aria-describedby="ids.notesHint"
                >
                <span class="choice__text">
                    <span class="choice__title">{{ t('templateSave.withNotes') }}</span>
                    <span :id="ids.notesHint" class="choice__hint">{{ t('templateSave.withNotesHint') }}</span>
                </span>
            </label>

            <p class="template-save__hint">{{ t('templateSave.hint', { count: plan.length }) }}</p>
        </form>

        <p v-if="error" class="form-field__error template-save__error" role="alert">
            <CircleAlert aria-hidden="true" />
            {{ error }}
        </p>

        <template #footer>
            <AppButton v-if="step === 'products'" variant="secondary" @click="step = 'form'">{{ t('common.back') }}</AppButton>
            <AppButton v-else variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
            <AppButton type="submit" :form="ids.form" :disabled="pending || (step === 'products' && selected.length === 0 && items.length > 0)">
                {{ step === 'products' ? t('templateSave.save') : t('common.next') }}
            </AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.template-save {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.template-save__select-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: calc(-1 * var(--space-2));
}

.template-save__count {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    font-variant-numeric: tabular-nums;
    line-height: var(--line-height-qty);
}

.template-save__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.template-save__chip {
    min-height: var(--tap-size);
    padding: 0 var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-pill);
    background: var(--color-surface-1);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-medium);
    transition: background-color var(--duration-fast) var(--ease-out);
}

@media (hover: hover) {
    .template-save__chip:hover {
        background: var(--color-surface-2);
    }
}

.template-save__legend {
    margin-bottom: var(--space-2);
    padding: 0;
}

.template-save__owner-icon {
    flex: none;
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    color: var(--color-text-2);
}

/* Kästchen wie in der Produktauswahl */
.template-save__check {
    display: grid;
    flex: none;
    place-content: center;
    width: var(--space-6);
    height: var(--space-6);
    margin: 0;
    border: 2px solid var(--color-ring);
    border-radius: var(--radius-xs);
    background: var(--color-surface-1);
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}

.template-save__check:checked {
    border-color: var(--color-accent);
    background: var(--color-accent);
}

.template-save__check:checked::after {
    content: "";
    width: 0.375rem;
    height: 0.75rem;
    margin-top: -0.125rem;
    border: solid var(--color-on-accent);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.template-save__check:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
}

.template-save__notes:has(.template-save__check:checked) {
    border-color: var(--color-accent);
    background: var(--color-accent-tint);
    box-shadow: inset 0 0 0 1px var(--color-accent);
}

.template-save__error {
    margin-top: var(--space-3);
}

.template-save__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}
</style>
