/**
 * Reihenfolge in „Produkte hinzufügen“ (docs/architecture.md, „Adding
 * products“). Ohne Vue, damit `node --test` es direkt prüft.
 *
 * - Zuerst die Vorschläge vom Server (`GET /products/suggestions`, Gewichtung
 *   in `Products\SuggestionRanking`), soweit das Produkt im Katalog steht und
 *   nicht ausgeblendet ist; danach der restliche Katalog in seiner
 *   Reihenfolge (alphabetisch, wie `useProducts` ihn hält).
 * - Die Reihenfolge wird beim Öffnen einmal festgehalten (`freezeOrder`) und
 *   bleibt stehen, solange die Ansicht offen ist; `currentOrder` setzt sie
 *   später mit dem aktuellen Katalog zusammen: hier angelegte Produkte in
 *   einem eigenen Abschnitt vorn, inzwischen von anderen angelegte hinten,
 *   verschwundene fallen weg.
 * - Gezeigt werden zuerst 48 Kacheln, beim Scrollen je 24 mehr.
 */

export const FIRST_PAGE = 48;
export const NEXT_PAGE = 24;

/**
 * @param {{ id: string, hidden?: boolean }[]} catalog sichtbarer Katalog in Anzeigereihenfolge
 * @param {{ productId: string }[]|null} suggestions beste zuerst; null = nicht geladen
 * @returns {{ suggested: string[], rest: string[] }}
 */
export function freezeOrder(catalog, suggestions) {
    const visible = new Set();

    for (const product of catalog) {
        if (!product.hidden) {
            visible.add(product.id);
        }
    }

    const suggested = [];
    const taken = new Set();

    for (const suggestion of suggestions ?? []) {
        const id = suggestion.productId;

        if (visible.has(id) && !taken.has(id)) {
            suggested.push(id);
            taken.add(id);
        }
    }

    const rest = [];

    for (const product of catalog) {
        if (visible.has(product.id) && !taken.has(product.id)) {
            rest.push(product.id);
            taken.add(product.id);
        }
    }

    return { suggested, rest };
}

/**
 * Festgehaltene Reihenfolge mit dem aktuellen Katalog, in drei Abschnitten:
 * hier angelegt (neueste zuerst) · Vorschläge · übriger Katalog.
 *
 * @param {{ suggested: string[], rest: string[] }} frozen
 * @param {{ id: string, hidden?: boolean }[]} catalog aktueller Katalog in Anzeigereihenfolge
 * @param {string[]} [createdHere] in dieser Ansicht angelegt, neueste zuerst
 * @returns {{ created: string[], suggested: string[], rest: string[] }}
 */
export function currentOrder(frozen, catalog, createdHere = []) {
    const visible = new Set(catalog.filter((product) => !product.hidden).map((product) => product.id));
    const taken = new Set();

    const keep = (ids) => ids.filter((id) => {
        if (!visible.has(id) || taken.has(id)) {
            return false;
        }

        taken.add(id);
        return true;
    });

    const created = keep(createdHere);
    const suggested = keep(frozen.suggested);
    const rest = keep(frozen.rest);

    for (const product of catalog) {
        if (visible.has(product.id) && !taken.has(product.id)) {
            rest.push(product.id);
            taken.add(product.id);
        }
    }

    return { created, suggested, rest };
}

/**
 * Wie viele Kacheln gezeigt werden, nachdem das Ende erreicht wurde: je 24
 * mehr. Nicht auf die aktuelle Anzahl begrenzt, damit später dazukommende
 * Produkte (hier angelegt) nicht das Ende abschneiden.
 */
export function nextLimit(limit) {
    return Math.max(limit, FIRST_PAGE) + NEXT_PAGE;
}

/**
 * Die ersten `limit` Kacheln, der Reihe nach über die Abschnitte verteilt.
 *
 * @template {Record<string, string[]>} O
 * @param {O} order Abschnitte in Anzeigereihenfolge
 * @param {number} limit
 * @returns {O}
 */
export function sliceOrder(order, limit) {
    const result = {};
    let left = limit;

    for (const [section, ids] of Object.entries(order)) {
        result[section] = ids.slice(0, Math.max(0, left));
        left -= result[section].length;
    }

    return result;
}

/** Anzahl aller Kacheln über alle Abschnitte. */
export function countOrder(order) {
    return Object.values(order).reduce((sum, ids) => sum + ids.length, 0);
}
