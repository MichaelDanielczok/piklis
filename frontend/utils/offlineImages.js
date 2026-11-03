/**
 * Produktbilder offline: Der Service Worker legt Bilder des Media-Caches in
 * Listen- und Detailgröße (`__128x128.webp`, `__640x640.webp`) in den Cache
 * `pk-images` (CacheFirst; die Adressen ändern sich bei jedem neuen Bild,
 * siehe vite.config.js). Die App holt die Bilder der Produkte auf ihren
 * aktiven Listen vorab (`warmImages`), damit sie auch dann offline da sind,
 * wenn eine Liste noch nie geöffnet war.
 *
 * Nur mit aktivem Service Worker (Build); im Entwicklungsserver tut das
 * Modul nichts.
 */

export const IMAGE_CACHE = 'pk-images';

/** Die Größen, die der Service Worker cacht. */
export const OFFLINE_IMAGE_PATTERN = /^\/media\/cache\/.+__(128x128|640x640)\.webp$/;

const PARALLEL = 4;

const done = new Set();
let running = null;
let waiting = [];

function canWarm() {
    return typeof caches !== 'undefined'
        && typeof navigator !== 'undefined'
        && Boolean(navigator.serviceWorker?.controller);
}

function cacheable(url) {
    try {
        const parsed = new URL(url, location.href);

        return parsed.origin === location.origin && OFFLINE_IMAGE_PATTERN.test(parsed.pathname);
    } catch {
        return false;
    }
}

async function warmOne(cache, url) {
    if (await cache.match(url)) {
        done.add(url);
        return;
    }

    try {
        // geht durch den Service Worker, der die Antwort ablegt
        await fetch(url, { credentials: 'same-origin' });
        // auch bei 404: nicht bei jedem Speichern erneut versuchen
        done.add(url);
    } catch {
        // offline: beim nächsten Mal
    }
}

async function run() {
    const cache = await caches.open(IMAGE_CACHE);

    while (waiting.length > 0) {
        const batch = waiting.splice(0, PARALLEL);

        await Promise.all(batch.map((url) => warmOne(cache, url)));
    }
}

/**
 * Bilder vorab in den Offline-Cache holen (nur, was noch fehlt, höchstens
 * vier gleichzeitig). Mehrfache Aufrufe reihen sich ein.
 *
 * @param {string[]} urls
 */
export function warmImages(urls) {
    if (!canWarm()) {
        return Promise.resolve();
    }

    const fresh = urls.filter((url) => !done.has(url) && !waiting.includes(url) && cacheable(url));

    waiting.push(...fresh);

    return start();
}

function start() {
    if (running === null && waiting.length > 0) {
        running = run()
            .catch((error) => {
                waiting = [];
                console.warn('Bilder nicht vorab geladen', error);
            })
            .finally(() => {
                running = null;
                // inzwischen Eingereihtes
                start();
            });
    }

    return running ?? Promise.resolve();
}

/**
 * Bilder im Offline-Cache: Anzahl und Größe in Bytes (für die Einstellungen).
 *
 * @returns {Promise<{ count: number, bytes: number }|null>} null ohne Cache
 */
export async function imageCacheSize() {
    if (typeof caches === 'undefined' || !(await caches.has(IMAGE_CACHE))) {
        return null;
    }

    const cache = await caches.open(IMAGE_CACHE);
    const requests = await cache.keys();
    let bytes = 0;

    for (const request of requests) {
        const response = await cache.match(request);

        bytes += response ? (await response.blob()).size : 0;
    }

    return { count: requests.length, bytes };
}

/** Abmelden: Bilder bleiben (sie sind ohne Anmeldung abrufbar), nur der Merker leert sich. */
export function forgetWarmedImages() {
    done.clear();
    waiting = [];
}
