/**
 * Mengen und Einheiten; dieselben Fälle prüft tests/unit/Lists/QuantityTest.php.
 * `label` gibt es nur im Frontend (Texte aus i18n).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseQuantity, validateQuantity, formatAmount, formatQuantity, parseAmount, sameQuantity } from '@/utils/quantity.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/quantities.json', import.meta.url), 'utf8'));

test('parse matches the shared fixture', () => {
    for (const { input, unit, amount = null, error = null } of fixture.parse) {
        const result = parseQuantity(input, unit);

        assert.equal(result.error, error, `"${input}" ${unit}`);
        assert.equal(result.amount, amount, `"${input}" ${unit}`);
    }
});

test('validate matches the shared fixture', () => {
    for (const { amount, unit, error } of fixture.validate) {
        assert.equal(validateQuantity(amount, unit), error, `${amount} ${unit}`);
    }
});

test('formatAmount matches the shared fixture', () => {
    for (const { amount, text } of fixture.formatAmount) {
        assert.equal(formatAmount(amount), text);
    }
});

test('formatted amounts parse back', () => {
    for (const amount of [1, 1.5, 0.125, 1000, 1234.5, 9999]) {
        assert.equal(parseAmount(formatAmount(amount)).amount, amount);
    }
});

test('labels with unit and plural', () => {
    for (const { amount, unit, text } of fixture.label) {
        assert.equal(formatQuantity(amount, unit), text);
    }
});

test('sameQuantity compares amount and unit', () => {
    assert.ok(sameQuantity({ amount: 2, unit: null }, { amount: 2 }));
    assert.ok(!sameQuantity({ amount: 2, unit: null }, { amount: 2, unit: 'pack' }));
    assert.ok(sameQuantity({ amount: 0.1 + 0.2, unit: 'kg' }, { amount: 0.3, unit: 'kg' }));
});
