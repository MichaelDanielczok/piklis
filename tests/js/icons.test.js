/**
 * Ersatzicons und Kategorienfarben: Daten, Sprite, Texte und Tokens passen
 * zusammen; die Linie jeder Farbfamilie hat zur Fläche mindestens 3 : 1, hell
 * und dunkel (Obsidian „Piklis Entscheidungen“ › „Kategorienfarben“).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { CATEGORIES, CATEGORY_COLORS } from '@/products/categories.js';
import de from '@/i18n/locales/de.js';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const { icons } = JSON.parse(read('data/icons.json'));
const sprite = read('frontend/icons/ersatzicons.svg');
const tokens = read('frontend/styles/tokens.css');

test('every icon has a unique key, a category, a source, a symbol and a name', () => {
    const keys = icons.map(({ key }) => key);

    assert.equal(new Set(keys).size, keys.length);

    for (const { key, category, source } of icons) {
        assert.ok(CATEGORIES.includes(category), key);
        assert.match(source, /^(lucide:[a-z0-9-]+|design:[a-z0-9-]+|own)$/, key);
        assert.ok(sprite.includes(`<symbol id="pk-${key}" `), key);
        assert.equal(typeof de.icons[key], 'string', key);
    }

    assert.equal((sprite.match(/<symbol /g) ?? []).length, icons.length);
    assert.ok(keys.includes('allgemein'));
});

test('all symbols share the stroke style of the design package', () => {
    for (const symbol of sprite.match(/<symbol [^>]*>/g)) {
        assert.match(symbol, /stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/);
        assert.match(symbol, /fill="none" stroke="currentColor"/);
    }
});

test('the category mapping in tokens.css matches CATEGORY_COLORS', () => {
    const mapping = {};

    for (const [, selectors, family] of tokens.matchAll(/((?:\[data-category="[a-z_]+"\],?\s*)+)\{\s*--category-bg: var\(--cat-([a-z]+)-bg\)/g)) {
        for (const [, category] of selectors.matchAll(/data-category="([a-z_]+)"/g)) {
            mapping[category] = family;
        }
    }

    assert.deepEqual(mapping, { ...CATEGORY_COLORS });
});

function luminance(hex) {
    const [r, g, b] = [1, 3, 5].map((index) => {
        const channel = parseInt(hex.slice(index, index + 2), 16) / 255;

        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

/** Werte eines Blocks: hell (:root) und fest dunkel (:root[data-theme="dark"]) */
function familyColors(block) {
    const colors = {};

    for (const [, family, part, value] of block.matchAll(/--cat-([a-z]+)-(bg|line): (#[0-9A-Fa-f]{6})/g)) {
        colors[family] ??= {};
        colors[family][part] = value;
    }

    return colors;
}

test('line and surface of every colour family differ by at least 3 : 1 in both themes', () => {
    const light = familyColors(tokens.slice(0, tokens.indexOf('@media (prefers-color-scheme: dark)')));
    const darkSystem = familyColors(tokens.slice(tokens.indexOf('@media (prefers-color-scheme: dark)'), tokens.indexOf(':root[data-theme="dark"]')));
    const darkForced = familyColors(tokens.slice(tokens.indexOf(':root[data-theme="dark"]')));
    const families = new Set(Object.values(CATEGORY_COLORS));

    assert.deepEqual(darkSystem, darkForced);

    for (const family of families) {
        for (const [theme, colors] of [['hell', light], ['dunkel', darkForced]]) {
            const { bg, line } = colors[family] ?? {};

            assert.ok(bg && line, `${family} ${theme}`);
            assert.ok(contrast(bg, line) >= 3, `${family} ${theme}: ${contrast(bg, line).toFixed(2)}`);
        }
    }
});
