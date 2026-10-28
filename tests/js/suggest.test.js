/**
 * Vorschlag von Kategorie und Ersatzicon aus dem Namen; dieselben Fälle prüft
 * tests/unit/Products/ProductSuggestionTest.php.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isDeepStrictEqual } from 'node:util';
import { suggestForName } from '@/products/suggest.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/product-suggestions.json', import.meta.url), 'utf8'));

test('suggestForName matches the shared fixture', () => {
    const failures = fixture.cases
        .filter(({ name, expected }) => !isDeepStrictEqual(suggestForName(name), expected))
        .map(({ name, expected }) => `${name}: ${JSON.stringify(suggestForName(name))} statt ${JSON.stringify(expected)}`);

    assert.deepEqual(failures, []);
});

test('the fixture has at least 100 names', () => {
    assert.ok(fixture.cases.length >= 100);
});
