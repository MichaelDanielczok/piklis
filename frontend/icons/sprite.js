/**
 * Ersatzicons für Produkte ohne Foto: SVG-Sprite (24er-Raster, Linie 1,75,
 * runde Enden, Farbe über currentColor). Je Icon eine Quelle: Lucide (ISC),
 * Designpaket „Leinen“ oder eigene im selben Stil; Liste in
 * `data/icons.json`, den Sprite erzeugt `npm run icons`.
 *
 * Der Sprite steht einmal unsichtbar im Dokument, ProductIcon.vue zeigt ein
 * Symbol per <use href="#pk-…">. Eingebettet statt als eigene Datei: keine
 * Anfrage, offline sofort da, und currentColor greift sicher.
 */
import sprite from './ersatzicons.svg?raw';
import data from '../../data/icons.json' with { type: 'json' };

/** Alle Icons: `{ key, category, source }` in Reihenfolge der Auswahl */
export const ICON_LIST = Object.freeze(data.icons.map((icon) => Object.freeze({ ...icon })));

export const FALLBACK_ICONS = Object.freeze(ICON_LIST.map(({ key }) => key));

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
