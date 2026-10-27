/**
 * Mengen: Zahl plus optionale Einheit aus fester Liste. Dezimal nur bei kg und
 * l (Anzeige mit Komma), sonst ganze Zahlen. Ohne Einheit „2 ד.
 * Texte und Mehrzahl in i18n (`units.*`, `unitNames.*`).
 */
import { t } from '@/i18n/index.js';

export const UNITS = ['piece', 'pack', 'bottle', 'cup', 'can', 'jar', 'bag', 'net', 'bunch', 'roll', 'bar', 'g', 'kg', 'ml', 'l'];

export const DECIMAL_UNITS = ['kg', 'l'];

/**
 * @param {number} amount
 * @param {string|null} [unit] Schlüssel aus UNITS, null ohne Einheit
 */
export function formatQuantity(amount, unit = null) {
    const key = UNITS.includes(unit) ? unit : 'none';
    const count = DECIMAL_UNITS.includes(key) ? Math.round(amount * 1000) / 1000 : Math.round(amount);

    return t('units.' + key, { count });
}

/** Name der Einheit ohne Zahl („Packung“), leer ohne Einheit. */
export function unitName(unit) {
    return UNITS.includes(unit) ? t('unitNames.' + unit) : '';
}
