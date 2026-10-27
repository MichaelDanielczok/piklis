/**
 * Sortierschlüssel; dieselben Fälle prüft tests/unit/Lists/SortKeyTest.php.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { keyBetween, keysBetween, isValidSortKey, compareBySortKey, keyForIndex } from '@/utils/sortKey.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/sort-keys.json', import.meta.url), 'utf8'));

test('keyBetween matches the shared fixture', () => {
    for (const { before, after, key } of fixture.between) {
        const result = keyBetween(before, after);

        assert.equal(result, key, `${before} | ${after}`);
        assert.ok(isValidSortKey(result));

        if (before !== null) {
            assert.ok(before < result);
        }

        if (after !== null) {
            assert.ok(result < after);
        }
    }
});

test('keysBetween matches the shared fixture', () => {
    for (const { before, after, count, keys } of fixture.manyBetween) {
        assert.deepEqual(keysBetween(before, after, count), keys, `${before} | ${after} × ${count}`);
    }
});

test('keyBetween rejects invalid neighbours', () => {
    for (const { before, after, reason } of fixture.invalidBetween) {
        assert.throws(() => keyBetween(before, after), RangeError, reason);
    }
});

test('validation matches the shared fixture', () => {
    for (const key of fixture.valid) {
        assert.ok(isValidSortKey(key), key);
    }

    for (const key of fixture.invalid) {
        assert.ok(!isValidSortKey(key), key);
    }
});

test('appending grows slowly', () => {
    let key = null;

    for (let i = 0; i < 500; i++) {
        const next = keyBetween(key, null);

        if (key !== null) {
            assert.ok(key < next);
        }

        key = next;
    }

    assert.ok(key.length <= 30);
});

test('random inserts keep the order', () => {
    // Einfacher deterministischer Zufall (LCG), Ergebnis wie in PHP egal
    let seed = 42;
    const random = (max) => {
        seed = (seed * 1103515245 + 12345) % 2147483648;
        return seed % (max + 1);
    };

    const keys = [keyBetween(null, null)];

    for (let i = 0; i < 400; i++) {
        const index = random(keys.length);
        const key = keyBetween(index > 0 ? keys[index - 1] : null, index < keys.length ? keys[index] : null);

        assert.ok(isValidSortKey(key));
        keys.splice(index, 0, key);
    }

    assert.deepEqual([...keys].sort(), keys);
    assert.equal(new Set(keys).size, keys.length);
});

test('equal keys are ordered by id', () => {
    const entries = [
        { id: 'b', sortKey: 'i' },
        { id: 'c', sortKey: 'h' },
        { id: 'a', sortKey: 'i' }
    ];

    assert.deepEqual(entries.sort(compareBySortKey).map((entry) => entry.id), ['c', 'a', 'b']);
});

test('keyForIndex finds a key for a drop position', () => {
    const sorted = [{ sortKey: 'i' }, { sortKey: 'j' }, { sortKey: 'k' }];

    assert.equal(keyForIndex(sorted, 0), '9');
    assert.equal(keyForIndex(sorted, 1), 'ii');
    assert.equal(keyForIndex(sorted, 3), 'l');
    assert.equal(keyForIndex([], 0), 'i');
    assert.equal(keyForIndex([{ sortKey: 'i' }, { sortKey: 'i' }], 1), null);
});
