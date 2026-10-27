<script setup>
/**
 * Foto im Produktformular: aufnehmen (Handy), Datei wählen oder am Desktop
 * ein kopiertes Bild einfügen (⌘ V / Strg + V oder Knopf „Einfügen“), dann
 * quadratisch zuschneiden (`ImageCropDialog`). Vorhandene Bilder lassen sich
 * aus dem Original neu zuschneiden oder entfernen (wieder das Ersatzicon).
 *
 * Zu große Dateien meldet das Feld vor dem Zuschnitt, mit den Grenzen aus
 * den Projekteinstellungen (`GET /config`).
 *
 * Hochgeladen wird erst beim Speichern des Formulars; bis dahin hält
 * v-model die Änderung: null (keine), { type: 'set', image, original,
 * preview } oder { type: 'remove' }.
 *
 * Props: product (null = neu), icon (Ersatzicon für die Vorschau)
 */
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Camera, ClipboardPaste, Crop, ImagePlus, Images, Trash2, CircleAlert } from '@lucide/vue';
import ProductImage from './ProductImage.vue';
import ImageCropDialog from './ImageCropDialog.vue';
import { loadConfig, useConfig } from '@/composables/useConfig.js';
import { useMediaQuery } from '@/composables/useMediaQuery.js';
import { IMAGE_ACCEPT, checkImageFile, formatMegabytes, imageFromClipboard } from '@/utils/imageUpload.js';
import { locale, t } from '@/i18n/index.js';

const props = defineProps({
    product: {
        type: Object,
        default: null
    },
    icon: {
        type: String,
        default: undefined
    }
});

const change = defineModel({ type: Object, default: null });

const { images: limits } = useConfig();
const isTouch = useMediaQuery('(pointer: coarse)');

const labelId = useId();
const errorId = useId();
const cameraInput = ref(null);
const fileInput = ref(null);
const cropOpen = ref(false);
const cropSource = ref(null);
const cropWithOriginal = ref(true);
const error = ref('');
const loadingOriginal = ref(false);

const canPasteButton = typeof navigator !== 'undefined' && typeof navigator.clipboard?.read === 'function';
const pasteKeys = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ V' : 'Strg + V';

const previewUrl = computed(() => {
    if (change.value?.type === 'set') {
        return change.value.preview;
    }

    if (change.value?.type === 'remove') {
        return null;
    }

    return props.product?.image?.medium ?? null;
});

const hasImage = computed(() => Boolean(previewUrl.value));

// neu zuschneiden: aus dem noch nicht gespeicherten Original oder dem auf dem Server
const canRecrop = computed(() => {
    if (change.value?.type === 'set') {
        return Boolean(change.value.original) || Boolean(props.product?.image?.original);
    }

    return change.value === null && Boolean(props.product?.image?.original);
});

function setChange(next) {
    if (change.value?.type === 'set' && change.value.preview !== next?.preview) {
        URL.revokeObjectURL(change.value.preview);
    }

    change.value = next;
}

function start(file) {
    error.value = '';

    if (!file) {
        return;
    }

    const problem = checkImageFile(file, limits.value);

    if (problem === 'size') {
        error.value = t('productImage.errors.tooLarge', {
            size: formatMegabytes(file.size, locale.value),
            max: formatMegabytes(limits.value.maxBytes, locale.value)
        });
        return;
    }

    if (problem === 'type') {
        error.value = t('productImage.errors.type');
        return;
    }

    cropSource.value = file;
    cropWithOriginal.value = true;
    cropOpen.value = true;
}

function onFileChange(event) {
    const [file] = event.target.files ?? [];

    // dieselbe Datei soll sich noch einmal wählen lassen
    event.target.value = '';
    start(file ?? null);
}

async function recrop() {
    error.value = '';

    if (change.value?.type === 'set' && change.value.original) {
        cropSource.value = change.value.original;
        cropWithOriginal.value = false;
        cropOpen.value = true;
        return;
    }

    loadingOriginal.value = true;

    try {
        const response = await fetch(props.product.image.original, { credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error('http_' + response.status);
        }

        cropSource.value = await response.blob();
        cropWithOriginal.value = false;
        cropOpen.value = true;
    } catch (fetchError) {
        console.error(fetchError);
        error.value = t('productImage.errors.original');
    } finally {
        loadingOriginal.value = false;
    }
}

function onApply({ image, original, preview }) {
    // neu zugeschnitten: ein noch nicht gespeichertes Original bleibt dabei
    const keptOriginal = cropWithOriginal.value ? original : (change.value?.type === 'set' ? change.value.original : null);

    setChange({ type: 'set', image, original: keptOriginal, preview });
}

function onCropError(reason) {
    error.value = t(reason === 'unreadable' ? 'productImage.errors.unreadable' : 'productImage.errors.render');
}

function remove() {
    error.value = '';
    setChange(props.product?.image ? { type: 'remove' } : null);
}

async function pasteFromButton() {
    error.value = '';

    try {
        const items = await navigator.clipboard.read();

        for (const item of items) {
            const type = item.types.find((candidate) => candidate.startsWith('image/'));

            if (type) {
                const blob = await item.getType(type);

                start(new File([blob], 'clipboard', { type }));
                return;
            }
        }

        error.value = t('productImage.errors.noClipboardImage');
    } catch (clipboardError) {
        console.error(clipboardError);
        error.value = t('productImage.errors.clipboard', { keys: pasteKeys });
    }
}

// ⌘ V / Strg + V irgendwo im Formular, auch im Namensfeld: Text bleibt Text
function onPaste(event) {
    if (cropOpen.value) {
        return;
    }

    const file = imageFromClipboard(event.clipboardData);

    if (file) {
        event.preventDefault();
        start(file);
    }
}

onMounted(() => {
    loadConfig();
    document.addEventListener('paste', onPaste);
});

onBeforeUnmount(() => {
    document.removeEventListener('paste', onPaste);

    if (change.value?.type === 'set') {
        URL.revokeObjectURL(change.value.preview);
    }
});
</script>

<template>
    <div class="form-field" role="group" :aria-labelledby="labelId" :aria-describedby="error ? errorId : undefined">
        <span :id="labelId" class="form-field__label">
            {{ t('productImage.label') }}
            <span class="form-field__label-hint">· {{ t('productImage.labelHint') }}</span>
        </span>

        <div class="image-field">
            <ProductImage class="image-field__preview" :image-url="previewUrl" :icon="icon" size="lg" />

            <div class="image-field__actions">
                <template v-if="isTouch">
                    <button type="button" class="image-field__action" @click="cameraInput?.click()">
                        <Camera aria-hidden="true" />
                        {{ t('productImage.camera') }}
                    </button>
                    <button type="button" class="image-field__action" @click="fileInput?.click()">
                        <Images aria-hidden="true" />
                        {{ t('productImage.library') }}
                    </button>
                </template>
                <template v-else>
                    <button type="button" class="image-field__action" @click="fileInput?.click()">
                        <ImagePlus aria-hidden="true" />
                        {{ t('productImage.choose') }}
                    </button>
                    <button v-if="canPasteButton" type="button" class="image-field__action" @click="pasteFromButton">
                        <ClipboardPaste aria-hidden="true" />
                        {{ t('productImage.paste') }}
                    </button>
                </template>
                <button v-if="canRecrop" type="button" class="image-field__action" :disabled="loadingOriginal" @click="recrop">
                    <Crop aria-hidden="true" />
                    {{ t('productImage.recrop') }}
                </button>
                <button v-if="hasImage" type="button" class="image-field__action image-field__action--danger" @click="remove">
                    <Trash2 aria-hidden="true" />
                    {{ t('productImage.remove') }}
                </button>
            </div>
        </div>

        <span v-if="error" :id="errorId" class="form-field__error" role="alert">
            <CircleAlert aria-hidden="true" />
            {{ error }}
        </span>
        <span v-else-if="change?.type === 'set'" class="form-field__hint">{{ t('productImage.pendingHint') }}</span>
        <span v-else-if="change?.type === 'remove'" class="form-field__hint">{{ t('productImage.removeHint') }}</span>
        <span v-else-if="!isTouch" class="form-field__hint">{{ t('productImage.pasteHint', { keys: pasteKeys }) }}</span>

        <input
            ref="cameraInput"
            class="visually-hidden"
            type="file"
            :accept="IMAGE_ACCEPT"
            capture="environment"
            tabindex="-1"
            aria-hidden="true"
            @change="onFileChange"
        >
        <input
            ref="fileInput"
            class="visually-hidden"
            type="file"
            :accept="IMAGE_ACCEPT"
            tabindex="-1"
            aria-hidden="true"
            @change="onFileChange"
        >

        <ImageCropDialog
            v-model:open="cropOpen"
            :source="cropSource"
            :with-original="cropWithOriginal"
            :crop-size="limits.cropSize"
            :max-pixels="limits.maxPixels"
            @apply="onApply"
            @error="onCropError"
        />
    </div>
</template>

<style scoped>
.image-field {
    display: flex;
    align-items: flex-start;
    gap: var(--space-4);
}

.image-field__actions {
    display: flex;
    flex: 1;
    flex-wrap: wrap;
    gap: var(--space-2);
    min-width: 0;
}

.image-field__action {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--tap-size);
    padding: 0 var(--space-4) 0 var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-surface-2);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    font-weight: var(--font-weight-semibold);
    transition:
        background-color var(--duration-fast) var(--ease-out),
        transform var(--duration-fast) var(--ease-out);
}

.image-field__action:active {
    transform: scale(var(--press-scale));
}

.image-field__action:disabled {
    opacity: 0.6;
    cursor: default;
}

@media (hover: hover) {
    .image-field__action:not(:disabled):hover {
        background: var(--color-surface-3);
    }
}

.image-field__action--danger {
    color: var(--color-danger);
}

.image-field__action :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}
</style>
