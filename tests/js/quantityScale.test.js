/**
 * Skala der Halten-Geste: Stufen, Obergrenze und Interpolation.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MAX_AMOUNT, quantityParts } from '@/utils/quantity.js';
import { amountAt, easeOutCubic, lerpKnots, rubberBand, scaleIndex, scaleMaxIndex, slotIndex } from '@/utils/quantityScale.js';

test('the scale has at least 20 steps and never cuts off a larger amount', () => {
    assert.equal(scaleMaxIndex(2, 'pack'), 20);
    assert.equal(scaleMaxIndex(25, null), 50);
    assert.equal(amountAt(scaleMaxIndex(1500, 'g'), 'g'), 3000);
    assert.equal(amountAt(scaleMaxIndex(1.5, 'kg'), 'kg'), 10);
    assert.equal(scaleIndex(1500, 'g'), 30);
});

test('the scale stays below the highest amount', () => {
    assert.equal(amountAt(scaleMaxIndex(9000, 'g'), 'g'), 9950);
    assert.equal(scaleMaxIndex(9999, null), MAX_AMOUNT);
    assert.equal(scaleIndex(9999, 'g'), scaleMaxIndex(9999, 'g'));
});

test('a free amount shows the next step', () => {
    assert.equal(scaleIndex(120, 'g'), 2);
    assert.equal(scaleIndex(1.25, 'kg'), 3);
    assert.equal(amountAt(3, 'kg'), 1.5);
    assert.equal(amountAt(0, 'g'), 0);
});

test('beyond the ends the scale brakes and stops', () => {
    assert.equal(rubberBand(3, 10), 3);
    assert.equal(rubberBand(-1, 10), -0.35);
    assert.equal(rubberBand(-10, 10), -0.45);
    assert.equal(rubberBand(11, 10), 10.35);
    assert.equal(rubberBand(30, 10), 10.45);
});

test('values between the knots are interpolated on both sides', () => {
    const knots = [1, 0.56, 0.5, 0.48];

    assert.equal(lerpKnots(knots, 0), 1);
    assert.equal(lerpKnots(knots, 1), 0.56);
    assert.equal(lerpKnots(knots, -0.5), 0.78);
    assert.equal(lerpKnots(knots, 9), 0.48);
});

test('snapping eases out', () => {
    assert.equal(easeOutCubic(0), 0);
    assert.equal(easeOutCubic(1), 1);
    assert.equal(easeOutCubic(0.5), 0.875);
});

test('every step in view has exactly one slot', () => {
    const shown = [];

    for (let slot = 0; slot < 6; slot++) {
        shown.push(slotIndex(slot, 6, 7, 10));
    }

    assert.deepEqual(shown.filter((index) => index !== null).sort((a, b) => a - b), [7, 8, 9, 10]);
    assert.equal(shown.filter((index) => index === null).length, 2);
});

test('number and unit stay apart for the drum', () => {
    assert.deepEqual(quantityParts(1500, 'g'), { number: '1,5', unit: 'kg' });
    assert.deepEqual(quantityParts(500, 'ml'), { number: '500', unit: 'ml' });
    assert.deepEqual(quantityParts(3, 'jar'), { number: '3', unit: 'Gläser' });
    assert.deepEqual(quantityParts(2, null), { number: '2', unit: '×' });
    assert.deepEqual(quantityParts(0, 'g'), { number: '0', unit: '' });
});
