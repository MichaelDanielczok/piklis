/**
 * Produktsuche und Kategorien; dieselben Fälle prüfen
 * tests/unit/Products/SearchTest.php und CategoriesTest.php.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { exactProduct, normalizeSearchText, rankName, RANK, searchProducts, searchTokens } from '@/utils/productSearch.js';
import { CATEGORIES } from '@/products/categories.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/product-search.json', import.meta.url), 'utf8'));
const categories = JSON.parse(readFileSync(new URL('../fixtures/categories.json', import.meta.url), 'utf8'));

test('normalizeSearchText matches the shared fixture', () => {
    for (const { input, expected } of fixture.normalize) {
        assert.equal(normalizeSearchText(input), expected, input);
    }
});

test('searchProducts matches the shared fixture', () => {
    for (const testCase of fixture.search) {
        const products = testCase.products.map((item, index) => ({
            id: String(index).padStart(12, '0'),
            name: typeof item === 'string' ? item : item.name,
            hidden: typeof item === 'string' ? false : Boolean(item.hidden)
        }));

        const found = searchProducts(testCase.query, products, {
            limit: testCase.limit ?? 20,
            includeHidden: testCase.includeHidden ?? false
        });

        assert.deepEqual(found.map((product) => product.name), testCase.expected, testCase.name);
    }
});

test('tokens and ranks', () => {
    assert.deepEqual(searchTokens('  Gouda, jung! '), ['gouda', 'jung']);
    assert.deepEqual(searchTokens(' - '), []);
    assert.equal(rankName('milch', ['milch']), RANK.EXACT);
    assert.equal(rankName('milchreis', ['milch']), RANK.PREFIX);
    assert.equal(rankName('frische milch', ['milch']), RANK.WORD_PREFIX);
    assert.equal(rankName('hafermilch', ['milch']), RANK.CONTAINS);
    assert.equal(rankName('butter', ['milch']), null);
});

test('categories match the shared fixture', () => {
    assert.deepEqual([...CATEGORIES], categories.categories);
});

test('exactProduct nimmt nur den gleichen Namen, nie einen Teiltreffer', () => {
    const products = [
        { id: 'a', name: 'Vollmilch' },
        { id: 'b', name: 'Milch' },
        { id: 'c', name: 'Salz', hidden: true }
    ];

    assert.equal(exactProduct('Milch', products)?.id, 'b');
    assert.equal(exactProduct('  mILCH ', products)?.id, 'b');
    assert.equal(exactProduct('Mil', products), null);
    assert.equal(exactProduct('Zucker 1kg', products), null);
    assert.equal(exactProduct('Salz', products), null);
    assert.equal(exactProduct('   ', products), null);
});

test('exactProduct zieht unter gleichen Namen die gleiche Schreibweise vor', () => {
    const products = [{ id: 'a', name: 'Apfel' }, { id: 'b', name: 'Äpfel' }];

    assert.equal(exactProduct('Äpfel', products)?.id, 'b');
    assert.equal(exactProduct('apfel', products)?.id, 'a');
    assert.equal(exactProduct('APFEL!', products)?.id, 'a');
});
