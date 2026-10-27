<script setup>
/**
 * Großansicht eines Produktbilds (1600 px, WebP) bildschirmfüllend auf
 * dunklem Grund. Zoom mit zwei Fingern, Mausrad, Doppeltippen/Doppelklick
 * oder den Knöpfen; gezoomt lässt sich das Bild verschieben. Tastatur:
 * Plus/Minus zoomen, Pfeiltasten verschieben, Escape schließt.
 *
 * v-model:open · Props: src, alt (Produktname)
 */
import { computed, ref, useId, watch } from 'vue';
import { IonModal } from '@ionic/vue';
import { X, ZoomIn, ZoomOut } from '@lucide/vue';
import { VIEWER_MAX_ZOOM, clampView, zoomAt } from '@/utils/imageCrop.js';
import { t } from '@/i18n/index.js';

const ZOOM_STEP = 0.5;
const DOUBLE_TAP_ZOOM = 2.5;
const DOUBLE_TAP_MS = 300;
const WHEEL_FACTOR = 0.002;
const KEY_MOVE = 40;

const props = defineProps({
    src: {
        type: String,
        default: ''
    },
    alt: {
        type: String,
        default: ''
    }
});

const open = defineModel('open', { type: Boolean, default: false });

const titleId = useId();
const frame = ref(null);
const frameSize = ref({ width: 0, height: 0 });
const natural = ref({ width: 0, height: 0 });
const view = ref({ x: 0, y: 0, zoom: 1 });
const loaded = ref(false);
const failed = ref(false);

// Bildgröße bei Zoom 1: ganz sichtbar, höchstens in Originalgröße
const fitted = computed(() => {
    const { width, height } = natural.value;

    if (!width || !height || !frameSize.value.width) {
        return { width: 0, height: 0 };
    }

    const scale = Math.min(frameSize.value.width / width, frameSize.value.height / height, 1);

    return { width: width * scale, height: height * scale };
});

const imageStyle = computed(() => ({
    width: fitted.value.width + 'px',
    height: fitted.value.height + 'px',
    transform: `translate3d(${view.value.x}px, ${view.value.y}px, 0) scale(${view.value.zoom})`
}));

const pointers = new Map();
let gesture = null;
let lastTap = 0;
let resizeObserver = null;

watch(frame, (element) => {
    resizeObserver?.disconnect();

    if (!element) {
        return;
    }

    resizeObserver = new ResizeObserver(([entry]) => {
        frameSize.value = { width: entry.contentRect.width, height: entry.contentRect.height };
        update({});
    });
    resizeObserver.observe(element);
});

watch(() => [open.value, props.src], () => {
    view.value = { x: 0, y: 0, zoom: 1 };
    loaded.value = false;
    failed.value = false;
    pointers.clear();
    gesture = null;
});

function onLoad(event) {
    natural.value = { width: event.target.naturalWidth, height: event.target.naturalHeight };
    loaded.value = true;
}

function update(changes) {
    view.value = clampView(fitted.value, frameSize.value, { ...view.value, ...changes });
}

// Punkt relativ zur Rahmenmitte
function relative(clientX, clientY) {
    const rect = frame.value.getBoundingClientRect();

    return { x: clientX - rect.left - rect.width / 2, y: clientY - rect.top - rect.height / 2 };
}

function zoomTo(zoom, point = { x: 0, y: 0 }) {
    const limited = Math.min(VIEWER_MAX_ZOOM, Math.max(1, zoom));

    view.value = clampView(fitted.value, frameSize.value, zoomAt(view.value, limited, point));
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
        view: { ...view.value },
        center: midpoint(list),
        distance: list.length > 1 ? distance(list[0], list[1]) : 0
    };
}

function onPointerDown(event) {
    if (!loaded.value) {
        return;
    }

    frame.value.setPointerCapture(event.pointerId);
    pointers.set(event.pointerId, relative(event.clientX, event.clientY));
    startGesture();

    // Doppeltippen: hinein- bzw. wieder herauszoomen
    if (pointers.size === 1) {
        const now = Date.now();

        if (now - lastTap < DOUBLE_TAP_MS) {
            const point = relative(event.clientX, event.clientY);

            zoomTo(view.value.zoom > 1 ? 1 : DOUBLE_TAP_ZOOM, point);
            lastTap = 0;
            startGesture();
            return;
        }

        lastTap = now;
    }
}

function onPointerMove(event) {
    if (!gesture || !pointers.has(event.pointerId)) {
        return;
    }

    pointers.set(event.pointerId, relative(event.clientX, event.clientY));

    const list = [...pointers.values()];
    const center = midpoint(list);
    let next = {
        zoom: gesture.view.zoom,
        x: gesture.view.x + center.x - gesture.center.x,
        y: gesture.view.y + center.y - gesture.center.y
    };

    if (list.length > 1 && gesture.distance > 0) {
        const zoom = Math.min(VIEWER_MAX_ZOOM, Math.max(1, gesture.view.zoom * distance(list[0], list[1]) / gesture.distance));

        next = zoomAt(next, zoom, center);
    }

    view.value = clampView(fitted.value, frameSize.value, next);
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
    if (loaded.value) {
        zoomTo(view.value.zoom * Math.exp(-event.deltaY * WHEEL_FACTOR), relative(event.clientX, event.clientY));
    }
}

function onKeydown(event) {
    const moves = {
        ArrowLeft: () => update({ x: view.value.x + KEY_MOVE }),
        ArrowRight: () => update({ x: view.value.x - KEY_MOVE }),
        ArrowUp: () => update({ y: view.value.y + KEY_MOVE }),
        ArrowDown: () => update({ y: view.value.y - KEY_MOVE }),
        '+': () => zoomTo(view.value.zoom + ZOOM_STEP),
        '=': () => zoomTo(view.value.zoom + ZOOM_STEP),
        '-': () => zoomTo(view.value.zoom - ZOOM_STEP)
    };

    if (moves[event.key]) {
        event.preventDefault();
        moves[event.key]();
    }
}
</script>

<template>
    <IonModal class="image-viewer" :is-open="open" :aria-labelledby="titleId" @did-dismiss="open = false">
        <div class="image-viewer__body" @keydown="onKeydown">
            <h2 :id="titleId" class="visually-hidden">{{ t('productImage.viewerTitle', { name: alt }) }}</h2>

            <div
                ref="frame"
                class="image-viewer__frame"
                tabindex="0"
                role="group"
                :aria-label="t('productImage.viewerArea')"
                :aria-busy="loaded || failed ? undefined : 'true'"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @wheel.prevent="onWheel"
            >
                <img
                    v-if="src && !failed"
                    class="image-viewer__image"
                    :class="{ 'image-viewer__image--loading': !loaded }"
                    :src="src"
                    :alt="alt"
                    :style="loaded ? imageStyle : null"
                    draggable="false"
                    @load="onLoad"
                    @error="failed = true"
                >
                <p v-if="failed" class="image-viewer__error">{{ t('productImage.viewerError') }}</p>
            </div>

            <button type="button" class="image-viewer__button image-viewer__close" :aria-label="t('common.close')" @click="open = false">
                <X aria-hidden="true" />
            </button>

            <div class="image-viewer__zoom">
                <button
                    type="button"
                    class="image-viewer__button"
                    :aria-label="t('productImage.zoomOut')"
                    :disabled="!loaded || view.zoom <= 1"
                    @click="zoomTo(view.zoom - ZOOM_STEP)"
                >
                    <ZoomOut aria-hidden="true" />
                </button>
                <span class="image-viewer__percent" aria-live="polite">{{ Math.round(view.zoom * 100) }} %</span>
                <button
                    type="button"
                    class="image-viewer__button"
                    :aria-label="t('productImage.zoomIn')"
                    :disabled="!loaded || view.zoom >= VIEWER_MAX_ZOOM"
                    @click="zoomTo(view.zoom + ZOOM_STEP)"
                >
                    <ZoomIn aria-hidden="true" />
                </button>
            </div>
        </div>
    </IonModal>
</template>

<style scoped>
.image-viewer {
    --width: 100%;
    --height: 100%;
    --border-radius: 0;
    --background: var(--viewer-bg);
    --backdrop-opacity: 1;
}

.image-viewer__body {
    position: relative;
    height: 100%;
    color: var(--viewer-text);
}

.image-viewer__frame {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    touch-action: none;
    user-select: none;
    -webkit-user-select: none;
    cursor: zoom-in;
}

.image-viewer__frame:focus-visible {
    outline-offset: calc(-1 * var(--space-1));
}

.image-viewer__image {
    flex: none;
    max-width: none;
    transform-origin: center;
    pointer-events: none;
}

.image-viewer__image--loading {
    max-width: 100%;
    max-height: 100%;
    opacity: 0;
}

.image-viewer__error {
    padding: var(--space-4);
    font-size: var(--font-size-text);
    text-align: center;
}

.image-viewer__button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--tap-size);
    height: var(--tap-size);
    border-radius: 50%;
    background: var(--viewer-button-bg);
    color: var(--viewer-text);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
}

.image-viewer__button:disabled {
    opacity: 0.4;
    cursor: default;
}

.image-viewer__button :deep(svg) {
    width: var(--icon-size-md);
    height: var(--icon-size-md);
}

.image-viewer__close {
    position: absolute;
    top: calc(var(--space-4) + env(safe-area-inset-top, 0px));
    right: calc(var(--space-4) + env(safe-area-inset-right, 0px));
}

.image-viewer__zoom {
    position: absolute;
    bottom: calc(var(--space-6) + env(safe-area-inset-bottom, 0px));
    left: 50%;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-1);
    border-radius: var(--radius-pill);
    background: var(--viewer-button-bg);
    transform: translateX(-50%);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
}

.image-viewer__zoom .image-viewer__button {
    background: transparent;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}

.image-viewer__percent {
    min-width: 3.5rem;
    font-size: var(--font-size-note);
    font-variant-numeric: tabular-nums;
    font-weight: var(--font-weight-semibold);
    text-align: center;
}
</style>
