/**
 * Listenartikel (Produkte mit `listItem: true`) im Speicher der App, ohne Vue
 * (`node --test`). Sie stehen nicht im Katalog, sondern kommen mit Listen und
 * Vorlagen (docs/architecture.md, „List items“).
 */
import { normalizeSearchText } from './productSearch.js';

/**
 * Bekannte Artikel mit den gesendeten zusammenführen. Unveränderte Artikel
 * ändern nichts: Dann kommt dieselbe Map zurück und Vue rendert nicht neu
 * (die offene Liste fragt alle 5 s ab).
 *
 * @param {Map<string, object>} known
 * @param {object[]} items
 * @returns {Map<string, object>}
 */
export function mergeListItems(known, items) {
    let next = known;

    for (const item of items) {
        if (JSON.stringify(known.get(item.id)) === JSON.stringify(item)) {
            continue;
        }

        if (next === known) {
            next = new Map(known);
        }

        next.set(item.id, item);
    }

    return next;
}

/**
 * Artikel, die auf der Liste stehen, aber nicht mehr als Listenartikel kommen:
 * jemand hat sie in den Katalog aufgenommen. Noch nicht bestätigte zählen
 * nicht, der Server kennt sie vielleicht noch gar nicht.
 *
 * @param {Map<string, object>} known
 * @param {object[]} items Antwort des Servers für diese Liste
 * @param {string[]} usedIds Produkte der sichtbaren Einträge
 * @returns {string[]}
 */
export function goneListItems(known, items, usedIds) {
    const sent = new Set(items.map((item) => item.id));

    return [...new Set(usedIds)].filter((id) => known.has(id) && !known.get(id).local && !sent.has(id));
}

/**
 * Bestätigte Listenartikel dieser Einträge, zum Speichern mit der Liste.
 *
 * @param {Iterable<{ productId: string }>} entries
 * @param {(productId: string) => object|null} findProduct
 * @returns {object[]}
 */
export function listItemsOf(entries, findProduct) {
    const items = new Map();

    for (const entry of entries) {
        const product = findProduct(entry.productId);

        if (product?.listItem && !product.local) {
            items.set(product.id, product);
        }
    }

    return [...items.values()];
}

/**
 * Listenartikel gleichen Namens unter diesen Einträgen, auch abgehakte: Noch
 * einmal eingetippt ist es derselbe Artikel, keine Dublette.
 *
 * @param {Iterable<{ productId: string }>} entries sichtbare Einträge
 * @param {string} name
 * @param {(productId: string) => object|null} findProduct
 * @returns {object|null}
 */
export function listItemNamed(entries, name, findProduct) {
    const wanted = normalizeSearchText(name);

    for (const entry of entries) {
        const product = findProduct(entry.productId);

        if (product?.listItem && normalizeSearchText(product.name) === wanted) {
            return product;
        }
    }

    return null;
}
