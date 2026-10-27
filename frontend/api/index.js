/**
 * Einziger Weg der App zum Server. Später bekommt nur dieses Modul den
 * Bearer-Token (native App, OAuth); die Komponenten bleiben unverändert.
 *
 * - REST: quiqqer/rest unter /api/piklis/v1, Anmeldung über die
 *   QUIQQER-Session (Cookie). Fehler: { error: 'code', message: '…' }.
 * - Anmelden/Abmelden: Core-Funktionen ajax_users_login und
 *   ajax_user_logout über /ajax.php (IP-Sperre, Fehlversuche, 2FA bleiben
 *   im Core). Die zurückgegebene Login-Vorlage wird ignoriert.
 *
 * Pfade absolut: Die App ist immer die Startseite `/` der Instanz. Im
 * Vite-Entwicklungsserver leitet ein Proxy beide Pfade an die Instanz weiter.
 */
import { locale } from '@/i18n/index.js';

const REST_BASE = '/api/piklis/v1';
const AJAX_URL = '/ajax.php';
const LOGIN_AUTHENTICATOR = 'QUI\\Users\\Auth\\QUIQQER';

export class ApiError extends Error {
    constructor(status, code, message) {
        super(message || code);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
    }
}

let unauthorizedHandler = null;

/**
 * Wird bei 401 einer REST-Anfrage aufgerufen (Session abgelaufen).
 */
export function onUnauthorized(handler) {
    unauthorizedHandler = handler;
}

/**
 * @param {string} method
 * @param {string} path
 * @param {object} [body]
 * @param {{ signal?: AbortSignal }} [options] signal bricht die Anfrage ab (AbortError)
 */
async function request(method, path, body = undefined, { signal } = {}) {
    const headers = {
        Accept: 'application/json',
        'Accept-Language': locale.value
    };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(REST_BASE + path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
            signal
        });
    } catch (error) {
        if (error?.name === 'AbortError') {
            throw error;
        }

        throw new ApiError(0, 'network', 'Network error');
    }

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        const error = new ApiError(response.status, data?.error ?? 'http_' + response.status, data?.message);

        if (response.status === 401 && unauthorizedHandler) {
            unauthorizedHandler(error);
        }

        throw error;
    }

    return data;
}

/**
 * Ruft eine QUIQQER-Ajax-Funktion auf. Antwort: `<quiqqer>{JSON}</quiqqer>`,
 * darin je Funktion `result` oder `Exception` (mit übersetzter Meldung).
 */
async function callAjax(functionName, params = {}) {
    const body = new URLSearchParams({
        _rf: JSON.stringify([functionName]),
        _FRONTEND: '1',
        lang: locale.value,
        ...params
    });

    let text;

    try {
        const response = await fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body
        });

        text = await response.text();
    } catch {
        throw new ApiError(0, 'network', 'Network error');
    }

    const match = text.match(/<quiqqer>([\s\S]*)<\/quiqqer>/);
    const data = match ? JSON.parse(match[1]) : null;
    const entry = data?.[functionName];

    if (!entry) {
        throw new ApiError(500, 'invalid_response', 'Invalid response');
    }

    if (entry.Exception) {
        throw new ApiError(entry.Exception.code || 500, 'ajax', entry.Exception.message);
    }

    return entry.result;
}

export const api = {
    /** Eigener Benutzer: { id, name, initials, avatarUrl, settings, permissions } */
    getMe() {
        return request('GET', '/me');
    },

    /**
     * @param {{ theme?: 'auto'|'light'|'dark', language?: 'auto'|'de'|'en' }} settings
     */
    updateSettings(settings) {
        return request('PATCH', '/me/settings', settings);
    },

    async login(username, password) {
        const result = await callAjax('ajax_users_login', {
            authenticator: LOGIN_AUTHENTICATOR,
            authStep: 'primary',
            params: JSON.stringify({ username, password })
        });

        // Zwei-Faktor-Anmeldung kann das eigene Formular (noch) nicht
        if (!result?.loggedIn) {
            throw new ApiError(401, 'second_factor_required', null);
        }

        return result.user;
    },

    logout() {
        return callAjax('ajax_user_logout');
    }
};
