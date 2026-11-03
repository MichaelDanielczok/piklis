/**
 * Barcodes; dieselben Fälle prüft tests/unit/FoodFacts/BarcodeTest.php.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseBarcode } from '@/utils/barcode.js';

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
