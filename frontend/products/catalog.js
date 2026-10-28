/**
 * Feste Kategorien und Ersatzicons für Auswahl und Anzeige.
 *
 * - `CATEGORIES` (aus `categories.js`): Schlüssel in Reihenfolge der Auswahl.
 * - `ICONS`: jedes Ersatzicon des Sprites mit seiner Kategorie für die nach
 *   Kategorien gegliederte Icon-Auswahl (`data/icons.json`); Namen:
 *   `t('icons.<key>')`. Kategorie und Icon eines Produkts sind trotzdem
 *   unabhängig: Die Kategorie des Icons ist nur sein Platz in der Auswahl.
 */
import { DEFAULT_ICON, ICON_LIST } from '@/icons/sprite.js';
import { CATEGORIES, CATEGORY_COLORS, colorOf } from './categories.js';

export { CATEGORIES, CATEGORY_COLORS, colorOf, DEFAULT_ICON };

export const ICONS = Object.freeze(ICON_LIST.map(({ key, category }) => ({ key, category })));

/** Icon eines Produkts: null heißt allgemein. */
export function iconOf(product) {
    return product?.icon || DEFAULT_ICON;
}

/** Kategorie eines Produkts für die Farbe (data-category), null = neutral. */
export function categoryOf(product) {
    return product?.category || null;
}
