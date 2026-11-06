/**
 * Produktkatalog: einmal ganz geladen (`GET /products`, mit ETag fast
 * kostenlos erneut), gesucht wird lokal (`utils/productSearch.js`), damit
 * Vorschläge beim Tippen sofort kommen. Anlegen, Bearbeiten, Ausblenden und
 * das Produktbild gehen direkt an die REST-API; die Antwort ersetzt das
 * Produkt im Zustand.
 *
 * Bilder: `product.image` = { small (128 px), medium (640), large (1600),
 * original } als Adressen im Media-Cache (WebP), `product.imageUrl` = small,
 * `product.imageSource` = 'openfoodfacts' für ein von dort übernommenes Bild.
 *
 * Open Food Facts: `product.barcode`, `product.brand` und `product.foodFacts`
 * = { fetchedAt, language, ingredients, nutrition, url } oder null. Sie
 * liegen mit dem Katalog im lokalen Speicher, das Detail zeigt sie also auch
 * offline; nachschlagen und übernehmen geht nur online.
 *
 * Offline: Katalog und Vorschläge liegen nach jedem Laden im lokalen
 * Speicher (`localStore`, Schlüssel `products` und `suggestions`); beim Start
 * (auch ohne Netz) kommen sie von dort, das Netz aktualisiert danach. Suchen
 * und Hinzufügen gehen so auch offline.
 *
 * Rechte wie im Server (`Products\ProductPermissions`): bearbeiten darf, wer
 * ein Produkt angelegt hat (solange es nicht ausgeblendet ist), und jeder
 * Produktpfleger; ausblenden nur Produktpfleger. Die Oberfläche bietet nur
 * an, was erlaubt ist; entscheidend bleibt der Server (403).
 *
 * Listenartikel (`product.listItem`) gehören nicht zum Katalog: `products`,
 * `catalog` und `search` kennen sie nicht. Sie kommen mit Listen und Vorlagen
 * (`rememberListItems`), liegen getrennt in `listItems` und sind offline Teil
 * der Liste, die sie nutzt (`useLists`). `findProduct` findet beide. Wer einen
 * Listenartikel sieht, darf ihn bearbeiten.
 */
import { computed, ref, shallowRef, watch } from 'vue';
import { api, logError } from '@/api/index.js';
import { localStore } from '@/api/localStore.js';
import { locale } from '@/i18n/index.js';
import { squareImage } from '@/utils/imageEncode.js';
import { goneListItems, mergeListItems } from '@/utils/listItems.js';
import { searchProducts } from '@/utils/productSearch.js';
import { onLogout, onSessionStart, useSession } from './useSession.js';

const products = ref([]);
const status = ref('idle'); // idle · loading · ready · error

/** Listenartikel aus geladenen Listen und Vorlagen: id → Produkt. */
const listItems = shallowRef(new Map());

/** Vorschläge für „Produkte hinzufügen“ (`GET /products/suggestions`), beste zuerst. */
const suggestions = ref(null);

let pendingLoad = null;
let pendingSuggestions = null;

/** Über die Warteschlange angelegt, vom Server noch nicht bestätigt: id → Produkt. */
const localProducts = new Map();

const { user, canManageProducts } = useSession();

let hydratedFor = null;

onLogout(() => {
    products.value = [];
    listItems.value = new Map();
    status.value = 'idle';
    suggestions.value = null;
    pendingLoad = null;
    pendingSuggestions = null;
    localProducts.clear();
    hydratedFor = null;
});

/** Lokalen Katalog laden, bevor die erste Ansicht erscheint (auch offline). */
onSessionStart(async (current) => {
    if (hydratedFor === current.id) {
        return;
    }

    hydratedFor = current.id;

    const [stored, storedSuggestions] = await Promise.all([
        localStore.data.get('products').catch(() => null),
        localStore.data.get('suggestions').catch(() => null)
    ]);

    if (Array.isArray(stored) && status.value !== 'ready') {
        products.value = sortByName(withLocalProducts(stored));
        status.value = 'ready';
    }

    if (Array.isArray(storedSuggestions) && suggestions.value === null) {
        suggestions.value = storedSuggestions;
    }

    // Katalog und Vorschläge für offline, auch wenn die App nicht mit „Produkte“ startet
    loadProducts().catch(logError);
    loadSuggestions();
});

// jeder bestätigte Stand (Laden, Anlegen, Bearbeiten, Bild) kurz danach lokal
let productsTimer = null;

watch(products, () => {
    if (status.value !== 'ready' || productsTimer !== null) {
        return;
    }

    productsTimer = setTimeout(() => {
        productsTimer = null;

        if (status.value === 'ready') {
            remember('products', products.value.filter((product) => !product.local));
        }
    }, 500);
});

function remember(key, value) {
    localStore.data.set(key, value).catch((error) => console.warn('Nicht lokal gespeichert: ' + key, error));
}

function sortByName(list) {
    const collator = new Intl.Collator(locale.value, { sensitivity: 'base', numeric: true });

    return [...list].sort((a, b) => collator.compare(a.name, b.name) || (a.id < b.id ? -1 : 1));
}

/** Geladener Katalog und die noch nicht bestätigten Katalogprodukte. */
function withLocalProducts(loaded) {
    const ids = new Set(loaded.map((product) => product.id));
    const local = [...localProducts.values()].filter((product) => !product.listItem && !ids.has(product.id));

    return [...loaded, ...local];
}

function setListItems(next) {
    if (next !== listItems.value) {
        listItems.value = next;
    }
}

function dropListItems(ids) {
    const next = new Map(listItems.value);

    for (const id of ids) {
        next.delete(id);
    }

    listItems.value = next;
}

/** Produkt ablegen, wo es hingehört; aufgenommen wechselt es in den Katalog. */
function upsert(product) {
    if (product.listItem) {
        setListItems(mergeListItems(listItems.value, [product]));

        return product;
    }

    if (listItems.value.has(product.id)) {
        dropListItems([product.id]);
    }

    const others = products.value.filter((item) => item.id !== product.id);
    products.value = sortByName([...others, product]);

    return product;
}

/**
 * Listenartikel aus der Antwort zu einer Liste oder Vorlage übernehmen.
 * `usedIds` (Produkte der sichtbaren Einträge): Kommt ein bekannter Artikel
 * von dort nicht mehr, hat ihn jemand in den Katalog aufgenommen; dann den
 * Katalog neu laden.
 *
 * @param {object[]} items
 * @param {string[]} [usedIds]
 */
export function rememberListItems(items, usedIds = []) {
    const gone = goneListItems(listItems.value, items, usedIds);

    setListItems(mergeListItems(listItems.value, items));

    if (gone.length > 0) {
        loadProducts().then(() => dropListItems(gone)).catch(logError);
    }
}

/**
 * Lädt den Katalog. Mehrere Aufrufe teilen eine Anfrage; ein neuer Aufruf
 * nach dem Laden fragt erneut (304, wenn sich nichts geändert hat).
 */
export function loadProducts() {
    if (!pendingLoad) {
        if (status.value !== 'ready') {
            status.value = 'loading';
        }

        pendingLoad = api.getProducts()
            .then((data) => {
                products.value = sortByName(withLocalProducts(data.products ?? []));
                status.value = 'ready';
            })
            .catch((error) => {
                if (status.value !== 'ready') {
                    status.value = 'error';
                }

                throw error;
            })
            .finally(() => {
                pendingLoad = null;
            });
    }

    return pendingLoad;
}

/**
 * Vorschläge laden: `[{ productId, score, uses, lastUsedAt }]`, beste zuerst.
 * Scheitert das (offline), bleibt der letzte Stand; ohne ihn `[]`, die
 * Auswahl zeigt dann den Katalog alphabetisch.
 *
 * @returns {Promise<object[]>}
 */
export function loadSuggestions() {
    if (!pendingSuggestions) {
        pendingSuggestions = api.getSuggestions()
            .then((data) => {
                suggestions.value = data.suggestions ?? [];
                remember('suggestions', suggestions.value);

                return suggestions.value;
            })
            .catch((error) => {
                console.warn('Vorschläge nicht geladen', error);

                return suggestions.value ?? [];
            })
            .finally(() => {
                pendingSuggestions = null;
            });
    }

    return pendingSuggestions;
}

/**
 * Produkt, das als Operation `product.create` unterwegs ist, sofort im
 * Katalog zeigen. Die Antwort des Servers ersetzt es (`confirmProduct`),
 * eine Ablehnung nimmt es wieder heraus (`dropLocalProduct`).
 */
export function addLocalProduct(product) {
    const local = {
        category: null,
        icon: null,
        description: null,
        packageSize: null,
        brand: null,
        barcode: null,
        foodFacts: null,
        image: null,
        imageUrl: null,
        imageSource: null,
        hidden: false,
        createdBy: user.value?.id ?? null,
        ...product,
        local: true
    };

    localProducts.set(local.id, local);

    return upsert(local);
}

export function confirmProduct(product) {
    localProducts.delete(product.id);

    return upsert(product);
}

export function dropLocalProduct(productId) {
    if (localProducts.delete(productId)) {
        products.value = products.value.filter((product) => product.id !== productId);

        if (listItems.value.has(productId)) {
            dropListItems([productId]);
        }
    }
}

/**
 * Nur der Name ist Pflicht. Ähnliche oder gleiche Namen verhindern nichts:
 * zusammengeführt wird nie automatisch.
 *
 * @param {{ id?: string, name: string, category?: string|null, icon?: string|null,
 *   description?: string|null, packageSize?: string|null, listItem?: boolean,
 *   barcode?: string }} fields
 */
export async function createProduct(fields) {
    const { product } = await api.createProduct({ id: crypto.randomUUID(), ...fields });

    return upsert(product);
}

/** @param {object} changes nur geänderte Felder */
export async function updateProduct(productId, changes) {
    const { product } = await api.updateProduct(productId, changes);

    return upsert(product);
}

export async function setProductHidden(productId, hidden) {
    const { product } = await api.setProductHidden(productId, hidden);

    return upsert(product);
}

/** Listenartikel in den Katalog aufnehmen; dürfen alle, die ihn sehen. */
export async function addProductToCatalog(productId) {
    const { product } = await api.addProductToCatalog(productId);

    return upsert(product);
}

/**
 * Bild setzen, ersetzen oder neu zuschneiden (ohne `original`).
 *
 * @param {{ image: Blob, original?: Blob|null, source?: string|null }} files
 */
export async function setProductImage(productId, files) {
    const { product } = await api.uploadProductImage(productId, files);

    return upsert(product);
}

export async function removeProductImage(productId) {
    const { product } = await api.removeProductImage(productId);

    return upsert(product);
}

/** Änderung aus `ProductImageField` hochladen oder das Bild entfernen. */
export function applyImageChange(productId, change) {
    return change.type === 'remove'
        ? removeProductImage(productId)
        : setProductImage(productId, { image: change.image, original: change.original });
}

/**
 * Gewählte Angaben aus Open Food Facts übernehmen (Werte aus dem Cache des
 * Servers). @param {{ barcode: string, fields: string[] }} choice
 */
export async function applyFoodFacts(productId, choice) {
    const { product } = await api.applyFoodFacts(productId, choice);

    return upsert(product);
}

/**
 * Bild aus Open Food Facts ohne Zuschnitt-Schritt setzen, zuschneiden geht
 * später im Produkt. @param {{ cropSize: number, maxPixels: number }} limits
 */
export async function importFoodFactsImage(productId, barcode, limits) {
    const blob = await api.getFoodFactsImage(barcode, { full: true });

    return setProductImage(productId, { ...await squareImage(blob, limits), source: 'openfoodfacts' });
}

export async function removeFoodFacts(productId) {
    const { product } = await api.removeFoodFacts(productId);

    return upsert(product);
}

export function canEditProduct(product) {
    if (!product || !user.value) {
        return false;
    }

    if (product.listItem || canManageProducts.value) {
        return true;
    }

    return !product.hidden && product.createdBy === user.value.id;
}

export function useProducts() {
    const byId = computed(() => new Map(products.value.map((product) => [product.id, product])));

    return {
        products,
        listItems,
        status,
        suggestions,
        canManageProducts,
        // Katalog für alle: ohne ausgeblendete
        catalog: computed(() => products.value.filter((product) => !product.hidden)),
        findProduct: (productId) => byId.value.get(productId) ?? listItems.value.get(productId) ?? null,
        search: (query, options) => searchProducts(query, products.value, options),
        canEdit: canEditProduct,
        isOwn: (product) => Boolean(product && user.value && product.createdBy === user.value.id)
    };
}
