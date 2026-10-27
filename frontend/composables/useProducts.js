/**
 * Produktkatalog: einmal ganz geladen (`GET /products`, mit ETag fast
 * kostenlos erneut), gesucht wird lokal (`utils/productSearch.js`), damit
 * Vorschläge beim Tippen sofort kommen. Anlegen, Bearbeiten, Ausblenden und
 * das Produktbild gehen direkt an die REST-API; die Antwort ersetzt das
 * Produkt im Zustand.
 *
 * Bilder: `product.image` = { small (128 px), medium (640), large (1600),
 * original } als Adressen im Media-Cache (WebP), `product.imageUrl` = small.
 *
 * Rechte wie im Server (`Products\ProductPermissions`): bearbeiten darf, wer
 * ein Produkt angelegt hat (solange es nicht ausgeblendet ist), und jeder
 * Produktpfleger; ausblenden nur Produktpfleger. Die Oberfläche bietet nur
 * an, was erlaubt ist; entscheidend bleibt der Server (403).
 */
import { computed, ref } from 'vue';
import { api } from '@/api/index.js';
import { locale } from '@/i18n/index.js';
import { searchProducts } from '@/utils/productSearch.js';
import { onLogout, useSession } from './useSession.js';

const products = ref([]);
const status = ref('idle'); // idle · loading · ready · error

let pendingLoad = null;

const { user, canManageProducts } = useSession();

onLogout(() => {
    products.value = [];
    status.value = 'idle';
    pendingLoad = null;
});

function sortByName(list) {
    const collator = new Intl.Collator(locale.value, { sensitivity: 'base', numeric: true });

    return [...list].sort((a, b) => collator.compare(a.name, b.name) || (a.id < b.id ? -1 : 1));
}

function upsert(product) {
    const others = products.value.filter((item) => item.id !== product.id);
    products.value = sortByName([...others, product]);

    return product;
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
                products.value = sortByName(data.products ?? []);
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
 * Nur der Name ist Pflicht. Ähnliche oder gleiche Namen verhindern nichts:
 * zusammengeführt wird nie automatisch.
 *
 * @param {{ name: string, category?: string|null, icon?: string|null,
 *   description?: string|null, packageSize?: string|null }} fields
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

/**
 * Bild setzen, ersetzen oder neu zuschneiden (ohne `original`).
 *
 * @param {{ image: Blob, original?: Blob|null }} files
 */
export async function setProductImage(productId, files) {
    const { product } = await api.uploadProductImage(productId, files);

    return upsert(product);
}

export async function removeProductImage(productId) {
    const { product } = await api.removeProductImage(productId);

    return upsert(product);
}

export function canEditProduct(product) {
    if (!product || !user.value) {
        return false;
    }

    if (canManageProducts.value) {
        return true;
    }

    return !product.hidden && product.createdBy === user.value.id;
}

export function useProducts() {
    const byId = computed(() => new Map(products.value.map((product) => [product.id, product])));

    return {
        products,
        status,
        canManageProducts,
        // Katalog für alle: ohne ausgeblendete
        catalog: computed(() => products.value.filter((product) => !product.hidden)),
        findProduct: (productId) => byId.value.get(productId) ?? null,
        search: (query, options) => searchProducts(query, products.value, options),
        canEdit: canEditProduct,
        isOwn: (product) => Boolean(product && user.value && product.createdBy === user.value.id)
    };
}
