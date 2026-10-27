/**
 * Kleines i18n-Modul ohne Bibliothek: Texte in `locales/<sprache>.js`,
 * Zahlen, Daten und Mehrzahl über Intl. QUIQQERs `locale.xml` enthält nur
 * Backend-Texte (Rechte, Cron, Einstellungen).
 *
 * Piklis ist vorerst nur Deutsch: keine Sprachauswahl, keine Erkennung der
 * Gerätesprache. Alle Texte laufen trotzdem über t(), damit eine weitere
 * Sprache (z. B. pl) später nur `locales/pl.js`, einen Eintrag in `messages`
 * und eine Auswahl braucht (siehe docs/architecture.md, „Texts“).
 */
import { ref } from 'vue';
import de from './locales/de.js';

const messages = { de };

export const FALLBACK_LOCALE = 'de';

export const locale = ref(FALLBACK_LOCALE);

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

/** Tag als Überschrift: de „Dienstag, 27. Oktober“. */
export function formatDayHeading(value) {
    return new Intl.DateTimeFormat(locale.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long'
    }).format(new Date(value));
}
