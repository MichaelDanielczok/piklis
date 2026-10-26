/**
 * Kleines i18n-Modul ohne Bibliothek: Texte in `locales/<sprache>.js`,
 * Zahlen, Daten und Mehrzahl über Intl. QUIQQERs `locale.xml` enthält nur
 * Backend-Texte (Rechte, Cron, Einstellungen).
 *
 * Sprache: Benutzereinstellung, sonst zuletzt gewählte (localStorage), sonst
 * Gerätesprache, sonst FALLBACK_LOCALE.
 *
 * Weitere Sprache (z. B. pl): `locales/pl.js` anlegen und hier in `messages`
 * und `LOCALE_NAMES` eintragen, dazu `Profile::LANGUAGES` in PHP
 * (siehe docs/architecture.md, „Texts“).
 */
import { ref } from 'vue';
import de from './locales/de.js';
import en from './locales/en.js';

const messages = { de, en };

/** Sprachen der App mit ihrem Namen in der eigenen Sprache (Auswahl im Profil). */
export const LOCALE_NAMES = {
    de: 'Deutsch',
    en: 'English'
};

export const SUPPORTED_LOCALES = Object.keys(LOCALE_NAMES);
export const FALLBACK_LOCALE = 'de';

const STORAGE_KEY = 'pwastarter.language';

function readStoredLocale() {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function detectLocale() {
    const stored = readStoredLocale();

    if (SUPPORTED_LOCALES.includes(stored)) {
        return stored;
    }

    const deviceLanguages = navigator.languages ?? [navigator.language];

    for (const language of deviceLanguages) {
        const base = String(language).toLowerCase().split('-')[0];

        if (SUPPORTED_LOCALES.includes(base)) {
            return base;
        }
    }

    return FALLBACK_LOCALE;
}

export const locale = ref(detectLocale());

/**
 * Setzt die Sprache. `null` bedeutet „Automatisch“ (Gerätesprache).
 */
export function setLocale(newLocale) {
    try {
        if (newLocale === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, newLocale);
        }
    } catch {
        // ohne localStorage gilt die Wahl nur bis zum Neuladen
    }

    locale.value = newLocale === null ? detectLocale() : newLocale;
    applyDocumentLanguage();
}

export function applyDocumentLanguage() {
    document.documentElement.lang = locale.value;
}

function lookup(source, key) {
    return key.split('.').reduce((node, part) => node?.[part], source);
}

/** Platzhalter: `{name}` */
function interpolate(text, params) {
    return text.replace(/\{(\w+)\}/g, (match, name) => {
        if (!(name in params)) {
            return match;
        }

        const value = params[name];

        return typeof value === 'number' ? formatNumber(value) : String(value);
    });
}

/**
 * Text zu einem Schlüssel, z. B. t('home.greeting', { name }). Ist der Text
 * ein Objekt mit Mehrzahlformen, wählt `count` die Form der Sprache
 * (Intl.PluralRules: de/en one · other, pl one · few · many · other),
 * z. B. t('home.days', { count: 5 }).
 */
export function t(key, params = {}) {
    let message = lookup(messages[locale.value], key) ?? lookup(messages[FALLBACK_LOCALE], key);

    if (message !== null && typeof message === 'object' && typeof params.count === 'number') {
        const category = new Intl.PluralRules(locale.value).select(params.count);
        message = message[category] ?? message.other;
    }

    if (typeof message !== 'string') {
        return key;
    }

    return interpolate(message, params);
}

export function formatNumber(value, options = {}) {
    return new Intl.NumberFormat(locale.value, options).format(value);
}

/** Datum kurz: de „14.03.2025“. */
export function formatDate(value) {
    return new Intl.DateTimeFormat(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    }).format(new Date(value));
}

/** Datum mit Uhrzeit: de „06.10.2026, 07:04“. */
export function formatDateTime(value) {
    return new Intl.DateTimeFormat(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }).format(new Date(value));
}
