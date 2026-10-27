/**
 * Feste Kategorien des Katalogs in Reihenfolge der Auswahl, gleich wie
 * `Products\Categories` (PHP); beide Tests prüfen sie gegen
 * `tests/fixtures/categories.json`. Namen: `t('categories.<key>')`.
 * Ohne Abhängigkeiten, damit `node --test` es laden kann.
 */
export const CATEGORIES = Object.freeze([
    'fruit',
    'vegetables',
    'bread',
    'dairy',
    'cheese',
    'meat',
    'fish',
    'grains',
    'canned',
    'frozen',
    'sweets',
    'drinks',
    'coffee_tea',
    'baking',
    'drugstore',
    'household',
    'baby',
    'pets',
    'other'
]);
