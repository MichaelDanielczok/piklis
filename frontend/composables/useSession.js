/**
 * Angemeldeter Benutzer und seine Einstellungen.
 * Zustand liegt modulweit (kein Pinia), alle Komponenten teilen ihn.
 *
 * status: 'unknown' (noch nicht geprüft) · 'authenticated' · 'anonymous'
 */
import { computed, ref } from 'vue';
import { api, ApiError } from '@/api/index.js';

const THEME_STORAGE_KEY = 'pk.theme';

const user = ref(null);
const status = ref('unknown');

let pendingLoad = null;

// Andere Module melden hier, was beim Abmelden geleert werden muss
const resetHandlers = new Set();

/**
 * Motiv: 'light'/'dark' fest über data-theme, 'auto' folgt dem System.
 * Zusätzlich im localStorage, damit schon die Anmeldeseite passt.
 */
export function applyTheme(theme) {
    const root = document.documentElement;

    if (theme === 'light' || theme === 'dark') {
        root.dataset.theme = theme;
    } else {
        delete root.dataset.theme;
    }

    applyThemeColor(theme);

    try {
        if (theme === 'light' || theme === 'dark') {
            window.localStorage.setItem(THEME_STORAGE_KEY, theme);
        } else {
            window.localStorage.removeItem(THEME_STORAGE_KEY);
        }
    } catch {
        // ohne localStorage gilt das Motiv bis zum Neuladen
    }
}

/**
 * Browserleiste (theme-color): Das Grundgerüst hat je ein Meta-Tag für hell
 * und dunkel mit media-Abfrage, das reicht für 'auto'. Bei festem Motiv
 * bekommen beide die Hintergrundfarbe aus den Tokens.
 */
function applyThemeColor(theme) {
    const fixed = theme === 'light' || theme === 'dark';
    const color = fixed
        ? getComputedStyle(document.documentElement).getPropertyValue('--color-bg').trim()
        : '';

    document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => {
        meta.dataset.systemColor ??= meta.content;
        meta.content = color || meta.dataset.systemColor;
    });
}

/** Motiv vom letzten Besuch, vor der Anmeldung. */
export function applyStoredTheme() {
    try {
        applyTheme(window.localStorage.getItem(THEME_STORAGE_KEY) ?? 'auto');
    } catch {
        applyTheme('auto');
    }
}

function applySettings(settings) {
    applyTheme(settings.theme);
}

/**
 * Prüft die Session über GET /me. Mehrfache Aufrufe teilen eine Anfrage.
 */
export function loadSession() {
    if (!pendingLoad) {
        pendingLoad = (async () => {
            try {
                user.value = await api.getMe();
                status.value = 'authenticated';
                applySettings(user.value.settings);
            } catch (error) {
                user.value = null;
                status.value = 'anonymous';

                if (!(error instanceof ApiError) || error.status !== 401) {
                    throw error;
                }
            } finally {
                pendingLoad = null;
            }
        })();
    }

    return pendingLoad;
}

export async function login(username, password) {
    await api.login(username, password);
    await loadSession();
}

export async function logout() {
    try {
        await api.logout();
    } finally {
        markLoggedOut();
    }
}

/**
 * Zustand eines anderen Moduls beim Abmelden leeren (Listen, Filter …).
 *
 * @param {() => void} handler
 */
export function onLogout(handler) {
    resetHandlers.add(handler);
}

/** Session ist serverseitig weg (Abmeldung oder 401). */
export function markLoggedOut() {
    resetHandlers.forEach((handler) => handler());
    user.value = null;
    status.value = 'anonymous';
}

/**
 * Ändert Einstellungen sofort in der Oberfläche und speichert sie dann.
 * Schlägt das Speichern fehl, gilt wieder der vorherige Stand.
 */
export async function updateSettings(changes) {
    const previous = user.value.settings;

    user.value = {
        ...user.value,
        settings: { ...previous, ...changes }
    };
    applySettings(user.value.settings);

    try {
        user.value = await api.updateSettings(changes);
    } catch (error) {
        user.value = { ...user.value, settings: previous };
        applySettings(previous);
        throw error;
    }
}

export function useSession() {
    return {
        user,
        status,
        isAuthenticated: computed(() => status.value === 'authenticated'),
        // Recht piklis.products.manage: alle Produkte pflegen
        canManageProducts: computed(() => user.value?.permissions?.manageProducts === true)
    };
}
