/**
 * Ersatzicons für Produkte ohne Foto: SVG-Sprite aus dem Designpaket „Leinen“
 * (24er-Raster, Linie 1,75, runde Enden, Farbe über currentColor).
 *
 * Der Sprite steht einmal unsichtbar im Dokument, ProductIcon.vue zeigt ein
 * Symbol per <use href="#pk-…">. Eingebettet statt als eigene Datei: keine
 * Anfrage, offline sofort da, und currentColor greift sicher.
 * Schritt 8 ergänzt weitere Icons (Lucide und eigene im selben Stil).
 */
import sprite from './ersatzicons.svg?raw';

export const FALLBACK_ICONS = [
    'apfel',
    'banane',
    'brot',
    'milch',
    'kaese',
    'eier',
    'nudeln',
    'waschmittel',
    'toilettenpapier',
    'allgemein',
    'flasche',
    'becher',
    'karton',
    'karotte',
    'gemuese',
    'butter'
];

export const DEFAULT_ICON = 'allgemein';

const SPRITE_ID = 'pk-icon-sprite';

export function installIconSprite() {
    if (document.getElementById(SPRITE_ID)) {
        return;
    }

    const holder = document.createElement('div');
    holder.id = SPRITE_ID;
    holder.hidden = true;
    holder.setAttribute('aria-hidden', 'true');
    holder.innerHTML = sprite;
    document.body.prepend(holder);
}
