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

/**
 * Farbfamilie je Kategorie (Tokens `--cat-<familie>-bg|line` in
 * `styles/tokens.css`, dort per `data-category` zugeordnet; der Test prüft,
 * dass beide Listen gleich sind). Fehlende Kategorien: neutral.
 */
export const CATEGORY_COLORS = Object.freeze({
    fruit: 'green',
    vegetables: 'orange',
    bread: 'sand',
    dairy: 'yellow',
    cheese: 'yellow',
    meat: 'rose',
    fish: 'blue',
    grains: 'sand',
    canned: 'orange',
    frozen: 'ice',
    sweets: 'pink',
    drinks: 'teal',
    coffee_tea: 'teal',
    baking: 'sand',
    drugstore: 'lilac',
    household: 'slate'
});

/** @param {string|null|undefined} category */
export function colorOf(category) {
    return CATEGORY_COLORS[category] ?? 'neutral';
}
