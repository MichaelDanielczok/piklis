/**
 * Barcode-Erkennung für den Scanner, nur beim Scannen geladen
 * (`import('@/utils/barcodeScanner.js')` in `BarcodeScanner.vue`).
 *
 * Kann der Browser selbst Barcodes lesen (BarcodeDetector, z. B. Chrome
 * unter Android und macOS), nimmt der Scanner das. Sonst (Safari, iPhone)
 * kommt `barcode-detector` (Ersatz mit zxing-wasm, nur Lesen) dazu. Die
 * WebAssembly-Datei kommt vom eigenen Server (Build-Asset), nie von einem
 * CDN; der Service Worker cacht sie nicht vorab.
 */
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

/** Lebensmittel-Barcodes (EAN/UPC) */
export const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

async function nativeDetector() {
    const Native = globalThis.BarcodeDetector;

    if (typeof Native !== 'function' || typeof Native.getSupportedFormats !== 'function') {
        return null;
    }

    try {
        const supported = await Native.getSupportedFormats();
        const formats = FORMATS.filter((format) => supported.includes(format));

        return formats.length > 0 ? new Native({ formats }) : null;
    } catch {
        return null;
    }
}

/**
 * @returns {Promise<{ detect(source: CanvasImageSource): Promise<{ rawValue: string }[]> }>}
 */
export async function createDetector() {
    const native = await nativeDetector();

    if (native) {
        return native;
    }

    const { BarcodeDetector, prepareZXingModule } = await import('barcode-detector/ponyfill');
    const wasm = new URL(wasmUrl, import.meta.url).href;

    await prepareZXingModule({
        overrides: {
            locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasm : prefix + path)
        },
        fireImmediately: true
    });

    return new BarcodeDetector({ formats: FORMATS });
}
