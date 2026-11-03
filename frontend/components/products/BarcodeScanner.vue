<script setup>
/**
 * Barcode mit der Kamera scannen: Rückkamera im Rahmen, Erkennung etwa alle
 * 150 ms. Ein Barcode gilt erst, wenn er zweimal hintereinander gleich und
 * mit gültiger Prüfziffer erkannt wird (vermeidet Lesefehler). Die
 * Erkennung (`utils/barcodeScanner.js`, auf dem iPhone mit zxing-wasm) wird
 * erst hier nachgeladen. Kamera und Schleife enden beim Verlassen.
 *
 * Emits: detected(barcode), failed(reason: denied · unavailable · failed)
 */
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { parseBarcode } from '@/utils/barcode.js';
import { t } from '@/i18n/index.js';

const SCAN_INTERVAL = 150;

const emit = defineEmits(['detected', 'failed']);

const statusId = useId();
const video = ref(null);
const state = ref('starting'); // starting · loading · scanning · found · denied · unavailable · failed
const found = ref('');

let stream = null;
let detector = null;
let timer = null;
let stopped = false;
let lastCode = '';

const message = computed(() => {
    if (state.value === 'found') {
        return t('foodFacts.scanner.found', { barcode: found.value });
    }

    return t('foodFacts.scanner.' + state.value);
});

const failed = computed(() => ['denied', 'unavailable', 'failed'].includes(state.value));

function fail(reason, error) {
    if (error) {
        console.warn('Scanner', error);
    }

    state.value = reason;
    stop();
    emit('failed', reason);
}

async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw Object.assign(new Error('no_camera'), { name: 'NotFoundError' });
    }

    return navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 }
        }
    });
}

async function loadDetector() {
    const { createDetector } = await import('@/utils/barcodeScanner.js');

    return createDetector();
}

async function scan() {
    timer = null;

    if (stopped) {
        return;
    }

    try {
        if (video.value && video.value.readyState >= 2) {
            const codes = await detector.detect(video.value);
            const code = codes
                .map((item) => parseBarcode(item.rawValue).barcode)
                .find((barcode) => barcode !== null);

            if (code && code === lastCode) {
                found.value = code;
                state.value = 'found';
                stop();
                emit('detected', code);
                return;
            }

            lastCode = code ?? '';
        }
    } catch {
        // einzelne Bilder dürfen scheitern (z. B. noch kein Bild da); nächstes Bild
    }

    if (!stopped) {
        timer = setTimeout(scan, SCAN_INTERVAL);
    }
}

function stop() {
    stopped = true;

    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

onMounted(async () => {
    let detectorPromise = null;

    try {
        // Scanner schon laden, während die Kamera um Erlaubnis fragt
        detectorPromise = loadDetector();
        detectorPromise.catch(() => {});

        stream = await startCamera();

        if (stopped) {
            stop();
            return;
        }

        video.value.srcObject = stream;
        await video.value.play().catch(() => {});
    } catch (error) {
        fail(error?.name === 'NotAllowedError' || error?.name === 'SecurityError' ? 'denied' : 'unavailable', error);
        return;
    }

    try {
        state.value = 'loading';
        detector = await detectorPromise;
    } catch (error) {
        fail('failed', error);
        return;
    }

    if (!stopped) {
        state.value = 'scanning';
        scan();
    }
});

onBeforeUnmount(stop);
</script>

<template>
    <div class="scanner">
        <div v-if="!failed" class="scanner__view">
            <video
                ref="video"
                class="scanner__video"
                :aria-label="t('foodFacts.scanner.label')"
                :aria-describedby="statusId"
                playsinline
                muted
                autoplay
            ></video>
            <span class="scanner__frame" aria-hidden="true"></span>
        </div>
        <p
            :id="statusId"
            class="scanner__status"
            :class="{ 'scanner__status--error': failed }"
            role="status"
        >
            {{ message }}
        </p>
    </div>
</template>

<style scoped>
.scanner {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.scanner__view {
    position: relative;
    overflow: hidden;
    aspect-ratio: 4 / 3;
    border-radius: var(--radius-md);
    background: var(--viewer-bg);
}

.scanner__video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Zielrahmen in Barcode-Form, außen abgedunkelt */
.scanner__frame {
    position: absolute;
    inset: 30% 12%;
    border: 3px solid var(--viewer-text);
    border-radius: var(--radius-sm);
    box-shadow: 0 0 0 100vmax var(--scanner-shade);
}

.scanner__status {
    color: var(--color-text-2);
    font-size: var(--font-size-text);
    line-height: var(--line-height-text);
}

.scanner__status--error {
    color: var(--color-text-1);
}
</style>
