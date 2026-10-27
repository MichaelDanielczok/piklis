<script setup>
/**
 * Sheet „Menge und Notiz“ eines Listeneintrags: Menge mit Minus/Plus und
 * Eingabe (Komma bei kg und l), Einheit aus der festen Liste, Einkaufsnotiz
 * (nur für diesen Einkauf, höchstens 500 Zeichen). In der Liste ist 1 das
 * Minimum; Entfernen ist eine eigene Aktion.
 *
 * Schritte: g und ml in 50ern, kg und l in halben, sonst ganze.
 *
 * v-model:open · entry (angezeigter Stand) · productName · listName
 * Emits: save({ quantity?, note? }) nur mit geänderten Feldern
 */
import { computed, ref, useId, watch } from 'vue';
import { CircleAlert, Minus, Plus } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import { allowsDecimals, formatAmount, parseQuantity, sameQuantity, UNITS, unitName } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const NOTE_MAX = 500;

const props = defineProps({
    entry: {
        type: Object,
        default: null
    },
    productName: {
        type: String,
        default: ''
    },
    listName: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['save']);
const open = defineModel('open', { type: Boolean, default: false });

const ids = {
    form: useId(),
    amount: useId(),
    amountError: useId(),
    unit: useId(),
    note: useId(),
    noteHint: useId()
};

const amountText = ref('');
const unit = ref(null);
const note = ref('');
const submitted = ref(false);

watch(open, (isOpen) => {
    if (isOpen && props.entry) {
        amountText.value = formatAmount(props.entry.quantity.amount);
        unit.value = props.entry.quantity.unit ?? null;
        note.value = props.entry.note ?? '';
        submitted.value = false;
    }
});

const parsed = computed(() => parseQuantity(amountText.value, unit.value));

const amountError = computed(() => {
    if (!submitted.value && parsed.value.error === 'empty') {
        return '';
    }

    return parsed.value.error ? t('entry.errors.' + parsed.value.error) : '';
});

const step = computed(() => {
    if (unit.value === 'g' || unit.value === 'ml') {
        return 50;
    }

    return allowsDecimals(unit.value) ? 0.5 : 1;
});

const currentAmount = computed(() => parsed.value.amount ?? 0);
const canDecrease = computed(() => currentAmount.value - step.value > 0);

function changeBy(direction) {
    const next = Math.round((currentAmount.value + direction * step.value) * 1000) / 1000;

    if (next > 0) {
        amountText.value = formatAmount(next);
    }
}

function onUnitChange(event) {
    unit.value = event.target.value || null;

    // ganze Zahl, wenn die neue Einheit keine Dezimalen erlaubt
    if (!allowsDecimals(unit.value) && parsed.value.error === 'not_integer') {
        const amount = parseQuantity(amountText.value, 'kg').amount;

        if (amount !== null) {
            amountText.value = formatAmount(Math.max(1, Math.round(amount)));
        }
    }
}

function submit() {
    submitted.value = true;

    if (parsed.value.error || !props.entry) {
        return;
    }

    const changes = {};
    const quantity = { amount: parsed.value.amount, unit: unit.value };

    if (!sameQuantity(quantity, props.entry.quantity)) {
        changes.quantity = quantity;
    }

    const trimmed = note.value.trim();

    if (trimmed !== (props.entry.note ?? '')) {
        changes.note = trimmed;
    }

    if (Object.keys(changes).length > 0) {
        emit('save', changes);
    }

    open.value = false;
}
</script>

<template>
    <AppSheet
        v-model:open="open"
        :title="productName"
        :subtitle="listName ? t('entry.sheetSubtitle', { name: listName }) : ''"
    >
        <form :id="ids.form" class="entry-form" novalidate @submit.prevent="submit">
            <div class="form-field">
                <label class="form-field__label" :for="ids.amount">{{ t('entry.amount') }}</label>
                <div class="entry-form__quantity">
                    <button
                        type="button"
                        class="entry-form__step"
                        :aria-label="t('entry.decrease')"
                        :disabled="!canDecrease"
                        @click="changeBy(-1)"
                    >
                        <Minus aria-hidden="true" />
                    </button>
                    <input
                        :id="ids.amount"
                        v-model="amountText"
                        class="form-field__input entry-form__amount"
                        type="text"
                        :inputmode="allowsDecimals(unit) ? 'decimal' : 'numeric'"
                        autocomplete="off"
                        :aria-invalid="amountError ? 'true' : 'false'"
                        :aria-describedby="amountError ? ids.amountError : undefined"
                    >
                    <button
                        type="button"
                        class="entry-form__step entry-form__step--plus"
                        :aria-label="t('entry.increase')"
                        @click="changeBy(1)"
                    >
                        <Plus aria-hidden="true" />
                    </button>
                    <label class="visually-hidden" :for="ids.unit">{{ t('entry.unit') }}</label>
                    <select
                        :id="ids.unit"
                        class="form-field__input entry-form__unit"
                        :value="unit ?? ''"
                        @change="onUnitChange"
                    >
                        <option value="">{{ t('entry.noUnit') }}</option>
                        <option v-for="key in UNITS" :key="key" :value="key">{{ unitName(key) }}</option>
                    </select>
                </div>
                <span v-if="amountError" :id="ids.amountError" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ amountError }}
                </span>
            </div>

            <label class="form-field" :for="ids.note">
                <span class="form-field__label">{{ t('entry.note') }}</span>
                <input
                    :id="ids.note"
                    v-model="note"
                    class="form-field__input"
                    type="text"
                    :maxlength="NOTE_MAX"
                    :placeholder="t('entry.notePlaceholder')"
                    autocomplete="off"
                    enterkeyhint="done"
                    :aria-describedby="ids.noteHint"
                >
                <span :id="ids.noteHint" class="form-field__hint">{{ t('entry.noteHint') }}</span>
            </label>
        </form>

        <template #footer>
            <AppButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</AppButton>
            <AppButton type="submit" :form="ids.form">{{ t('common.save') }}</AppButton>
        </template>
    </AppSheet>
</template>

<style scoped>
.entry-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.entry-form__quantity {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

.entry-form__step {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border: 2px solid var(--color-accent);
    border-radius: 50%;
    color: var(--color-accent-text);
    transition: transform var(--duration-fast) var(--ease-out);
}

.entry-form__step--plus {
    background: var(--color-accent);
    color: var(--color-on-accent);
}

.entry-form__step:active:not(:disabled) {
    transform: scale(0.92);
}

.entry-form__step:disabled {
    border-color: var(--color-border);
    color: var(--color-text-3);
    cursor: default;
}

.entry-form__step :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
    stroke-width: 2.5;
}

.entry-form__amount {
    flex: none;
    width: 5rem;
    padding: 0 var(--space-2);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    text-align: center;
}

.entry-form__unit {
    flex: 1;
    min-width: 0;
}
</style>
