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
 * @param {object|FormData} [body] JSON, oder FormData für Dateien (multipart)
 * @param {{ signal?: AbortSignal }} [options] signal bricht die Anfrage ab (AbortError)
 */
async function request(method, path, body = undefined, { signal } = {}) {
    const headers = {
        Accept: 'application/json',
        'Accept-Language': locale.value
    };
    const isForm = body instanceof FormData;

    if (body !== undefined && !isForm) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(REST_BASE + path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined || isForm ? body : JSON.stringify(body),
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

        error.reason = data?.reason ?? null;

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
     * @param {{ theme?: 'auto'|'light'|'dark' }} settings
     */
    updateSettings(settings) {
        return request('PATCH', '/me/settings', settings);
    },

    /**
     * Einstellungen vom Server: { images: { maxPixels, maxBytes, cropSize } }
     * (Grenzen der Produktbilder aus den Projekteinstellungen).
     */
    getConfig() {
        return request('GET', '/config');
    },

    /**
     * Operationen senden (höchstens 200), Antwort `{ results: [...] }` in
     * derselben Reihenfolge. Nur über `api/operations.js` benutzen.
     */
    sendOperations(ops, { signal } = {}) {
        return request('POST', '/ops', { ops }, { signal });
    },

    /** Zugängliche Listen ohne Einträge: { lists: [{ ...list, role, counts }] } */
    getLists({ signal } = {}) {
        return request('GET', '/lists', undefined, { signal });
    },

    /**
     * Eine Liste: { list, revision, full, entries }. Mit `since` nur Einträge,
     * die sich seit dieser Revision geändert haben (auch entfernte).
     */
    getList(listId, since = null, { signal } = {}) {
        const query = since === null || since === undefined ? '' : '?since=' + encodeURIComponent(since);

        return request('GET', '/lists/' + encodeURIComponent(listId) + query, undefined, { signal });
    },

    /**
     * Persönliche Liste in den eigenen Haushalt verschieben (ID bleibt):
     * { list }. Nur der Besitzer.
     */
    moveListToHousehold(listId) {
        return request('POST', '/lists/' + encodeURIComponent(listId) + '/move', { to: 'household' });
    },

    /**
     * Liste kopieren, neue ID aus dem Browser: { status, list }.
     * `to`: 'household' (persönliche Liste in den Haushalt) oder 'personal'
     * (Haushaltsliste als persönliche Kopie).
     */
    copyList(listId, newListId, to) {
        return request('POST', '/lists/' + encodeURIComponent(listId) + '/copy', { id: newListId, to });
    },

    /**
     * Einladungslink zu einer Liste erzeugen (Besitzer, Mitglieder):
     * { token, invite: { id, createdBy, createdAt, expiresAt } }. Das Token
     * gibt es nur in dieser Antwort.
     */
    createListInvite(listId) {
        return request('POST', '/lists/' + encodeURIComponent(listId) + '/invites');
    },

    /**
     * Teilnehmer und offene Links einer Liste: { shares: [{ id, name,
     * initials, avatarUrl, invitedBy, acceptedAt }], invites: [...] }.
     */
    getListShares(listId, { signal } = {}) {
        return request('GET', '/lists/' + encodeURIComponent(listId) + '/shares', undefined, { signal });
    },

    /**
     * Freigabe entziehen: wie getListShares. Mit der eigenen ID verlässt ein
     * Gast die Liste: { status: 'left' }.
     */
    removeListShare(listId, userId) {
        return request(
            'DELETE',
            '/lists/' + encodeURIComponent(listId) + '/shares/' + encodeURIComponent(userId)
        );
    },

    /** Offenen Link zurückziehen: wie getListShares. */
    withdrawListInvite(listId, inviteId) {
        return request(
            'DELETE',
            '/lists/' + encodeURIComponent(listId) + '/invites/' + encodeURIComponent(inviteId)
        );
    },

    /**
     * Einladung ansehen: { invite: { list: { id, name, shopDate }, invitedBy,
     * expiresAt }, access: null | 'owner' | 'member' | 'guest' }.
     * Ungültig: `invite_invalid` (404/410) mit `reason`.
     */
    getInvite(token, { signal } = {}) {
        return request('GET', '/invites/' + encodeURIComponent(token), undefined, { signal });
    },

    /** Einladung annehmen: { status: 'accepted' | 'already', list }. */
    acceptInvite(token) {
        return request('POST', '/invites/' + encodeURIComponent(token) + '/accept');
    },

    /**
     * Eigener Haushalt: { household: { id, name, role, members, invite } | null }.
     * `invite` ({ code, expiresAt }) nur für Administratoren.
     */
    getHousehold() {
        return request('GET', '/household');
    },

    /** Haushalt anlegen (man wird Administrator): wie getHousehold. */
    createHousehold(name) {
        return request('POST', '/household', { name });
    },

    /**
     * Mit Einladungscode beitreten. `switchHousehold`: den bisherigen Haushalt
     * dabei verlassen; `confirmDissolve`: als letztes Mitglied auflösen.
     */
    joinHousehold(code, { switchHousehold = false, confirmDissolve = false } = {}) {
        return request('POST', '/household/join', { code, switch: switchHousehold, confirmDissolve });
    },

    /** Neuer Einladungscode (Administratoren): wie getHousehold. */
    renewHouseholdInvite() {
        return request('POST', '/household/invite');
    },

    /** Austreten; als letztes Mitglied nur mit `confirmDissolve`. */
    leaveHousehold({ confirmDissolve = false } = {}) {
        return request('POST', '/household/leave', { confirmDissolve });
    },

    /** Auflösen (Administratoren): Haushalt und seine Listen werden gelöscht. */
    dissolveHousehold() {
        return request('DELETE', '/household');
    },

    /** Rolle eines Mitglieds: 'admin' oder 'member' (Administratoren). */
    setHouseholdRole(userId, role) {
        return request('PATCH', '/household/members/' + encodeURIComponent(userId), { role });
    },

    /** Mitglied entfernen (Administratoren). */
    removeHouseholdMember(userId) {
        return request('DELETE', '/household/members/' + encodeURIComponent(userId));
    },

    /**
     * Ganzer Katalog inkl. ausgeblendeter Produkte (`hidden: true`):
     * { products }. ETag: Der Browser fragt bedingt nach (304 aus dem Cache).
     */
    getProducts({ signal } = {}) {
        return request('GET', '/products', undefined, { signal });
    },

    /** Suche auf dem Server: { products } ohne ausgeblendete, beste zuerst. */
    searchProducts(query, { limit, signal } = {}) {
        const params = new URLSearchParams({ q: query });

        if (limit) {
            params.set('limit', String(limit));
        }

        return request('GET', '/products/search?' + params.toString(), undefined, { signal });
    },

    /**
     * Vorschläge für „Produkte hinzufügen“: Produkte der letzten 90 Tage,
     * gewichtet, beste zuerst: { days, suggestions: [{ productId, score, uses, lastUsedAt }] }.
     */
    getSuggestions({ signal } = {}) {
        return request('GET', '/products/suggestions', undefined, { signal });
    },

    /**
     * Produkt anlegen, ID aus dem Browser: { status: 'created'|'unchanged', product }.
     *
     * @param {{ id: string, name: string, category?: string|null, icon?: string|null,
     *   description?: string|null, packageSize?: string|null }} product
     */
    createProduct(product) {
        return request('POST', '/products', product);
    },

    /** Geänderte Felder speichern: { product }. 403 forbidden ohne Recht. */
    updateProduct(productId, changes) {
        return request('PATCH', '/products/' + encodeURIComponent(productId), changes);
    },

    /** Nur Produktpfleger: { product } */
    setProductHidden(productId, hidden) {
        return request('POST', '/products/' + encodeURIComponent(productId) + (hidden ? '/hide' : '/unhide'));
    },

    /**
     * Produktbild setzen oder ersetzen: { product }. `image` ist der
     * quadratische Zuschnitt (JPEG, cropSize px), `original` das verkleinerte
     * Original; ohne `original` bleibt das bisherige (neu zuschneiden).
     *
     * @param {string} productId
     * @param {{ image: Blob, original?: Blob|null }} files
     */
    uploadProductImage(productId, { image, original = null }) {
        const form = new FormData();

        form.append('image', image, 'image.jpg');

        if (original) {
            form.append('original', original, 'original.jpg');
        }

        return request('POST', '/products/' + encodeURIComponent(productId) + '/image', form);
    },

    /** Produktbild entfernen (wieder das Ersatzicon): { product } */
    removeProductImage(productId) {
        return request('DELETE', '/products/' + encodeURIComponent(productId) + '/image');
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
