/**
 * Barcodes; dieselben Fälle prüft tests/unit/FoodFacts/BarcodeTest.php.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseBarcode, productWithBarcode } from '@/utils/barcode.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/barcodes.json', import.meta.url), 'utf8'));

test('valid barcodes match the shared fixture', () => {
    for (const { input, barcode } of fixture.valid) {
        assert.deepEqual(parseBarcode(input), { barcode, error: null }, `"${input}"`);
    }
});

test('invalid barcodes match the shared fixture', () => {
    for (const { input, reason } of fixture.invalid) {
        assert.deepEqual(parseBarcode(input), { barcode: null, error: reason }, `"${input}"`);
    }
});

test('finds the product with a barcode', () => {
    const milk = { id: 'a', barcode: '4000417025005' };
    const products = [{ id: 'b', barcode: null }, milk];

    assert.equal(productWithBarcode(products, '4000417025005'), milk);
    assert.equal(productWithBarcode(products, '4000417025012'), null);
    assert.equal(productWithBarcode([], '4000417025005'), null);
});

test('leading zeros do not make a different product', () => {
    const upc = { id: 'a', barcode: '036000291452' };

    assert.equal(productWithBarcode([upc], '0036000291452'), upc);
});

test('the first match wins, so the catalog goes before list items', () => {
    const catalogProduct = { id: 'a', barcode: '4000417025005' };
    const listItem = { id: 'b', barcode: '4000417025005', listItem: true };

    assert.equal(productWithBarcode([catalogProduct, listItem], '4000417025005'), catalogProduct);
});
