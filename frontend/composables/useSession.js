/**
 * Angemeldeter Benutzer und seine Einstellungen.
 * Zustand liegt modulweit (kein Pinia), alle Komponenten teilen ihn.
 *
 * status: 'unknown' (noch nicht geprüft) · 'authenticated' · 'anonymous'
 *
 * Offline-Start: Ist der Server nicht erreichbar (kein Netz, 5xx), gilt der
 * zuletzt angemeldete Benutzer aus dem lokalen Speicher (`localStore`), die
 * App startet mit dem lokalen Stand. Ohne ihn steht `unreachable` auf true,
 * die Anmeldeseite sagt das gleich. Nach dem Anmelden (oder Offline-Start)
 * laufen die mit `onSessionStart` gemeldeten Schritte (lokalen Stand laden,
 * Warteschlange senden), bevor die erste Ansicht erscheint.
 */
import { computed, ref, watch } from 'vue';
import { api, ApiError } from '@/api/index.js';
import { localStore } from '@/api/localStore.js';

const THEME_STORAGE_KEY = 'pk.theme';

const user = ref(null);
const status = ref('unknown');

/** Beim letzten Prüfen war der Server nicht erreichbar und kein lokaler Benutzer da. */
const unreachable = ref(false);

/** Gestartet ohne Server, mit dem lokal gespeicherten Benutzer. */
const offlineSession = ref(false);

let pendingLoad = null;

// Andere Module melden hier, was beim Abmelden geleert werden muss
const resetHandlers = new Set();

// … und was nach dem Anmelden (auch offline) geschehen soll
const startHandlers = new Set();

// Benutzer für den Offline-Start merken (Name, Rechte, Haushalt, Einstellungen)
watch(user, (value) => {
    if (value && status.value === 'authenticated') {
        localStore.data.set('me', value).catch((error) => console.warn('Benutzer nicht lokal gespeichert', error));
    }
});

function isUnreachable(error) {
    return error instanceof ApiError && (error.status === 0 || error.status >= 500);
}

/**
 * Lokalen Stand dem Benutzer zuordnen (ein anderer vorher: alles leeren)
 * und die Startschritte der anderen Module ausführen.
 */
async function startSession(current) {
    const changed = await localStore.bindUser(current.id);

    if (changed) {
        resetHandlers.forEach((handler) => handler());
    }

    await Promise.all([...startHandlers].map(async (handler) => {
        try {
            await handler(current);
        } catch (error) {
            console.error(error);
        }
    }));
}

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
                const me = await api.getMe();

                unreachable.value = false;
                offlineSession.value = false;
                user.value = me;
                await startSession(me);
                status.value = 'authenticated';
                applySettings(me.settings);
                localStore.data.set('me', me).catch((storeError) => console.warn('Benutzer nicht lokal gespeichert', storeError));
            } catch (error) {
                const cached = isUnreachable(error) ? await localStore.data.get('me').catch(() => null) : null;

                if (cached?.id) {
                    // ohne Server: mit dem zuletzt angemeldeten Benutzer und dem lokalen Stand
                    unreachable.value = false;
                    offlineSession.value = true;
                    user.value = cached;
                    await startSession(cached);
                    status.value = 'authenticated';
                    applySettings(cached.settings ?? {});
                    return;
                }

                user.value = null;
                status.value = 'anonymous';
                unreachable.value = isUnreachable(error);

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
        // Abmelden löscht die Offline-Kopien und nicht übertragene Änderungen
        await localStore.wipe().catch((error) => console.warn('Lokaler Speicher nicht geleert', error));
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

/**
 * Nach dem Anmelden (auch beim Offline-Start mit dem lokalen Benutzer),
 * bevor die erste Ansicht erscheint: `handler(user)`, darf ein Promise
 * zurückgeben.
 */
export function onSessionStart(handler) {
    startHandlers.add(handler);
}

/**
 * Session ist serverseitig weg: Abmeldung (alles leeren) oder abgelaufen
 * (`expired`, 401). Abgelaufen bleibt der lokale Stand samt nicht
 * übertragener Änderungen; nach dem Anmelden desselben Benutzers geht es
 * weiter, bei einem anderen wird vorher alles gelöscht (`startSession`).
 */
export function markLoggedOut({ expired = false } = {}) {
    if (!expired) {
        resetHandlers.forEach((handler) => handler());
    }

    user.value = null;
    status.value = 'anonymous';
    offlineSession.value = false;
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

/**
 * Haushalt in Kurzform `{ id, name, role }` oder null nach Anlegen, Beitritt,
 * Austritt usw. (aus `GET /me`, danach aus `useHousehold.js`).
 */
export function setSessionHousehold(household) {
    if (!user.value) {
        return;
    }

    user.value = {
        ...user.value,
        household: household ? { id: household.id, name: household.name, role: household.role } : null
    };
}

export function useSession() {
    return {
        user,
        status,
        isAuthenticated: computed(() => status.value === 'authenticated'),
        // Server beim Start nicht erreichbar und kein lokaler Benutzer
        unreachable,
        // offline gestartet (lokaler Benutzer)
        offlineSession,
        // Haushalt des Benutzers { id, name, role } oder null: Standard für neue Listen
        household: computed(() => user.value?.household ?? null),
        // Recht piklis.products.manage: alle Produkte pflegen
        canManageProducts: computed(() => user.value?.permissions?.manageProducts === true)
    };
}
