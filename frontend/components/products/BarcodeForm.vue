<script setup>
/**
 * Barcode von Hand eingeben: Nummernfeld mit Hinweis und Prüfziffer-Check
 * (`utils/barcode.js`). Der Absende-Knopf steht meist im Sheet-Fuß und zeigt
 * über `form` auf die `id` dieses Formulars. Fehler erscheinen erst nach dem
 * ersten Absenden.
 *
 * v-model (Eingabe) · Props: id · Slot: default (unter dem Feld)
 * Emits: search(barcode) · Exponiert `focus()`, `showError(reason)`
 */
import { ref, useId, watch } from 'vue';
import { CircleAlert } from '@lucide/vue';
import { parseBarcode } from '@/utils/barcode.js';
import { t } from '@/i18n/index.js';

defineProps({
    id: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['search']);
const value = defineModel({ type: String, default: '' });

const ids = {
    barcode: useId(),
    hint: useId(),
    error: useId()
};

const field = ref(null);
const error = ref('');
const submitted = ref(false);

function validate() {
    const parsed = parseBarcode(value.value);

    error.value = parsed.error ? t('foodFacts.errors.' + parsed.error) : '';

    return parsed.barcode;
}

watch(value, () => {
    if (submitted.value) {
        validate();
    }
});

function focus() {
    field.value?.focus();
}

function submit() {
    submitted.value = true;

    const barcode = validate();

    if (barcode) {
        emit('search', barcode);
    } else {
        focus();
    }
}

/** Fehler, den erst der Server erkannt hat. */
function showError(reason) {
    submitted.value = true;
    error.value = t('foodFacts.errors.' + reason);
    focus();
}

defineExpose({ focus, showError });
</script>

<template>
    <form :id="id" class="barcode-form" novalidate @submit.prevent="submit">
        <div class="form-field">
            <label :for="ids.barcode" class="form-field__label">{{ t('foodFacts.barcode') }}</label>
            <input
                :id="ids.barcode"
                ref="field"
                v-model="value"
                class="form-field__input barcode-form__input"
                type="text"
                name="barcode"
                inputmode="numeric"
                autocomplete="off"
                enterkeyhint="search"
                maxlength="20"
                :aria-invalid="error ? 'true' : 'false'"
                :aria-describedby="error ? ids.error : ids.hint"
            >
            <span v-if="error" :id="ids.error" class="form-field__error">
                <CircleAlert aria-hidden="true" />
                {{ error }}
            </span>
            <span v-else :id="ids.hint" class="form-field__hint">{{ t('foodFacts.barcodeHint') }}</span>
        </div>

        <slot />
    </form>
</template>

<style scoped>
.barcode-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.barcode-form__input {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.04em;
}
</style>
