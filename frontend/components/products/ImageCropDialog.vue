<script setup>
/**
 * Produktbild quadratisch zuschneiden, ohne Bibliothek (Vorbild:
 * Benutzerbild in SmartPicker). Bild per Ziehen verschieben; zoomen mit
 * Regler, Knöpfen, zwei Fingern oder Mausrad; Tastatur: Pfeiltasten
 * verschieben, Plus/Minus zoomen.
 *
 * „Übernehmen“ zeichnet den Ausschnitt mit `cropSize` Pixeln als JPEG und,
 * mit `withOriginal`, das ganze Bild auf höchstens `maxPixels` verkleinert;
 * beides neu kodiert, also ohne EXIF und Ortsdaten. Hochgeladen wird erst
 * beim Speichern des Formulars.
 *
 * Am Handy bildschirmfüllend, ab Tablet mittig.
 * v-model:open · Props: source (Blob), withOriginal, cropSize, maxPixels
 * Emits: apply({ image: Blob, original: Blob|null, preview: string }), error
 */
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { IonModal } from '@ionic/vue';
import { X, ZoomIn, ZoomOut } from '@lucide/vue';
import AppButton from '@/components/ui/AppButton.vue';
import { MAX_ZOOM, MIN_ZOOM, clampCrop, cropSide, imageLayout, initialCrop } from '@/utils/imageCrop.js';
import { loadImage, renderCrop, renderOriginal } from '@/utils/imageEncode.js';
import { t } from '@/i18n/index.js';

const ZOOM_STEP = 0.25;
const WHEEL_FACTOR = 0.002;
// Pfeiltaste: so viel vom Ausschnitt
const KEY_MOVE = 0.05;

const props = defineProps({
    source: {
        type: Blob,
        default: null
    },
    withOriginal: {
        type: Boolean,
        default: true
    },
    cropSize: {
        type: Number,
        required: true
    },
    maxPixels: {
        type: Number,
        required: true
    }
});

const emit = defineEmits(['apply', 'error']);
const open = defineModel('open', { type: Boolean, default: false });

const titleId = useId();
const hintId = useId();
const zoomId = useId();
const viewport = ref(null);
const viewportSize = ref(0);
const image = ref(null);
const imageUrl = ref('');
const crop = ref({ x: 0, y: 0, zoom: MIN_ZOOM });
const busy = ref(false);

const size = computed(() => (image.value
    ? { width: image.value.naturalWidth, height: image.value.naturalHeight }
    : null));

const imageStyle = computed(() => {
    if (!size.value || !viewportSize.value) {
        return null;
    }

    const layout = imageLayout(size.value, crop.value, viewportSize.value);

    return {
        width: layout.width + 'px',
        height: layout.height + 'px',
        transform: `translate3d(${layout.left}px, ${layout.top}px, 0)`
    };
});

// Ziehen mit einem Finger oder der Maus, zwei Finger zoomen zusätzlich
const pointers = new Map();
let gesture = null;
let resizeObserver = null;

watch(viewport, (element) => {
    resizeObserver?.disconnect();

    if (!element) {
        return;
    }

    resizeObserver = new ResizeObserver(([entry]) => {
        viewportSize.value = entry.contentRect.width;
    });
    resizeObserver.observe(element);
});

watch(() => [open.value, props.source], async ([isOpen, source]) => {
    release();

    if (!isOpen || !source) {
        return;
    }

    try {
        const loaded = await loadImage(source);

        // inzwischen geschlossen oder ein anderes Bild
        if (!open.value || props.source !== source) {
            URL.revokeObjectURL(loaded.url);
            return;
        }

        image.value = loaded.image;
        imageUrl.value = loaded.url;
        crop.value = initialCrop(size.value);
    } catch {
        open.value = false;
        emit('error', 'unreadable');
    }
}, { immediate: true });

function release() {
    if (imageUrl.value) {
        URL.revokeObjectURL(imageUrl.value);
    }

    image.value = null;
    imageUrl.value = '';
    pointers.clear();
    gesture = null;
}

onBeforeUnmount(() => {
    release();
    resizeObserver?.disconnect();
});

function update(changes) {
    if (size.value) {
        crop.value = clampCrop(size.value, { ...crop.value, ...changes });
    }
}

function setZoom(zoom) {
    update({ zoom });
}

function midpoint(list) {
    return {
        x: list.reduce((sum, point) => sum + point.x, 0) / list.length,
        y: list.reduce((sum, point) => sum + point.y, 0) / list.length
    };
}

function distance(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function startGesture() {
    const list = [...pointers.values()];

    gesture = {
        crop: { ...crop.value },
        center: midpoint(list),
        distance: list.length > 1 ? distance(list[0], list[1]) : 0
    };
}

function onPointerDown(event) {
    if (!size.value || busy.value) {
        return;
    }

    viewport.value.setPointerCapture(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    startGesture();
}

function onPointerMove(event) {
    if (!gesture || !pointers.has(event.pointerId)) {
        return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    const list = [...pointers.values()];
    const center = midpoint(list);
    // Bildpixel je Anzeigepixel beim Start der Geste
    const ratio = cropSide(size.value, gesture.crop.zoom) / viewportSize.value;
    let zoom = gesture.crop.zoom;

    if (list.length > 1 && gesture.distance > 0) {
        zoom = gesture.crop.zoom * distance(list[0], list[1]) / gesture.distance;
    }

    update({
        zoom,
        x: gesture.crop.x - (center.x - gesture.center.x) * ratio,
        y: gesture.crop.y - (center.y - gesture.center.y) * ratio
    });
}

function onPointerUp(event) {
    pointers.delete(event.pointerId);

    if (pointers.size > 0) {
        startGesture();
    } else {
        gesture = null;
    }
}

function onWheel(event) {
    if (size.value && !busy.value) {
        setZoom(crop.value.zoom * Math.exp(-event.deltaY * WHEEL_FACTOR));
    }
}

function onKeydown(event) {
    if (!size.value || busy.value) {
        return;
    }

    const step = cropSide(size.value, crop.value.zoom) * KEY_MOVE;
    const moves = {
        ArrowLeft: { x: crop.value.x - step },
        ArrowRight: { x: crop.value.x + step },
        ArrowUp: { y: crop.value.y - step },
        ArrowDown: { y: crop.value.y + step },
        '+': { zoom: crop.value.zoom + ZOOM_STEP },
        '=': { zoom: crop.value.zoom + ZOOM_STEP },
        '-': { zoom: crop.value.zoom - ZOOM_STEP }
    };

    if (moves[event.key]) {
        event.preventDefault();
        update(moves[event.key]);
    }
}

async function apply() {
    if (!image.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const cropped = await renderCrop(image.value, crop.value, props.cropSize);
        const original = props.withOriginal ? await renderOriginal(image.value, props.maxPixels) : null;

        emit('apply', { image: cropped, original, preview: URL.createObjectURL(cropped) });
        open.value = false;
    } catch (error) {
        console.error(error);
        emit('error', 'render');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <IonModal class="crop-dialog" :is-open="open" :aria-labelledby="titleId" @did-dismiss="open = false">
        <div class="crop-dialog__body">
            <header class="crop-dialog__head">
                <h2 :id="titleId" class="crop-dialog__title">{{ t('productImage.cropTitle') }}</h2>
                <button type="button" class="crop-dialog__close" :aria-label="t('common.close')" @click="open = false">
                    <X aria-hidden="true" />
                </button>
            </header>

            <div class="crop-dialog__stage">
                <div
                    ref="viewport"
                    class="crop-dialog__viewport"
                    tabindex="0"
                    role="group"
                    :aria-label="t('productImage.cropArea')"
                    :aria-describedby="hintId"
                    :aria-busy="image ? undefined : 'true'"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                    @wheel.prevent="onWheel"
                    @keydown="onKeydown"
                >
                    <img
                        v-if="imageUrl && imageStyle"
                        class="crop-dialog__image"
                        :src="imageUrl"
                        :style="imageStyle"
                        alt=""
                        draggable="false"
                    >
                    <span class="crop-dialog__frame" aria-hidden="true"></span>
                </div>

                <p :id="hintId" class="crop-dialog__hint">{{ t('productImage.cropHint') }}</p>

                <div class="crop-dialog__zoom">
                    <button
                        type="button"
                        class="crop-dialog__zoom-button"
                        :aria-label="t('productImage.zoomOut')"
                        :disabled="!image || crop.zoom <= MIN_ZOOM"
                        @click="setZoom(crop.zoom - ZOOM_STEP)"
                    >
                        <ZoomOut aria-hidden="true" />
                    </button>
                    <label :for="zoomId" class="visually-hidden">{{ t('productImage.zoom') }}</label>
                    <input
                        :id="zoomId"
                        class="crop-dialog__range"
                        type="range"
                        :min="MIN_ZOOM"
                        :max="MAX_ZOOM"
                        step="0.01"
                        :value="crop.zoom"
                        :disabled="!image"
                        :aria-valuetext="t('productImage.zoomValue', { percent: Math.round(crop.zoom * 100) })"
                        @input="setZoom(Number($event.target.value))"
                    >
                    <button
                        type="button"
                        class="crop-dialog__zoom-button"
                        :aria-label="t('productImage.zoomIn')"
                        :disabled="!image || crop.zoom >= MAX_ZOOM"
                        @click="setZoom(crop.zoom + ZOOM_STEP)"
                    >
                        <ZoomIn aria-hidden="true" />
                    </button>
                </div>
            </div>

            <footer class="crop-dialog__footer">
                <AppButton variant="secondary" :disabled="busy" @click="open = false">{{ t('productImage.cancel') }}</AppButton>
                <AppButton :disabled="!image || busy" @click="apply">
                    {{ busy ? t('productImage.preparing') : t('productImage.apply') }}
                </AppButton>
            </footer>
        </div>
    </IonModal>
</template>

<style scoped>
.crop-dialog {
    --background: var(--color-surface-1);
    --backdrop-opacity: 1;
}

.crop-dialog__body {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    height: 100%;
    overflow-y: auto;
    padding:
        calc(var(--space-4) + env(safe-area-inset-top, 0px))
        var(--page-gutter)
        calc(var(--space-4) + env(safe-area-inset-bottom, 0px));
    color: var(--color-text-1);
}

.crop-dialog__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
}

.crop-dialog__title {
    font-size: var(--font-size-h2);
    font-weight: var(--font-weight-semibold);
    line-height: var(--line-height-h2);
}

.crop-dialog__close,
.crop-dialog__zoom-button {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    background: var(--color-surface-2);
    color: var(--color-text-1);
    transition: background-color var(--duration-fast) var(--ease-out);
}

.crop-dialog__zoom-button:disabled {
    opacity: 0.5;
    cursor: default;
}

@media (hover: hover) {
    .crop-dialog__close:hover,
    .crop-dialog__zoom-button:not(:disabled):hover {
        background: var(--color-surface-3);
    }
}

.crop-dialog__close :deep(svg),
.crop-dialog__zoom-button :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.crop-dialog__stage {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-4);
}

.crop-dialog__viewport {
    position: relative;
    width: 100%;
    max-width: var(--crop-viewport-max);
    aspect-ratio: 1;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--color-surface-3);
    cursor: grab;
    touch-action: none;
    user-select: none;
    -webkit-user-select: none;
}

.crop-dialog__viewport:active {
    cursor: grabbing;
}

.crop-dialog__image {
    position: absolute;
    top: 0;
    left: 0;
    max-width: none;
    pointer-events: none;
}

/* Drittel-Raster und feine Kante zeigen den Ausschnitt, ohne das Bild zu verdecken */
.crop-dialog__frame {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background:
        linear-gradient(to right, transparent calc(100% / 3 - 0.5px), var(--crop-grid) calc(100% / 3 - 0.5px), var(--crop-grid) calc(100% / 3 + 0.5px), transparent calc(100% / 3 + 0.5px), transparent calc(200% / 3 - 0.5px), var(--crop-grid) calc(200% / 3 - 0.5px), var(--crop-grid) calc(200% / 3 + 0.5px), transparent calc(200% / 3 + 0.5px)),
        linear-gradient(to bottom, transparent calc(100% / 3 - 0.5px), var(--crop-grid) calc(100% / 3 - 0.5px), var(--crop-grid) calc(100% / 3 + 0.5px), transparent calc(100% / 3 + 0.5px), transparent calc(200% / 3 - 0.5px), var(--crop-grid) calc(200% / 3 - 0.5px), var(--crop-grid) calc(200% / 3 + 0.5px), transparent calc(200% / 3 + 0.5px));
    box-shadow: inset 0 0 0 2px var(--crop-edge);
    pointer-events: none;
}

.crop-dialog__hint {
    color: var(--color-text-2);
    font-size: var(--font-size-note);
    line-height: var(--line-height-note);
    text-align: center;
}

.crop-dialog__zoom {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    max-width: var(--crop-viewport-max);
}

.crop-dialog__range {
    flex: 1;
    min-width: 0;
    accent-color: var(--color-accent);
}

.crop-dialog__footer {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
}

@media (min-width: 45em) {
    .crop-dialog {
        --width: var(--crop-dialog-width);
        --height: auto;
        --border-radius: var(--radius-lg);
    }

    .crop-dialog__body {
        padding: var(--space-6);
    }
}
</style>
