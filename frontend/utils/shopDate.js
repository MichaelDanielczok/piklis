/**
 * Einkaufsdatum einer Liste (`YYYY-MM-DD`, optional): kurz und relativ zu
 * heute, wie im Designpaket („heute“, „Mo“, „Sa, 24. Okt.“).
 *
 * - gestern · heute · morgen
 * - die nächsten Tage dieser Woche (2 bis 6 Tage voraus): Wochentag kurz
 * - sonst Wochentag, Tag und Monat, in einem anderen Jahr mit Jahr
 */
import { locale, t } from '@/i18n/index.js';

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const DAY_MS = 24 * 60 * 60 * 1000;

/** `YYYY-MM-DD` als lokales Datum (Mitternacht), null bei ungültiger Eingabe. */
export function parseShopDate(value) {
    const match = DATE.exec(String(value ?? ''));

    if (!match) {
        return null;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return date.getMonth() === Number(match[2]) - 1 ? date : null;
}

/** Lokales Datum als `YYYY-MM-DD` (für Liste und Datumsfeld). */
export function toShopDate(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Heute bzw. in `days` Tagen als `YYYY-MM-DD`. */
export function shopDateFromToday(days = 0, now = new Date()) {
    return toShopDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() + days));
}

/**
 * @param {string|null} value
 * @param {Date} [now]
 * @returns {string} leer ohne Datum
 */
export function formatShopDate(value, now = new Date()) {
    const date = parseShopDate(value);

    if (!date) {
        return '';
    }

    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const days = Math.round((date.getTime() - today.getTime()) / DAY_MS);

    if (days === 0) {
        return t('dates.today');
    }

    if (days === 1) {
        return t('dates.tomorrow');
    }

    if (days === -1) {
        return t('dates.yesterday');
    }

    if (days > 1 && days < 7) {
        return new Intl.DateTimeFormat(locale.value, { weekday: 'short' }).format(date).replace(/\.$/, '');
    }

    const options = { weekday: 'short', day: 'numeric', month: 'short' };

    if (date.getFullYear() !== today.getFullYear()) {
        options.year = 'numeric';
    }

    return new Intl.DateTimeFormat(locale.value, options).format(date);
}
