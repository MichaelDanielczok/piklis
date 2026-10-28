/**
 * Erzeugt den Sprite der Ersatzicons `frontend/icons/ersatzicons.svg` aus
 * `data/icons.json` (`npm run icons`, danach `npm run build`).
 *
 * Je Icon genau eine Quelle:
 * - `lucide:<name>`: Lucide aus `@lucide/vue` (ISC, siehe `LICENSE-lucide.txt`)
 * - `design:<id>`: Icons des Designpakets „Leinen“ (`build/icons/design.svg`,
 *   Kopie von `design/piklis-designpaket/assets/ersatzicons/`)
 * - `own`: eigene Icons im selben Stil (`build/icons/own.svg`)
 *
 * Alle Symbole bekommen dieselben Strich-Attribute (Linie 1,75, runde Enden
 * und Ecken, currentColor); die Quellen tragen nur Formen.
 *
 * Lucide füllt das Raster fast ganz (2–22), das Designpaket und die eigenen
 * Icons nutzen etwa 3,5–20,5. Damit die Mischung nicht auffällt, werden die
 * Lucide-Formen um die Mitte auf 90 % verkleinert; die Linie wird dabei
 * ausgeglichen (1,75 / 0,9) und bleibt sichtbar 1,75.
 */
import { readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const { icons } = JSON.parse(read('data/icons.json'));

const LUCIDE_SCALE = 0.9;
const round = (value) => Math.round(value * 1000) / 1000;
const LUCIDE_OFFSET = round(12 * (1 - LUCIDE_SCALE));
const LUCIDE_GROUP = `<g transform="matrix(${LUCIDE_SCALE} 0 0 ${LUCIDE_SCALE} ${LUCIDE_OFFSET} ${LUCIDE_OFFSET})" `
    + `stroke-width="${round(1.75 / LUCIDE_SCALE)}">`;

const ATTRIBUTES = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
    + 'stroke-linecap="round" stroke-linejoin="round"';

/** Symbole einer Quelldatei: id → Inhalt */
function symbolsOf(file) {
    const symbols = new Map();

    for (const match of read(file).matchAll(/<symbol id="([^"]+)"[^>]*>([\s\S]*?)<\/symbol>/g)) {
        symbols.set(match[1].replace(/^pk-/, ''), match[2].trim());
    }

    return symbols;
}

function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

async function lucide(name) {
    const module = await import(`@lucide/vue/dist/esm/icons/${name}.mjs`);

    return module.__iconData.node
        .map(([tag, attributes]) => {
            const list = Object.entries(attributes)
                .filter(([key]) => key !== 'key')
                .map(([key, value]) => `${key}="${escapeAttribute(value)}"`)
                .join(' ');

            return `<${tag} ${list}/>`;
        })
        .join('');
}

const sources = {
    design: symbolsOf('build/icons/design.svg'),
    own: symbolsOf('build/icons/own.svg')
};

const lucideVersion = JSON.parse(read('node_modules/@lucide/vue/package.json')).version;
const lines = [];

for (const { key, source } of icons) {
    const [kind, name = key] = source.split(':');
    let content;

    if (kind === 'lucide') {
        content = LUCIDE_GROUP + await lucide(name) + '</g>';
    } else if (sources[kind]?.has(name)) {
        content = sources[kind].get(name);
    } else {
        throw new Error(`Icon ${key}: Quelle ${source} nicht gefunden`);
    }

    lines.push(`  <symbol id="pk-${key}" ${ATTRIBUTES}>${content}</symbol>`);
}

const header = [
    '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">',
    '  <!-- Piklis-Ersatzicons · erzeugt von build/make-sprite.mjs aus data/icons.json, nicht von Hand ändern.',
    '       24er-Raster · Linie 1,75 · runde Enden · Farbe über currentColor.',
    `       Enthält Icons von Lucide ${lucideVersion} (https://lucide.dev), ISC-Lizenz,`,
    '       Copyright (c) Lucide Icons and Contributors; siehe LICENSE-lucide.txt im Paket. -->'
];

writeFileSync(new URL('frontend/icons/ersatzicons.svg', root), [...header, ...lines, '</svg>', ''].join('\n'));

const count = (kind) => icons.filter(({ source }) => source.split(':')[0] === kind).length;

console.log(`${icons.length} Icons: Lucide ${count('lucide')}, Designpaket ${count('design')}, eigene ${count('own')}`);
