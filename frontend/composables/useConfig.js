/**
 * Einstellungen vom Server (`GET /config`), einmal je Sitzung geladen.
 * Bis die Antwort da ist (oder ohne Verbindung), gelten die Standardwerte;
 * sie entsprechen den Projekteinstellungen von `piklis`.
 */
import { ref } from 'vue';
import { api } from '@/api/index.js';
import { onLogout } from './useSession.js';

const DEFAULT_IMAGES = {
    // media_maxUploadSize: längste Seite des Originals
    maxPixels: 2048,
    // media_maxUploadFileSize: 15 MB
    maxBytes: 15 * 1024 * 1024,
    // Seitenlänge des Zuschnitts
    cropSize: 1600
};

const images = ref({ ...DEFAULT_IMAGES });

let pending = null;
let loaded = false;

onLogout(() => {
    images.value = { ...DEFAULT_IMAGES };
    pending = null;
    loaded = false;
});

/**
 * Lädt die Einstellungen, falls noch nicht geschehen. Fehler bleiben still
 * (die Standardwerte gelten weiter, der Server prüft ohnehin selbst).
 */
export function loadConfig() {
    if (loaded) {
        return Promise.resolve();
    }

    pending ??= api.getConfig()
        .then((data) => {
            images.value = { ...DEFAULT_IMAGES, ...(data?.images ?? {}) };
            loaded = true;
        })
        .catch((error) => console.error(error))
        .finally(() => {
            pending = null;
        });

    return pending;
}

export function useConfig() {
    return { images };
}
