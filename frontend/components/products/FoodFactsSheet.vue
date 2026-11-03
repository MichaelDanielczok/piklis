<script setup>
/**
 * „Mit Open Food Facts ergänzen“ (Obsidian „Piklis Konzept“ › „Open Food
 * Facts“): Barcode eingeben oder scannen, Treffer prüfen, gewünschte
 * Angaben einzeln übernehmen.
 *
 * - Nachschlagen und Übernehmen über den eigenen Server (Cache, App-Kennung);
 *   ohne Verbindung eine klare Meldung statt der Eingabe.
 * - Vorauswahl (`utils/foodFacts.js`): nur, was am Produkt fehlt oder gleich
 *   ist, dazu Zutaten und Nährwerte. Eigener Name und eigenes Foto werden
 *   nie ungefragt ersetzt; die Beschreibung (eigene Hinweise) gar nicht.
 *   Was Open Food Facts nicht kennt, steht als Hinweis da und bleibt leer.
 * - Bild: nach dem Übernehmen der Daten das große Bild über den Server
 *   laden, mittig auf eine quadratische Fläche setzen und im normalen
 *   Zuschnitt (`ImageCropDialog`) zuschneiden; hochgeladen wird es wie ein
 *   eigenes Foto, markiert als `openfoodfacts` (Lizenzhinweis).
 *
 * v-model:open · Props: product, mode (`add` · `refresh`: mit dem Barcode
 * des Produkts sofort neu abrufen)
 */
import { computed, nextTick, ref, useId, watch } from 'vue';
import { CircleAlert, ImageOff, ScanBarcode, SearchX, WifiOff } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import BarcodeScanner from './BarcodeScanner.vue';
import ImageCropDialog from './ImageCropDialog.vue';
import ProductImage from './ProductImage.vue';
import { api } from '@/api/index.js';
import { loadConfig, useConfig } from '@/composables/useConfig.js';
import { useConnection } from '@/composables/useConnection.js';
import { applyFoodFacts, setProductImage } from '@/composables/useProducts.js';
import { showToast } from '@/composables/useToast.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { parseBarcode } from '@/utils/barcode.js';
import {
    defaultSelection,
    fieldEffect,
    FOOD_FACTS_FIELDS,
    missingFields,
    nutrientFormat,
    nutritionSummary
} from '@/utils/foodFacts.js';
import { padToSquare } from '@/utils/imageEncode.js';
import { formatNumber, t } from '@/i18n/index.js';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    mode: {
        type: String,
        default: 'add',
        validator: (value) => ['add', 'refresh'].includes(value)
    }
});

const open = defineModel('open', { type: Boolean, default: false });

const { online } = useConnection();
const { images: limits } = useConfig();

const ids = {
    form: useId(),
    barcode: useId(),
    hint: useId(),
    error: useId(),
    choices: useId()
};

const canScan = typeof navigator !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function';

const step = ref('input'); // input · scan · loading · result · notFound · error
const input = ref('');
const inputError = ref('');
const submitted = ref(false);
const errorMessage = ref('');
const result = ref(null);
const selected = ref([]);
const applying = ref(false);
const barcodeInput = ref(null);
const previewFailed = ref(false);

const cropOpen = ref(false);
const cropSource = ref(null);
let cropApplied = false;

const title = computed(() => t(props.mode === 'refresh' ? 'foodFacts.refreshTitle' : 'foodFacts.title'));

const subtitle = computed(() => {
    if (step.value === 'result' || step.value === 'loading') {
        return result.value?.barcode ?? input.value;
    }

    return t('foodFacts.subtitle');
});

const offered = computed(() => FOOD_FACTS_FIELDS.filter((field) => result.value?.fields.includes(field)));
const missing = computed(() => missingFields(result.value)
    .map((field) => t('foodFacts.fields.' + field))
    .join(', '));

const previewUrl = computed(() => (result.value?.hasImage ? api.foodFactsImageUrl(result.value.barcode) : null));

function reset() {
    step.value = 'input';
    inputError.value = '';
    submitted.value = false;
    errorMessage.value = '';
    result.value = null;
    selected.value = [];
    previewFailed.value = false;
}

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    reset();
    loadConfig();
    input.value = props.product.barcode ?? '';

    if (props.mode === 'refresh' && props.product.barcode && online.value) {
        lookup(props.product.barcode, true);
    }
});

function focusInput() {
    if (step.value === 'input') {
        barcodeInput.value?.focus();
    }
}

function validate() {
    const { barcode, error } = parseBarcode(input.value);

    inputError.value = error ? t('foodFacts.errors.' + error) : '';

    return barcode;
}

watch(input, () => {
    if (submitted.value) {
        validate();
    }
});

function search() {
    submitted.value = true;

    const barcode = validate();

    if (!barcode) {
        barcodeInput.value?.focus();
        return;
    }

    lookup(barcode, false);
}

function messageFor(error) {
    if (error?.status === 0) {
        return t('foodFacts.errors.offline');
    }

    if (error?.code === 'off_busy') {
        return t('foodFacts.errors.busy');
    }

    if (error?.code === 'off_unavailable') {
        return t('foodFacts.errors.unavailable');
    }

    if (error?.status === 403) {
        return t('products.forbidden');
    }

    return t('common.error');
}

async function lookup(barcode, refresh) {
    input.value = barcode;
    step.value = 'loading';
    previewFailed.value = false;

    try {
        const data = await api.lookupFoodFacts(barcode, { refresh });

        result.value = data.result;
        selected.value = defaultSelection(props.product, data.result);
        step.value = 'result';
    } catch (error) {
        if (error?.code === 'invalid_barcode') {
            step.value = 'input';
            submitted.value = true;
            inputError.value = t('foodFacts.errors.' + (error.reason ?? 'invalid'));
            await nextTick();
            barcodeInput.value?.focus();
            return;
        }

        if (error?.code === 'off_not_found') {
            step.value = 'notFound';
            return;
        }

        errorMessage.value = messageFor(error);
        step.value = 'error';
    }
}

function onDetected(barcode) {
    lookup(barcode, props.mode === 'refresh');
}

function toggle(field, checked) {
    const current = new Set(selected.value);

    if (checked) {
        current.add(field);
    } else {
        current.delete(field);
    }

    selected.value = FOOD_FACTS_FIELDS.filter((item) => current.has(item));
}

function valueOf(field) {
    const hit = result.value;

    if (field === 'nutrition') {
        const summary = nutritionSummary(hit.nutrition);

        if (!summary) {
            return t('foodFacts.nutritionRows', { count: hit.nutrition.rows.length });
        }

        const value = formatNumber(summary.value, nutrientFormat(summary.value, summary.unit)) + ' ' + summary.unit;

        return t('foodFacts.nutritionSummary', { value, per: hit.nutrition.per });
    }

    if (field === 'image') {
        return '';
    }

    return hit[field];
}

function effectOf(field) {
    const effect = fieldEffect(props.product, result.value, field);

    if (effect !== 'replace') {
        return field === 'image' ? t('foodFacts.effect.imageHint') : t('foodFacts.effect.' + effect);
    }

    if (field === 'image') {
        return t(props.product.imageSource === 'openfoodfacts' ? 'foodFacts.effect.replaceOffImage' : 'foodFacts.effect.replaceImage');
    }

    if (field === 'ingredients' || field === 'nutrition') {
        return t('foodFacts.effect.replaceData');
    }

    return t('foodFacts.effect.replace', { value: props.product[field] });
}

async function apply() {
    if (selected.value.length === 0 || applying.value) {
        return;
    }

    applying.value = true;
    errorMessage.value = '';

    const fields = [...selected.value];
    const barcode = result.value.barcode;

    try {
        await applyFoodFacts(props.product.id, { barcode, fields });
    } catch (error) {
        applying.value = false;
        errorMessage.value = messageFor(error);
        return;
    }

    if (!fields.includes('image')) {
        applying.value = false;
        open.value = false;
        showToast(t('foodFacts.appliedToast'), { icon: 'done' });
        return;
    }

    try {
        const blob = await api.getFoodFactsImage(barcode, { full: true });

        cropSource.value = await padToSquare(blob, limits.value.maxPixels);
        cropApplied = false;
        open.value = false;
        cropOpen.value = true;
    } catch (error) {
        console.error(error);
        open.value = false;
        showToast(t('foodFacts.imageFailedToast'), { icon: 'notgot' });
    } finally {
        applying.value = false;
    }
}

async function onCropped({ image, original, preview }) {
    cropApplied = true;
    URL.revokeObjectURL(preview);

    try {
        await setProductImage(props.product.id, { image, original, source: 'openfoodfacts' });
        showToast(t('foodFacts.appliedToast'), { icon: 'done' });
    } catch (error) {
        console.error(error);
        showToast(t('foodFacts.imageFailedToast'), { icon: 'notgot' });
    }
}

function onCropError() {
    cropApplied = true;
    cropOpen.value = false;
    showToast(t('foodFacts.imageFailedToast'), { icon: 'notgot' });
}

watch(cropOpen, (isOpen) => {
    if (!isOpen) {
        if (!cropApplied) {
            showToast(t('foodFacts.imageSkippedToast'), { icon: 'info' });
        }

        cropApplied = true;
        cropSource.value = null;
    }
});
</script>

<template>
    <AppSheet v-model:open="open" :title="title" :subtitle="subtitle" @presented="focusInput">
        <div v-if="!online" class="off-message" role="status">
            <WifiOff class="off-message__icon" aria-hidden="true" />
            <p>{{ t('foodFacts.errors.offline') }}</p>
        </div>

        <form
            v-else-if="step === 'input'"
            :id="ids.form"
            class="off-input"
            novalidate
            @submit.prevent="search"
        >
            <div class="form-field">
                <label :for="ids.barcode" class="form-field__label">{{ t('foodFacts.barcode') }}</label>
                <input
                    :id="ids.barcode"
                    ref="barcodeInput"
                    v-model="input"
                    class="form-field__input off-input__barcode"
                    type="text"
                    name="barcode"
                    inputmode="numeric"
                    autocomplete="off"
                    enterkeyhint="search"
                    maxlength="20"
                    :aria-invalid="inputError ? 'true' : 'false'"
                    :aria-describedby="inputError ? ids.error : ids.hint"
                >
                <span v-if="inputError" :id="ids.error" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ inputError }}
                </span>
                <span v-else :id="ids.hint" class="form-field__hint">{{ t('foodFacts.barcodeHint') }}</span>
            </div>

            <AppButton v-if="canScan" variant="secondary" block @click="step = 'scan'">
                <ScanBarcode aria-hidden="true" />
                {{ t('foodFacts.scan') }}
            </AppButton>
        </form>

        <BarcodeScanner v-else-if="step === 'scan'" @detected="onDetected" />

        <div v-else-if="step === 'loading'" class="off-skeleton" aria-busy="true">
            <span class="visually-hidden" role="status">{{ t('foodFacts.searching') }}</span>
            <div class="off-hit" aria-hidden="true">
                <span class="off-skeleton__image"></span>
                <span class="off-hit__text">
                    <span class="off-skeleton__line off-skeleton__line--title"></span>
                    <span class="off-skeleton__line"></span>
                </span>
            </div>
            <!-- wie ein vollständiger Treffer: Überschrift und sechs Felder -->
            <span class="off-skeleton__choices" aria-hidden="true">
                <span v-for="index in 6" :key="index" class="off-skeleton__row"></span>
            </span>
            <span class="off-skeleton__line off-skeleton__line--note" aria-hidden="true"></span>
        </div>

        <div v-else-if="step === 'result'" class="off-result">
            <section class="off-hit" :aria-label="t('foodFacts.hitLabel')">
                <span v-if="previewUrl && !previewFailed" class="off-hit__image">
                    <img :src="previewUrl" alt="" decoding="async" @error="previewFailed = true">
                </span>
                <ProductImage
                    v-else
                    :icon="iconOf(product)"
                    :category="categoryOf(product)"
                    size="lg"
                />
                <span class="off-hit__text">
                    <span class="off-hit__name">{{ result.name ?? t('foodFacts.noName') }}</span>
                    <span v-if="result.brand || result.packageSize" class="off-hit__meta">
                        {{ [result.brand, result.packageSize].filter(Boolean).join(' · ') }}
                    </span>
                </span>
            </section>

            <fieldset v-if="offered.length > 0" class="off-choices">
                <legend :id="ids.choices" class="off-choices__legend">{{ t('foodFacts.choose') }}</legend>
                <label v-for="field in offered" :key="field" class="off-choice">
                    <input
                        class="off-choice__box"
                        type="checkbox"
                        :checked="selected.includes(field)"
                        @change="toggle(field, $event.target.checked)"
                    >
                    <span class="off-choice__text">
                        <span class="off-choice__title">{{ t('foodFacts.fields.' + field) }}</span>
                        <span
                            v-if="valueOf(field)"
                            class="off-choice__value"
                            :lang="field === 'ingredients' ? result.language : undefined"
                        >{{ valueOf(field) }}</span>
                        <span
                            class="off-choice__effect"
                            :class="{ 'off-choice__effect--replace': fieldEffect(product, result, field) === 'replace' }"
                        >{{ effectOf(field) }}</span>
                    </span>
                    <span v-if="field === 'image'" class="off-choice__thumb" aria-hidden="true">
                        <img v-if="previewUrl && !previewFailed" :src="previewUrl" alt="">
                        <ImageOff v-else />
                    </span>
                </label>
            </fieldset>

            <p v-if="missing" class="off-note">{{ t('foodFacts.missing', { fields: missing }) }}</p>
            <p class="off-note">{{ t('foodFacts.disclaimer') }}</p>

            <p v-if="errorMessage" class="form-field__error" role="alert">
                <CircleAlert aria-hidden="true" />
                {{ errorMessage }}
            </p>
        </div>

        <div v-else-if="step === 'notFound'" class="off-message" role="status">
            <SearchX class="off-message__icon" aria-hidden="true" />
            <p>
                <strong>{{ t('foodFacts.notFoundTitle') }}</strong><br>
                {{ t('foodFacts.notFoundText', { barcode: input }) }}
            </p>
        </div>

        <div v-else-if="step === 'error'" class="off-message" role="alert">
            <CircleAlert class="off-message__icon" aria-hidden="true" />
            <p>{{ errorMessage }}</p>
        </div>

        <template #footer>
            <AppButton v-if="!online" variant="secondary" @click="open = false">{{ t('common.close') }}</AppButton>
            <AppButton v-else-if="step === 'input'" type="submit" :form="ids.form">
                {{ t('foodFacts.search') }}
            </AppButton>
            <AppButton v-else-if="step === 'scan'" variant="secondary" @click="step = 'input'">
                {{ t('foodFacts.typeInstead') }}
            </AppButton>
            <AppButton v-else-if="step === 'loading'" disabled>{{ t('foodFacts.searching') }}</AppButton>
            <template v-else-if="step === 'result'">
                <AppButton variant="secondary" :disabled="applying" @click="reset">{{ t('foodFacts.otherBarcode') }}</AppButton>
                <AppButton :disabled="applying || selected.length === 0" @click="apply">
                    {{ applying ? t('foodFacts.applying') : t('foodFacts.apply') }}
                </AppButton>
            </template>
            <AppButton v-else-if="step === 'notFound'" variant="secondary" @click="reset">
                {{ t('foodFacts.otherBarcode') }}
            </AppButton>
            <template v-else>
                <AppButton variant="secondary" @click="reset">{{ t('foodFacts.otherBarcode') }}</AppButton>
                <AppButton @click="lookup(input, false)">{{ t('common.retry') }}</AppButton>
            </template>
        </template>
    </AppSheet>

    <ImageCropDialog
        v-model:open="cropOpen"
        :source="cropSource"
        :crop-size="limits.cropSize"
        :max-pixels="limits.maxPixels"
        @apply="onCropped"
        @error="onCropError"
    />
</template>

<style scoped>
.off-input,
.off-result,
.off-skeleton {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.off-input__barcode {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.04em;
}

.off-message {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-message__icon {
    flex: none;
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
    color: var(--color-text-2);
}

.off-hit {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.off-hit__image {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    width: var(--thumb-size-lg);
    height: var(--thumb-size-lg);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-hit__image img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.off-hit__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
}

.off-hit__name {
    font-size: var(--font-size-name);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
    overflow-wrap: anywhere;
}

.off-hit__meta {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.off-choices {
    overflow: hidden;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-choices__legend {
    float: left;
    width: 100%;
    padding: var(--space-3) var(--space-4) var(--space-1);
    color: var(--color-text-2);
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-semibold);
}

.off-choice {
    clear: both;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: var(--row-min-height);
    padding: var(--space-2) var(--space-4);
    cursor: pointer;
}

.off-choice + .off-choice {
    border-top: 1px solid var(--color-border-subtle);
}

.off-choice__box {
    flex: none;
    display: grid;
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

.off-choice__box:checked {
    border-color: var(--color-accent);
    background: var(--color-accent);
}

.off-choice__box:checked::after {
    content: "";
    width: 0.375rem;
    height: 0.75rem;
    margin-top: -0.125rem;
    border: solid var(--color-on-accent);
    border-width: 0 3px 3px 0;
    transform: rotate(45deg);
}

.off-choice__box:focus-visible {
    outline: var(--focus-ring-width) solid var(--focus-ring-color);
    outline-offset: 2px;
}

.off-choice__text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.off-choice__title {
    font-size: var(--font-size-title);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-qty);
}

.off-choice__value {
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    font-size: var(--font-size-text);
    line-height: var(--line-height-note);
    overflow-wrap: anywhere;
}

.off-choice__effect {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.off-choice__effect--replace {
    color: var(--color-notgot);
    font-weight: var(--font-weight-semibold);
}

.off-choice__thumb {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: var(--radius-sm);
    background: var(--color-surface-1);
    color: var(--color-text-2);
}

.off-choice__thumb img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.off-note {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.off-skeleton__image {
    flex: none;
    width: var(--thumb-size-lg);
    height: var(--thumb-size-lg);
    border-radius: var(--radius-md);
    background: var(--color-skeleton);
}

.off-skeleton__line {
    width: 60%;
    height: var(--space-4);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}

.off-skeleton__line--title {
    width: 85%;
    height: var(--space-5);
    background: var(--color-skeleton);
}

.off-skeleton__choices {
    display: flex;
    flex-direction: column;
    gap: 1px;
    overflow: hidden;
    padding-top: var(--space-8);
    border-radius: var(--radius-md);
    background: var(--color-surface-2);
}

.off-skeleton__line--note {
    width: 70%;
    height: var(--line-height-note);
}

/* Feld mit Wert und Hinweis: drei Zeilen, etwas höher als eine Listenzeile */
.off-skeleton__row {
    height: calc(var(--row-min-height) + var(--space-2));
    background: var(--color-skeleton);
}
</style>
