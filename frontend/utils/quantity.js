/**
 * Mengen: Zahl plus optionale Einheit aus fester Liste. Dezimal nur bei kg und
 * l (höchstens 3 Nachkommastellen, Anzeige mit Komma), sonst ganze Zahlen ab 1;
 * höchstens 9999. Ohne Einheit „2 ד. Texte und Mehrzahl in i18n (`units.*`,
 * `unitNames.*`).
 *
 * Prüfung, Komma-Eingabe und Zahlanzeige gleich wie `Lists\Quantity` in PHP
 * (gemeinsame Testfälle in `tests/fixtures/quantities.json`).
 */
import { t, formatNumber } from '@/i18n/index.js';

export const UNITS = ['piece', 'pack', 'bottle', 'cup', 'can', 'jar', 'bag', 'net', 'bunch', 'roll', 'bar', 'g', 'kg', 'ml', 'l'];

export const DECIMAL_UNITS = ['kg', 'l'];

export const MAX_AMOUNT = 9999;

export const MAX_DECIMALS = 3;

const SCALE = 1000;

const MAX_INPUT_LENGTH = 20;

const GROUPED_NUMBER = /^\d{1,3}(\.\d{3})+(,\d+)?$/;

const DECIMAL_NUMBER = /^(\d+[,.]?\d*|[,.]\d+)$/;

export function allowsDecimals(unit) {
    return DECIMAL_UNITS.includes(unit);
}

/**
 * Fehlercode oder null, wenn die Menge gültig ist: `unknown_unit`,
 * `invalid_number`, `not_positive`, `not_integer`, `too_many_decimals`,
 * `too_large`.
 *
 * @param {number} amount
 * @param {string|null} unit
 * @returns {string|null}
 */
export function validateQuantity(amount, unit = null) {
    if (unit !== null && !UNITS.includes(unit)) {
        return 'unknown_unit';
    }

    if (typeof amount !== 'number' || !Number.isFinite(amount)) {
        return 'invalid_number';
    }

    if (amount <= 0) {
        return 'not_positive';
    }

    const thousandths = amount * SCALE;

    if (!allowsDecimals(unit)) {
        if (Math.round(amount) !== amount) {
            return 'not_integer';
        }
    } else if (Math.abs(thousandths - Math.round(thousandths)) > 1e-6) {
        return 'too_many_decimals';
    }

    if (amount > MAX_AMOUNT) {
        return 'too_large';
    }

    return null;
}

/**
 * Zahl aus einer Eingabe. Komma ist das Dezimalzeichen; ein Punkt trennt
 * Tausender, wenn danach genau drei Ziffern folgen („1.500“ = 1500, auch
 * „1.234,5“), sonst gilt ein einzelner Punkt als Dezimalzeichen („1.5“).
 *
 * @returns {{ amount: number|null, error: string|null }} error: `empty`, `invalid_number`
 */
export function parseAmount(input) {
    const text = String(input ?? '').trim();

    if (text === '') {
        return { amount: null, error: 'empty' };
    }

    if (text.length > MAX_INPUT_LENGTH) {
        return { amount: null, error: 'invalid_number' };
    }

    if (GROUPED_NUMBER.test(text)) {
        return { amount: Number(text.replaceAll('.', '').replace(',', '.')), error: null };
    }

    if (DECIMAL_NUMBER.test(text)) {
        return { amount: Number(text.replace(',', '.')), error: null };
    }

    return { amount: null, error: 'invalid_number' };
}

/**
 * Eingabe aus einem Textfeld samt Prüfung, z. B. parseQuantity('1,5', 'kg').
 *
 * @returns {{ amount: number|null, unit: string|null, error: string|null }}
 */
export function parseQuantity(input, unit = null) {
    const { amount, error } = parseAmount(input);

    if (error !== null) {
        return { amount: null, unit, error };
    }

    const invalid = validateQuantity(amount, unit);

    return invalid === null ? { amount, unit, error: null } : { amount: null, unit, error: invalid };
}

/** Zahl ohne Einheit („1,5“, „1.000“), z. B. für ein Eingabefeld. */
export function formatAmount(amount) {
    return formatNumber(Math.round(amount * SCALE) / SCALE, { maximumFractionDigits: MAX_DECIMALS });
}

/**
 * @param {number} amount
 * @param {string|null} [unit] Schlüssel aus UNITS, null ohne Einheit
 */
export function formatQuantity(amount, unit = null) {
    const key = UNITS.includes(unit) ? unit : 'none';
    const count = DECIMAL_UNITS.includes(key) ? Math.round(amount * SCALE) / SCALE : Math.round(amount);

    return t('units.' + key, { count });
}

/** Name der Einheit ohne Zahl („Packung“), leer ohne Einheit. */
export function unitName(unit) {
    return UNITS.includes(unit) ? t('unitNames.' + unit) : '';
}

/** Gleiche Menge und Einheit (auf Tausendstel genau). */
export function sameQuantity(a, b) {
    return Math.round(a.amount * SCALE) === Math.round(b.amount * SCALE) && (a.unit ?? null) === (b.unit ?? null);
}
