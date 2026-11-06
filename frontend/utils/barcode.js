/**
 * Barcodes (GTIN) für Open Food Facts, dieselbe Regel wie
 * `FoodFacts\Barcode` im Server (gemeinsame Fälle in
 * tests/fixtures/barcodes.json): 8, 12, 13 oder 14 Ziffern, Leerzeichen und
 * Bindestriche werden entfernt, die Prüfziffer muss stimmen.
 */

const LENGTHS = [8, 12, 13, 14];

/**
 * @param {string} input
 * @returns {{ barcode: string|null, error: null|'required'|'invalid'|'checksum' }}
 */
export function parseBarcode(input) {
    const barcode = String(input ?? '').replace(/[\s-]+/g, '');

    if (barcode === '') {
        return { barcode: null, error: 'required' };
    }

    if (!/^\d+$/.test(barcode) || !LENGTHS.includes(barcode.length)) {
        return { barcode: null, error: 'invalid' };
    }

    if (!hasValidCheckDigit(barcode)) {
        return { barcode: null, error: 'checksum' };
    }

    return { barcode, error: null };
}

/** Führende Nullen zählen nicht: UPC-A liest mancher Scanner als EAN-13 mit einer Null davor. */
function gtin(barcode) {
    return barcode.replace(/^0+/, '');
}

/**
 * Das erste Produkt mit diesem Barcode.
 *
 * @param {object[]} products
 * @param {string} barcode
 * @returns {object|null}
 */
export function productWithBarcode(products, barcode) {
    const wanted = gtin(barcode);

    return products.find((product) => product.barcode && gtin(product.barcode) === wanted) ?? null;
}

/**
 * GTIN-Prüfziffer: von rechts ohne die Prüfziffer abwechselnd mal 3 und
 * mal 1 gewichtet, auf den nächsten Zehner ergänzt.
 *
 * @param {string} barcode nur Ziffern
 */
export function hasValidCheckDigit(barcode) {
    let sum = 0;

    for (let index = barcode.length - 2, position = 0; index >= 0; index--, position++) {
        const digit = Number(barcode[index]);
        sum += position % 2 === 0 ? digit * 3 : digit;
    }

    return (10 - (sum % 10)) % 10 === Number(barcode[barcode.length - 1]);
}
