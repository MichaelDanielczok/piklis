/**
 * Feste Kategorien und Ersatzicons für Auswahl und Anzeige.
 *
 * - `CATEGORIES` (aus `categories.js`): Schlüssel in Reihenfolge der Auswahl.
 * - `ICONS`: jedes Ersatzicon des Sprites mit seiner Kategorie für die nach
 *   Kategorien gegliederte Icon-Auswahl; Namen: `t('icons.<key>')`.
 *   Kategorie und Icon eines Produkts sind trotzdem unabhängig.
 *   Vorerst die 16 Icons des Designpakets, Schritt 8 ergänzt weitere.
 */
import { DEFAULT_ICON, FALLBACK_ICONS } from '@/icons/sprite.js';
import { CATEGORIES } from './categories.js';

const ICON_CATEGORY = {
    apfel: 'fruit',
    banane: 'fruit',
    karotte: 'vegetables',
    gemuese: 'vegetables',
    brot: 'bread',
    milch: 'dairy',
    butter: 'dairy',
    becher: 'dairy',
    eier: 'dairy',
    kaese: 'cheese',
    nudeln: 'grains',
    karton: 'canned',
    flasche: 'drinks',
    toilettenpapier: 'drugstore',
    waschmittel: 'household',
    allgemein: 'other'
};

export { CATEGORIES, DEFAULT_ICON };

export const ICONS = Object.freeze(FALLBACK_ICONS.map((key) => ({ key, category: ICON_CATEGORY[key] ?? 'other' })));

/** Icon eines Produkts: null heißt allgemein. */
export function iconOf(product) {
    return product?.icon || DEFAULT_ICON;
}
