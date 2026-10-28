<script setup>
/**
 * „In andere Liste übernehmen“ (Designpaket 07) und „Einkauf abschließen“.
 *
 * - Produkte an- und abwählen (alle vorgewählt), Ziel-Liste wählen: eine
 *   andere aktive Liste oder „Neue Liste …“ mit Namen. Beim Abschließen
 *   zusätzlich „Nicht übernehmen“ (vorgewählt: das Angebot ist freiwillig).
 * - Steht ein Produkt in der Zielliste schon mit anderer Einheit, fragt ein
 *   zweiter Schritt je Produkt: Behalten · Ersetzen · Beide Angaben (Menge
 *   der Zielliste bleibt, die übernommene kommt in die Notiz). Gleiche
 *   Einheit wird ohne Frage addiert.
 * - Übernommen wird über `takeOverEntries()`; im Ursprung bleiben die
 *   Produkte „nicht bekommen“ mit Verweis auf die Zielliste.
 *
 * v-model:open · mode: transfer | complete
 * Emits: finished({ result, targetName }) – `result` null, wenn nichts
 * übernommen wurde (nur abschließen)
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import SectionLabel from '@/components/ui/SectionLabel.vue';
import EntryChecklist from './EntryChecklist.vue';
import { ensureListLoaded, listEntries, loadLists, takeOverEntries, useLists } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { formatQuantity } from '@/utils/quantity.js';
import { formatShopDate } from '@/utils/shopDate.js';
import { planTakeOver } from '@/utils/takeOver.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 100;

const props = defineProps({
    list: {
        type: Object,
        required: true
    },
    entries: {
        type: Array,
        default: () => []
    },
    mode: {
        type: String,
        default: 'transfer',
        validator: (value) => ['transfer', 'complete'].includes(value)
    }
});

const emit = defineEmits(['finished']);
const open = defineModel('open', { type: Boolean, default: false });

const { activeLists } = useLists();
const { findProduct } = useProducts();

const ids = {
    form: useId(),
    target: useId(),
    newName: useId(),
    error: useId()
};

const step = ref('choose');
const selected = ref([]);
const target = ref('none');
const newName = ref('');
const answers = ref({});
const mismatches = ref([]);
const error = ref('');
const busy = ref(false);

const isComplete = computed(() => props.mode === 'complete');
const others = computed(() => activeLists.value.filter((item) => item.id !== props.list.id));
const targetName = computed(() => {
    if (target.value === 'new') {
        return newName.value.trim();
    }

    return others.value.find((item) => item.id === target.value)?.name ?? '';
});

const items = computed(() => props.entries.map((entry) => {
    const product = findProduct(entry.productId);

    return {
        id: entry.id,
        name: product?.name ?? t('entry.unknownProduct'),
        imageUrl: product?.imageUrl ?? null,
        icon: product ? iconOf(product) : undefined,
        category: categoryOf(product),
        note: entry.note,
        meta: entry.status === 'open' && isComplete.value ? t('entry.statusOpen') : '',
        quantity: entry.quantity
    };
}));

const title = computed(() => {
    if (step.value === 'units') {
        return t('transfer.unitsTitle', { list: targetName.value });
    }

    return isComplete.value ? t('complete.title') : t('transfer.title');
});

const subtitle = computed(() => (isComplete.value ? props.list.name : t('transfer.subtitle', { name: props.list.name })));

const submitLabel = computed(() => {
    if (!isComplete.value) {
        return t('transfer.submit');
    }

    return target.value === 'none' || props.entries.length === 0 ? t('complete.submit') : t('complete.submitWithTransfer');
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    step.value = 'choose';
    selected.value = props.entries.map((entry) => entry.id);
    target.value = isComplete.value ? 'none' : (others.value[0]?.id ?? 'new');
    newName.value = props.list.name ?? '';
    answers.value = {};
    mismatches.value = [];
    error.value = '';
    busy.value = false;
    preload(target.value);

    // andere Listen aktuell, auch wenn die Übersicht noch nicht geladen war
    loadLists()
        .then(() => {
            if (!isComplete.value && target.value === 'new' && others.value.length > 0) {
                target.value = others.value[0].id;
            }
        })
        .catch((loadError) => console.error(loadError));
});

watch(target, (value) => {
    error.value = '';
    preload(value);
});

/** Zielliste schon beim Wählen laden, damit „Übernehmen“ nicht wartet. */
function preload(listId) {
    if (listId && listId !== 'none' && listId !== 'new') {
        ensureListLoaded(listId).catch((loadError) => console.error(loadError));
    }
}

function listHint(item) {
    const parts = [];
    const date = formatShopDate(item.shopDate);

    if (date) {
        parts.push(date);
    }

    parts.push(t('transfer.count', { count: item.counts?.total ?? 0 }));

    return parts.join(' · ');
}

function quantityText(quantity) {
    return formatQuantity(quantity.amount, quantity.unit);
}

function chosenEntries() {
    const chosen = new Set(selected.value);

    return props.entries.filter((entry) => chosen.has(entry.id));
}

function nameOf(entry) {
    return findProduct(entry.productId)?.name ?? t('entry.unknownProduct');
}

async function submit() {
    if (busy.value) {
        return;
    }

    error.value = '';

    if (isComplete.value && (target.value === 'none' || props.entries.length === 0)) {
        emit('finished', { result: null, targetName: '' });
        open.value = false;
        return;
    }

    const chosen = chosenEntries();

    if (chosen.length === 0) {
        error.value = t('transfer.nothingSelected');
        return;
    }

    if (target.value === 'new') {
        const name = newName.value.trim();

        if (name === '' || name.length > NAME_MAX) {
            error.value = name === '' ? t('listForm.nameRequired') : t('listForm.nameTooLong', { count: NAME_MAX });
            return;
        }
    }

    busy.value = true;

    try {
        if (target.value !== 'new' && step.value === 'choose') {
            try {
                await ensureListLoaded(target.value);
            } catch (loadError) {
                console.error(loadError);
                error.value = t('transfer.loadError');
                return;
            }

            const found = planTakeOver(listEntries(target.value), chosen).filter((item) => item.kind === 'mismatch');

            if (found.length > 0) {
                mismatches.value = found;
                answers.value = Object.fromEntries(found.map((item) => [item.entry.id, 'note']));
                step.value = 'units';
                return;
            }
        }

        const result = await takeOverEntries(chosen, target.value === 'new'
            // neue Liste gehört dorthin, wohin die Ursprungsliste gehört
            ? { newList: { name: newName.value.trim(), shopDate: null, householdId: props.list.householdId ?? null } }
            : { targetListId: target.value, answers: answers.value });

        emit('finished', { result, targetName: targetName.value });
        open.value = false;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppSheet v-model:open="open" :title="title" :subtitle="step === 'units' ? '' : subtitle">
        <form :id="ids.form" class="transfer" novalidate @submit.prevent="submit">
            <template v-if="step === 'choose'">
                <p v-if="isComplete" class="transfer__text">
                    <template v-if="entries.length === 0">{{ t('complete.allDone') }}</template>
                    {{ t('complete.text') }}
                </p>

                <template v-if="entries.length > 0">
                    <SectionLabel v-if="isComplete" :label="t('complete.offer')" :level="3" />
                    <SectionLabel v-else :label="t('transfer.products')" :count="entries.length" :level="3" />
                    <EntryChecklist v-model:selected="selected" :items="items" :label="t('transfer.products')" />

                    <fieldset class="choice-list">
                        <legend class="transfer__legend">{{ t('transfer.target') }}</legend>

                        <label v-if="isComplete" class="choice">
                            <input v-model="target" class="choice__radio" type="radio" :name="ids.target" value="none">
                            <span class="choice__text">
                                <span class="choice__title">{{ t('transfer.none') }}</span>
                                <span class="choice__hint">{{ t('transfer.noneHint') }}</span>
                            </span>
                        </label>

                        <label v-for="item in others" :key="item.id" class="choice">
                            <input v-model="target" class="choice__radio" type="radio" :name="ids.target" :value="item.id">
                            <span class="choice__text">
                                <span class="choice__title">{{ item.name }}</span>
                                <span class="choice__hint">{{ listHint(item) }}</span>
                            </span>
                        </label>

                        <label class="choice">
                            <input v-model="target" class="choice__radio" type="radio" :name="ids.target" value="new">
                            <span class="choice__text">
                                <span class="choice__title">{{ t('transfer.newList') }}</span>
                                <span class="choice__hint">{{ t('transfer.newListHint', { name: list.name }) }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <label v-if="target === 'new'" class="form-field" :for="ids.newName">
                        <span class="form-field__label">{{ t('transfer.newListName') }}</span>
                        <input
                            :id="ids.newName"
                            v-model="newName"
                            class="form-field__input"
                            type="text"
                            :maxlength="NAME_MAX"
                            autocomplete="off"
                            enterkeyhint="done"
                        >
                    </label>

                    <p v-if="target !== 'none'" class="transfer__hint">{{ t('transfer.hint', { name: list.name }) }}</p>
                </template>
            </template>

            <template v-else>
                <p class="transfer__text">{{ t('transfer.unitsIntro', { list: targetName }) }}</p>
                <div v-for="item in mismatches" :key="item.entry.id" class="transfer__unit">
                    <p class="transfer__unit-name">{{ nameOf(item.entry) }}</p>
                    <p class="transfer__unit-hint">
                        {{ t('transfer.unitsItem', {
                            list: targetName,
                            existing: quantityText(item.existing.quantity),
                            incoming: quantityText(item.entry.quantity)
                        }) }}
                    </p>
                    <fieldset class="segments">
                        <legend class="visually-hidden">{{ nameOf(item.entry) }}</legend>
                        <label v-for="option in ['keep', 'replace', 'note']" :key="option" class="segments__option">
                            <input v-model="answers[item.entry.id]" type="radio" :name="ids.target + item.entry.id" :value="option">
                            {{ t('transfer.' + option) }}
                        </label>
                    </fieldset>
                    <p class="transfer__unit-result">
                        <template v-if="answers[item.entry.id] === 'keep'">{{ quantityText(item.existing.quantity) }}</template>
                        <template v-else-if="answers[item.entry.id] === 'replace'">{{ quantityText(item.entry.quantity) }}</template>
                        <template v-else>
                            {{ quantityText(item.existing.quantity) }} · {{ t('transfer.noteHint') }} „+ {{ quantityText(item.entry.quantity) }}“
                        </template>
                    </p>
                </div>
            </template>

            <p v-if="error" :id="ids.error" class="form-field__error" role="alert">
                <CircleAlert aria-hidden="true" />
                {{ error }}
            </p>
        </form>

        <template #footer>
            <AppButton v-if="step === 'units'" variant="secondary" @click="step = 'choose'">{{ t('common.back') }}</AppButton>
            <AppButton v-else variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
            <AppButton type="submit" :form="ids.form" :aria-disabled="busy ? 'true' : undefined">{{ submitLabel }}</AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.transfer {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.transfer__text {
    font-size: var(--font-size-name);
    line-height: var(--line-height-text);
}

.transfer__legend {
    margin-bottom: var(--space-2);
    padding: 0;
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--letter-spacing-label);
    line-height: var(--line-height-label);
    text-transform: uppercase;
}

.transfer__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.transfer__unit {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.transfer__unit-name {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-name);
}

.transfer__unit-hint,
.transfer__unit-result {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.transfer__unit .segments {
    background: var(--color-surface-3);
}
</style>
