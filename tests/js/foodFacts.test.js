/**
 * Vorauswahl und Anzeige der Angaben aus Open Food Facts.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    defaultSelection,
    fieldEffect,
    hasServingColumn,
    missingFields,
    nutrientFormat,
    nutritionSummary
} from '@/utils/foodFacts.js';

const milk = {
    barcode: '4311501489840',
    name: 'H-Vollmilch 3,5 % Fett',
    brand: 'Edeka, GUT&GÜNSTIG',
    packageSize: '1 l',
    ingredients: 'Haltbare vollmilch',
    nutrition: {
        per: 'ml',
        servingSize: null,
        rows: [
            { key: 'energy-kj', unit: 'kJ', per100: 267, perServing: null },
            { key: 'energy-kcal', unit: 'kcal', per100: 64, perServing: null }
        ]
    },
    hasImage: true,
    fields: ['name', 'brand', 'packageSize', 'ingredients', 'nutrition', 'image']
};

const sparse = {
    barcode: '4316268526463',
    name: 'Milch frisch',
    brand: null,
    packageSize: null,
    ingredients: null,
    nutrition: null,
    hasImage: true,
    fields: ['name', 'image']
};

test('own name, own photo and a different size are never preselected', () => {
    const product = { name: 'Milch', brand: null, packageSize: '1,5 l', image: { medium: 'x' }, foodFacts: null };

    assert.deepEqual(defaultSelection(product, milk), ['brand', 'ingredients', 'nutrition']);
    assert.equal(fieldEffect(product, milk, 'name'), 'replace');
    assert.equal(fieldEffect(product, milk, 'packageSize'), 'replace');
    assert.equal(fieldEffect(product, milk, 'image'), 'replace');
    assert.equal(fieldEffect(product, milk, 'brand'), 'new');
});

test('empty fields and equal values are preselected', () => {
    const product = { name: 'Milch', brand: 'edeka,  gut&günstig', packageSize: null, image: null, foodFacts: null };

    assert.deepEqual(defaultSelection(product, milk), ['brand', 'packageSize', 'image', 'ingredients', 'nutrition']);
    assert.equal(fieldEffect(product, milk, 'brand'), 'same');
});

test('missing data is neither offered nor preselected', () => {
    const product = { name: 'Milch', brand: null, packageSize: null, image: null, foodFacts: null };

    assert.deepEqual(defaultSelection(product, sparse), ['image']);
    assert.deepEqual(missingFields(sparse), ['brand', 'packageSize', 'ingredients', 'nutrition']);
    assert.deepEqual(missingFields(milk), []);
});

test('earlier ingredients count as replaced unless equal', () => {
    const product = { name: 'Milch', foodFacts: { ingredients: 'Milch', nutrition: null } };

    assert.equal(fieldEffect(product, milk, 'ingredients'), 'replace');
    assert.equal(fieldEffect(product, milk, 'nutrition'), 'new');

    const fetched = { name: 'Milch', foodFacts: { ingredients: milk.ingredients, nutrition: milk.nutrition } };

    assert.equal(fieldEffect(fetched, milk, 'ingredients'), 'same');
    assert.equal(fieldEffect(fetched, milk, 'nutrition'), 'same');
});

test('nutrient formats', () => {
    assert.deepEqual(nutrientFormat(267, 'kJ'), { maximumFractionDigits: 0 });
    assert.deepEqual(nutrientFormat(0.13, 'g'), { maximumFractionDigits: 2 });
    assert.deepEqual(nutrientFormat(3.5, 'g'), { maximumFractionDigits: 1 });
});

test('serving column and summary', () => {
    assert.equal(hasServingColumn(milk.nutrition), false);
    assert.equal(hasServingColumn({ rows: [{ per100: 1, perServing: 2 }] }), true);
    assert.equal(hasServingColumn(null), false);
    assert.deepEqual(nutritionSummary(milk.nutrition), { key: 'energy-kcal', value: 64, unit: 'kcal' });
    assert.equal(nutritionSummary(null), null);
});
