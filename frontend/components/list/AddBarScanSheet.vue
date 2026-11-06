<script setup>
/**
 * Scannen aus der Eingabeleiste (Obsidian „Piklis Konzept“ › „Neue Liste und
 * Produktauswahl“ › „Scanner in der Leiste“). Kamera (`BarcodeScanner`) oder
 * eingetippte Nummer (`BarcodeForm`) führen zu einem Barcode:
 *
 * - Im Katalog oder als Listenartikel auf dieser Liste: sofort `found`, auch
 *   offline, ohne Rückfrage.
 * - Unbekannt: Open Food Facts über den Server (Cache und Bremse gelten), vorher
 *   ein Blick in den frischen Katalog, das spart Abrufe und Doppelte. Der Treffer
 *   zeigt Name und Bild mit „Zur Liste“; fehlt der Name, wird er eingetragen.
 * - Nichts gefunden: Name eintragen, Foto optional. Der Barcode bleibt am Produkt,
 *   damit der nächste Scan es findet.
 * - Offline: nur ein Hinweis, Open Food Facts geht nicht ohne Verbindung.
 *
 * „Im Katalog speichern“ ist an, weil Markenprodukte meist wiederkommen; aus
 * entsteht ein Listenartikel mit denselben Daten. Angelegt wird direkt am Server,
 * erst danach steht das Produkt auf der Liste. Angaben und Bild kommen im
 * Hintergrund nach, damit das Sheet sofort schließt; das Bild wird ohne
 * Zuschnitt-Schritt übernommen und lässt sich später zuschneiden. Toasts gibt es
 * nur, wenn dabei etwas scheitert.
 *
 * v-model:open · Props: listId · Emits: found(product)
 */
import { computed, nextTick, ref, useId, useTemplateRef, watch } from 'vue';
import { CircleAlert, Keyboard, ScanBarcode, SearchX, WifiOff } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSheet from '@/components/ui/AppSheet.vue';
import BarcodeForm from '@/components/products/BarcodeForm.vue';
import BarcodeScanner from '@/components/products/BarcodeScanner.vue';
import FoodFactsHit from '@/components/products/FoodFactsHit.vue';
import FoodFactsMessage from '@/components/products/FoodFactsMessage.vue';
import ProductImageField from '@/components/products/ProductImageField.vue';
import { api, logError } from '@/api/index.js';
import { loadConfig, useConfig } from '@/composables/useConfig.js';
import { useConnection } from '@/composables/useConnection.js';
import { listView } from '@/composables/useLists.js';
import {
    applyFoodFacts,
    applyImageChange,
    createProduct,
    importFoodFactsImage,
    loadProducts,
    useProducts
} from '@/composables/useProducts.js';
import { showToast } from '@/composables/useToast.js';
import { categoryOf, iconOf } from '@/products/catalog.js';
import { suggestForName } from '@/products/suggest.js';
import { productWithBarcode } from '@/utils/barcode.js';
import { foodFactsErrorKey, scanFields } from '@/utils/foodFacts.js';
import { t } from '@/i18n/index.js';

const NAME_MAX = 120;

const props = defineProps({
    listId: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['found']);
const open = defineModel('open', { type: Boolean, default: false });

const { catalog, findProduct } = useProducts();
const { online } = useConnection();
const { images: limits } = useConfig();
const listState = listView(props.listId);

const ids = {
    number: useId(),
    create: useId(),
    name: useId(),
    nameError: useId(),
    catalog: useId()
};

const step = ref('scan'); // scan · number · loading · result · notFound · offline · error
const typed = ref('');
const barcode = ref('');
const result = ref(null);
const name = ref('');
const nameError = ref('');
const toCatalog = ref(true);
const imageChange = ref(null);
const saving = ref(false);
const errorMessage = ref('');

const numberForm = useTemplateRef('numberForm');
const nameInput = useTemplateRef('nameInput');

// gleiche ID beim erneuten Versuch: Kam die erste Antwort nur nicht an, gibt es kein zweites Produkt
let newProductId = '';
// Gehört eine Antwort noch zu dieser Suche? Sonst ist das Sheet zu oder schon weiter.
let currentSearch = 0;

const scanning = computed(() => ['scan', 'number', 'offline'].includes(step.value));
const creating = computed(() => step.value === 'result' || step.value === 'notFound');
const needsName = computed(() => step.value === 'notFound' || (step.value === 'result' && !result.value?.name));
const fallback = computed(() => suggestForName(name.value));

const announcement = computed(() => {
    if (step.value === 'loading') {
        return t('foodFacts.searching');
    }

    return step.value === 'result' ? (result.value.name ?? t('addBar.scanNoName')) : '';
});

function startOver() {
    currentSearch++;
    step.value = 'scan';
    typed.value = '';
    barcode.value = '';
    result.value = null;
    name.value = '';
    nameError.value = '';
    toCatalog.value = true;
    imageChange.value = null;
    saving.value = false;
    errorMessage.value = '';
}

watch(open, (isOpen) => {
    currentSearch++;

    if (isOpen) {
        startOver();
        loadConfig();
    }
});

function knownProduct(code) {
    const { open: active, notGot, done } = listState.value;
    const onList = [...active, ...notGot, ...done].map((entry) => findProduct(entry.productId)).filter(Boolean);

    return productWithBarcode([...catalog.value, ...onList.filter((product) => product.listItem)], code);
}

function finish(product) {
    open.value = false;
    emit('found', product);
}

function showNotFound() {
    step.value = 'notFound';
    nextTick(() => nameInput.value?.focus({ preventScroll: true }));
}

async function lookup() {
    const search = ++currentSearch;

    step.value = 'loading';
    errorMessage.value = '';
    newProductId = crypto.randomUUID();

    try {
        await loadProducts().catch(logError);

        if (search !== currentSearch) {
            return;
        }

        const known = knownProduct(barcode.value);

        if (known) {
            finish(known);
            return;
        }

        const data = await api.lookupFoodFacts(barcode.value);

        if (search === currentSearch) {
            result.value = data.result;
            name.value = data.result.name ?? '';
            step.value = 'result';
        }
    } catch (error) {
        if (search !== currentSearch) {
            return;
        }

        if (error?.code === 'off_not_found') {
            showNotFound();
        } else if (error?.status === 0) {
            step.value = 'offline';
        } else {
            errorMessage.value = t(foodFactsErrorKey(error));
            step.value = 'error';
        }
    }
}

function onBarcode(code) {
    const known = knownProduct(code);

    if (known) {
        finish(known);
        return;
    }

    barcode.value = code;

    if (online.value) {
        lookup();
    } else {
        step.value = 'offline';
    }
}

function typeNumber() {
    step.value = 'number';
    nextTick(() => numberForm.value?.focus());
}

/** Angaben und Bild nachtragen; das Produkt steht schon auf der Liste. */
async function complete(product, hit, change) {
    const fields = scanFields(hit);

    try {
        if (fields.length > 0) {
            await applyFoodFacts(product.id, { barcode: hit.barcode, fields });
        }
    } catch (error) {
        logError(error);
        showToast(t('addBar.scanDataFailed', { name: product.name }), { icon: 'notgot' });
        return;
    }

    try {
        if (hit?.hasImage) {
            await importFoodFactsImage(product.id, hit.barcode, limits.value);
        } else if (change) {
            await applyImageChange(product.id, change);
        }
    } catch (error) {
        logError(error);
        showToast(t('addBar.scanImageFailed', { name: product.name }), { icon: 'notgot' });
    }
}

async function toList() {
    if (saving.value) {
        return;
    }

    const wanted = name.value.trim();

    if (wanted === '') {
        nameError.value = t('productForm.nameRequired');
        nameInput.value?.focus();
        return;
    }

    nameError.value = '';
    errorMessage.value = '';
    saving.value = true;

    let product;

    try {
        product = await createProduct({
            id: newProductId,
            name: wanted,
            barcode: barcode.value,
            listItem: !toCatalog.value
        });
    } catch (error) {
        saving.value = false;
        errorMessage.value = t(error?.status === 0 ? 'common.onlineOnly' : 'common.error');
        return;
    }

    const hit = result.value;
    const change = imageChange.value;

    saving.value = false;
    finish(product);
    complete(product, hit, change);
}
</script>

<template>
    <AppSheet
        v-model:open="open"
        :title="t(scanning ? 'addBar.scanTitle' : 'addBar.scanNewTitle')"
        :subtitle="scanning ? '' : barcode"
    >
        <p class="visually-hidden" role="status">{{ announcement }}</p>

        <BarcodeScanner v-if="step === 'scan'" @detected="onBarcode" />

        <BarcodeForm
            v-else-if="step === 'number'"
            :id="ids.number"
            ref="numberForm"
            v-model="typed"
            @search="onBarcode"
        />

        <div v-else-if="step === 'loading'" class="scan-new" aria-busy="true">
            <FoodFactsHit />
            <span class="scan-new__skeleton-switch" aria-hidden="true"></span>
            <span class="scan-new__skeleton-note" aria-hidden="true"></span>
        </div>

        <form v-else-if="creating" :id="ids.create" class="scan-new" novalidate @submit.prevent="toList">
            <FoodFactsHit
                v-if="step === 'result'"
                :result="result"
                :icon="iconOf(fallback)"
                :category="categoryOf(fallback)"
            />
            <FoodFactsMessage v-else :icon="SearchX">{{ t('addBar.scanNotFound', { barcode }) }}</FoodFactsMessage>

            <div v-if="needsName" class="form-field">
                <label :for="ids.name" class="form-field__label">{{ t('productForm.name') }}</label>
                <input
                    :id="ids.name"
                    ref="nameInput"
                    v-model="name"
                    class="form-field__input"
                    type="text"
                    :maxlength="NAME_MAX"
                    autocomplete="off"
                    autocapitalize="sentences"
                    enterkeyhint="done"
                    :aria-invalid="nameError ? 'true' : 'false'"
                    :aria-describedby="nameError ? ids.nameError : undefined"
                    @input="nameError = ''"
                >
                <span v-if="nameError" :id="ids.nameError" class="form-field__error">
                    <CircleAlert aria-hidden="true" />
                    {{ nameError }}
                </span>
            </div>

            <ProductImageField
                v-if="step === 'notFound'"
                v-model="imageChange"
                :icon="iconOf(fallback)"
                :category="categoryOf(fallback)"
            />

            <label class="form-switch" :for="ids.catalog">
                <span class="scan-new__switch-text">
                    {{ t('addBar.scanSaveCatalog') }}
                    <span class="form-field__hint">{{ t('addBar.scanSaveCatalogHint') }}</span>
                </span>
                <input :id="ids.catalog" v-model="toCatalog" class="form-switch__toggle" type="checkbox" role="switch">
            </label>

            <p v-if="step === 'result'" class="scan-new__note">{{ t('addBar.scanSource') }}</p>

            <p v-if="errorMessage" class="form-field__error" role="alert">
                <CircleAlert aria-hidden="true" />
                {{ errorMessage }}
            </p>
        </form>

        <FoodFactsMessage v-else-if="step === 'offline'" :icon="WifiOff" role="status">
            {{ t('addBar.scanOffline', { barcode }) }}
        </FoodFactsMessage>

        <FoodFactsMessage v-else :icon="CircleAlert" role="alert">{{ errorMessage }}</FoodFactsMessage>

        <template #footer>
            <AppButton v-if="step === 'scan'" variant="secondary" @click="typeNumber">
                <Keyboard aria-hidden="true" />
                {{ t('foodFacts.typeInstead') }}
            </AppButton>
            <template v-else-if="step === 'number'">
                <AppButton variant="secondary" @click="startOver">
                    <ScanBarcode aria-hidden="true" />
                    {{ t('addBar.scan') }}
                </AppButton>
                <AppButton type="submit" :form="ids.number">{{ t('foodFacts.search') }}</AppButton>
            </template>
            <template v-else-if="step === 'loading'">
                <AppButton variant="secondary" disabled>{{ t('addBar.scanAgain') }}</AppButton>
                <AppButton disabled>{{ t('foodFacts.searching') }}</AppButton>
            </template>
            <template v-else-if="creating">
                <AppButton variant="secondary" :disabled="saving" @click="startOver">{{ t('addBar.scanAgain') }}</AppButton>
                <AppButton type="submit" :form="ids.create" :disabled="saving">
                    {{ saving ? t('addBar.scanAdding') : t('addBar.scanToList') }}
                </AppButton>
            </template>
            <AppButton v-else-if="step === 'offline'" variant="secondary" @click="startOver">{{ t('addBar.scanAgain') }}</AppButton>
            <template v-else>
                <AppButton variant="secondary" @click="startOver">{{ t('addBar.scanAgain') }}</AppButton>
                <AppButton @click="lookup">{{ t('common.retry') }}</AppButton>
            </template>
        </template>
    </AppSheet>
</template>

<style scoped>
.scan-new {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
}

.scan-new__switch-text {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    font-weight: var(--font-weight-semibold);
}

.scan-new__switch-text .form-field__hint {
    font-weight: var(--font-weight-regular);
}

.scan-new__note {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
}

.scan-new__skeleton-switch {
    height: var(--tap-size);
    border-radius: var(--radius-sm);
    background: var(--color-skeleton);
}

.scan-new__skeleton-note {
    width: 85%;
    height: var(--line-height-note);
    border-radius: var(--radius-xs);
    background: var(--color-skeleton-2);
}
</style>
