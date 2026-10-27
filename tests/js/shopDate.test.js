/**
 * Einkaufsdatum relativ zu heute.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatShopDate, parseShopDate, shopDateFromToday, toShopDate } from '@/utils/shopDate.js';

// Dienstag, 27. Oktober 2026, mittags
const NOW = new Date(2026, 9, 27, 12, 0);

test('heute, morgen, gestern', () => {
    assert.equal(formatShopDate('2026-10-27', NOW), 'heute');
    assert.equal(formatShopDate('2026-10-28', NOW), 'morgen');
    assert.equal(formatShopDate('2026-10-26', NOW), 'gestern');
});

test('die nächsten Tage als Wochentag ohne Punkt', () => {
    assert.equal(formatShopDate('2026-10-31', NOW), 'Sa');
    assert.equal(formatShopDate('2026-11-02', NOW), 'Mo');
});

test('weiter weg mit Tag und Monat, anderes Jahr mit Jahr', () => {
    assert.match(formatShopDate('2026-11-14', NOW), /14\. Nov/);
    assert.match(formatShopDate('2027-01-08', NOW), /2027/);
    assert.match(formatShopDate('2026-10-20', NOW), /20\. Okt/);
});

test('ohne oder mit ungültigem Datum leer', () => {
    assert.equal(formatShopDate(null, NOW), '');
    assert.equal(formatShopDate('2026-02-30', NOW), '');
    assert.equal(parseShopDate('31.10.2026'), null);
});

test('Umwandlung hin und zurück', () => {
    assert.equal(toShopDate(new Date(2026, 0, 5)), '2026-01-05');
    assert.equal(shopDateFromToday(0, NOW), '2026-10-27');
    assert.equal(shopDateFromToday(5, NOW), '2026-11-01');
});
