<script setup>
/**
 * Eingabeleiste einer aktiven Liste (Obsidian „Piklis Konzept“ › „Neue Liste
 * und Produktauswahl“, docs/architecture.md, „Input bar“). Steht unten anstelle
 * der Navigation, führt auch zum Scanner und zur Kachelansicht.
 *
 * - Vorschläge: beim Öffnen die häufigsten Produkte (wie die Kachelansicht), beim
 *   Tippen die Treffer der Katalogsuche. Listenartikel erscheinen dort nie.
 *   B zeigt sie als Liste, C als Kacheln.
 * - Ein Vorschlag fügt genau dieses Produkt hinzu. Die Eingabetaste nimmt nur
 *   einen gleichen Namen aus dem Katalog, sonst entsteht ein Listenartikel; ein
 *   Teiltreffer wird nie gewählt. Offen auf der Liste heißt +1, erledigt ein neuer
 *   offener Eintrag. Alles läuft über die Warteschlange, geht also auch offline.
 * - Kein Toast, keine Rückfrage, die Tastatur bleibt offen: Die Vorschläge halten
 *   den Fokus im Feld, Bildschirmleser hören die Meldung.
 * - `autocorrect="off"`: iOS würde sonst unbekannte Namen beim Eintragen ersetzen.
 * - `:value` statt `v-model`: `v-model` wartet auf das Ende einer Komposition, die
 *   Vorschläge kämen auf Android erst nach dem Wort.
 *
 * Zwei Varianten, bis Michael gewählt hat (`addBarMode.js`):
 *
 * - B: Vorschläge über der Leiste, die bei offener Tastatur direkt darüber sitzt
 *   (`--keyboard-height`). In Ruhe liegt ein Knopf über dem Feld und setzt den
 *   Fokus mit `preventScroll`: Tippt man direkt ins Feld, schiebt iOS die ganze
 *   Seite um die Tastaturhöhe hoch, und Vorschläge sind nicht mehr dort antippbar,
 *   wo man sie sieht.
 * - C: Eingabe und Kacheln in einer Ebene über der Seite. Das Feld steht immer
 *   im DOM, denn nur ein vorhandenes Feld bekommt im selben Tipp den Fokus. Es
 *   erscheint der ganze Katalog, beim Scrollen kommen weitere Kacheln dazu. Was
 *   beim Öffnen oder beim Leeren der Suche offen auf der Liste steht, fehlt, die
 *   Suche findet es trotzdem. Ohne Suche angetippte Kacheln bleiben bis dahin
 *   stehen, damit nichts unter dem Finger springt. Nach einem Tipp auf einen
 *   Treffer bleibt die Suche 400 ms stehen: Das Badge zeigt, was dazukam, und ein
 *   schneller zweiter Tipp trifft dasselbe Produkt. Wer sofort weitertippt,
 *   beginnt eine neue Suche.
 *
 * Props: listId · Emits: tiles · Exponiert `close()`
 */
import { computed, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue';
import { useIonRouter } from '@ionic/vue';
import { LayoutGrid, ScanBarcode, X } from '@lucide/vue';
import AddBarScanSheet from './AddBarScanSheet.vue';
import AddBarSuggestions from './AddBarSuggestions.vue';
import AddBarTiles from './AddBarTiles.vue';
import { addBarMode as mode, addBarSwitchable, nextAddBarMode } from './addBarMode.js';
import { createProductOnList, increaseProduct, listView, setProductQuantity } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { dismissToast } from '@/composables/useToast.js';
import { FIRST_PAGE, nextLimit, topProductIds } from '@/utils/pickerOrder.js';
import { exactProduct } from '@/utils/productSearch.js';
import { formatQuantity } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const SUGGESTION_LIMIT = 20;
const CLEAR_DELAY_MS = 400;
const NAME_MAX = 120;

const props = defineProps({
    listId: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['tiles']);

const ionRouter = useIonRouter();

const { catalog, findProduct, search, suggestions } = useProducts();
const listState = listView(props.listId);

const open = ref(false);
const text = ref('');
const scanOpen = ref(false);
const announcement = ref('');
/** C: beim Öffnen oder Leeren der Suche offene Produkte; was danach dazukommt, bleibt als Kachel stehen. */
const openOnArrival = shallowRef(new Set());
const tileLimit = ref(FIRST_PAGE);

const input = useTemplateRef('input');

let clearTimer = null;

const isB = computed(() => mode.value === 'b');
const query = computed(() => text.value.trim());

const shown = computed(() => {
    const products = query.value !== ''
        ? search(query.value, { limit: SUGGESTION_LIMIT })
        : topProductIds(catalog.value, suggestions.value, SUGGESTION_LIMIT).map(findProduct);

    return products.map((product) => ({ product, meta: metaOf(product.id) }));
});

/** Ausgeblendet: beim Öffnen offen und noch immer offen (sonst z. B. von jemandem erledigt). */
const hidden = computed(() => new Set([...openOnArrival.value].filter((id) => entryOf(id))));

const tiles = computed(() => {
    const products = query.value !== ''
        ? search(query.value, { limit: tileLimit.value })
        : topProductIds(catalog.value, suggestions.value, tileLimit.value, hidden.value).map(findProduct);

    return products.map((product) => {
        const entry = entryOf(product.id);

        return {
            product,
            amount: entry?.quantity.amount ?? 0,
            unit: entry?.quantity.unit ?? null,
            meta: tileMeta(product, entry)
        };
    });
});

/** Die Eingabetaste legt einen Listenartikel an: das steht als Zeile über den Kacheln. */
const freeName = computed(() => (query.value !== '' && !exactProduct(query.value, catalog.value) ? query.value : ''));

// neue Kacheln beginnen oben; ohne Suche fehlt, was eben dazukam
watch(query, (value) => {
    tileLimit.value = FIRST_PAGE;

    if (value === '' && !isB.value) {
        rememberOpen();
    }
});

// ein Toast läge über den Vorschlägen
watch(open, (isOpen) => {
    if (isOpen) {
        dismissToast();
    }
});

const emptyText = computed(() => (query.value !== '' ? t('addBar.noMatch', { name: query.value }) : t('addBar.noProducts')));

const tilesEmptyText = computed(() => {
    if (query.value !== '') {
        return t('addProducts.noResults', { query: query.value });
    }

    return hidden.value.size > 0 ? t('addProducts.allSelected') : t('addBar.noProducts');
});

function entryOf(productId) {
    return listState.value.open.find((item) => item.productId === productId);
}

function rememberOpen() {
    openOnArrival.value = new Set(listState.value.open.map((item) => item.productId));
}

/** Nur eine volle Seite kann mehr haben. */
function showMoreTiles() {
    if (tiles.value.length >= tileLimit.value) {
        tileLimit.value = nextLimit(tileLimit.value);
    }
}

function tileMeta(product, entry) {
    if (!entry && listState.value.done.some((item) => item.productId === product.id)) {
        return t('addProducts.done');
    }

    return product.packageSize ?? '';
}

function metaOf(productId) {
    const entry = entryOf(productId);

    if (entry) {
        return t('addBar.onList', { quantity: formatQuantity(entry.quantity.amount, entry.quantity.unit) });
    }

    return listState.value.done.some((item) => item.productId === productId) ? t('addProducts.done') : '';
}

function keepTyping() {
    text.value = '';
    input.value?.focus({ preventScroll: true });
}

function cancelClear() {
    clearTimeout(clearTimer);
    clearTimer = null;
}

/** C: Die Suche bleibt kurz stehen; jeder weitere Tipp verlängert das. */
function clearSoon() {
    if (query.value === '') {
        return;
    }

    cancelClear();
    clearTimer = setTimeout(() => {
        clearTimer = null;
        keepTyping();
    }, CLEAR_DELAY_MS);
}

function onInput(event) {
    const restart = clearTimer !== null;

    cancelClear();
    text.value = restart ? (event.data ?? '') : event.target.value;
    event.target.value = text.value;
}

function addProduct(product) {
    increaseProduct(props.listId, product.id);
    announcement.value = t('addProducts.added', { name: product.name });
}

function pick(product) {
    addProduct(product);
    keepTyping();
}

function setTile(product, amount) {
    const entry = entryOf(product.id);
    const before = entry?.quantity.amount ?? 0;

    setProductQuantity(props.listId, product.id, amount);

    if (before === 0 && amount > 0) {
        announcement.value = t('addProducts.added', { name: product.name });
    } else if (before > 0 && amount <= 0) {
        announcement.value = t('addProducts.removed', { name: product.name });
    } else if (amount > 0) {
        announcement.value = t('addProducts.quantity', {
            name: product.name,
            quantity: formatQuantity(amount, entry?.quantity.unit ?? null)
        });
    }
}

function openDetails(product) {
    const entry = entryOf(product.id);

    close();
    ionRouter.push(entry
        ? '/lists/' + encodeURIComponent(props.listId) + '/entries/' + encodeURIComponent(entry.id)
        : '/products/' + encodeURIComponent(product.id));
}

function submit() {
    // die Suche ist schon erledigt und läuft nur noch aus
    if (clearTimer !== null) {
        cancelClear();
        keepTyping();
        return;
    }

    if (query.value === '') {
        return;
    }

    const product = exactProduct(query.value, catalog.value);

    if (product) {
        addProduct(product);
    } else {
        createProductOnList(props.listId, query.value);
        announcement.value = t('addProducts.added', { name: query.value });
    }

    keepTyping();
}

function openBar() {
    if (!isB.value) {
        rememberOpen();
        tileLimit.value = FIRST_PAGE;
    }

    open.value = true;
    // im selben Tipp, sonst öffnet iOS die Tastatur nicht
    input.value?.focus({ preventScroll: true });
}

function close() {
    cancelClear();
    open.value = false;
    text.value = '';
    input.value?.blur();
}

/** B schließt, sobald der Fokus die Leiste verlässt; zwischen ihren Knöpfen bleibt sie offen. */
function onFocusOut(event) {
    if (isB.value && !event.currentTarget.contains(event.relatedTarget)) {
        open.value = false;
    }
}

function switchMode() {
    close();
    nextAddBarMode();
}

function openScanner() {
    close();
    scanOpen.value = true;
}

function openTiles() {
    close();
    emit('tiles');
}

onBeforeUnmount(cancelClear);

defineExpose({ close });
</script>

<template>
    <div v-if="isB && open" class="add-bar__backdrop" aria-hidden="true" @click="close"></div>

    <div class="add-bar" :class="{ 'add-bar--open': isB && open }" @focusout="onFocusOut" @keydown.esc="close">
        <AddBarSuggestions
            v-if="isB && open"
            class="add-bar__above"
            :items="shown"
            :label="t('addBar.suggestions')"
            :empty-text="emptyText"
            @pick="pick"
        />

        <form class="add-bar__bar" @submit.prevent="submit">
            <div class="add-bar__field">
                <input
                    v-if="isB"
                    ref="input"
                    class="add-bar__input"
                    type="text"
                    :value="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('addBar.placeholder')"
                    :aria-label="t('addBar.placeholder')"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="sentences"
                    spellcheck="false"
                    enterkeyhint="enter"
                    @input="onInput"
                    @focus="open = true"
                >
                <!-- `tabindex`: Mit der Tastatur geht es direkt ins Feld, dort verschiebt sich nichts -->
                <button
                    v-if="isB"
                    v-show="!open"
                    type="button"
                    class="add-bar__tap"
                    tabindex="-1"
                    :aria-label="t('addBar.placeholder')"
                    @mousedown.prevent
                    @click="openBar"
                ></button>
                <button v-else type="button" class="add-bar__fake" @click="openBar">{{ t('addBar.placeholder') }}</button>
            </div>

            <button
                v-if="addBarSwitchable"
                v-show="!open"
                type="button"
                class="add-bar__button add-bar__button--switch"
                :aria-label="t('addBar.switchMode', { mode: mode.toUpperCase() })"
                @click="switchMode"
            >
                {{ mode.toUpperCase() }}
            </button>
            <button type="button" class="add-bar__button" :aria-label="t('addBar.scan')" aria-haspopup="dialog" @mousedown.prevent @click="openScanner">
                <ScanBarcode aria-hidden="true" />
            </button>
            <button type="button" class="add-bar__button" :aria-label="t('addBar.tiles')" @mousedown.prevent @click="openTiles">
                <LayoutGrid aria-hidden="true" />
            </button>
        </form>
    </div>

    <div v-if="!isB" class="add-bar__layer" :class="{ 'add-bar__layer--open': open }" :aria-hidden="open ? undefined : 'true'" @keydown.esc="close">
        <div class="add-bar__layer-body">
            <form class="add-bar__layer-head" @submit.prevent="submit">
                <input
                    ref="input"
                    class="add-bar__input"
                    type="text"
                    :value="text"
                    :maxlength="NAME_MAX"
                    :placeholder="t('addBar.placeholder')"
                    :aria-label="t('addBar.placeholder')"
                    :tabindex="open ? 0 : -1"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="sentences"
                    spellcheck="false"
                    enterkeyhint="enter"
                    @input="onInput"
                >
                <button type="button" class="add-bar__button" :aria-label="t('common.close')" :tabindex="open ? 0 : -1" @mousedown.prevent @click="close">
                    <X aria-hidden="true" />
                </button>
            </form>
            <AddBarTiles
                v-if="open"
                :items="tiles"
                :label="t('addBar.suggestions')"
                :empty-text="tilesEmptyText"
                :query="query"
                :free-name="freeName"
                @change="setTile"
                @increase="clearSoon"
                @details="openDetails"
                @free="submit"
                @more="showMoreTiles"
            />
        </div>
    </div>

    <p class="visually-hidden" aria-live="polite">{{ announcement }}</p>

    <AddBarScanSheet v-model:open="scanOpen" :list-id="listId" @found="addProduct" />
</template>

<style scoped>
.add-bar__backdrop {
    position: fixed;
    inset: 0;
    z-index: 11;
}

.add-bar {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: var(--mobile-nav-bottom);
    z-index: 12;
    /* läuft mit der Tastatur mit, die rund 250 ms braucht */
    transition: transform 250ms cubic-bezier(0.2, 0, 0, 1);
}

.add-bar--open {
    /* nie nach unten: `--keyboard-height` kommt erst kurz nach dem Tipp */
    transform: translateY(min(0px, calc(var(--mobile-nav-bottom) - var(--keyboard-height) - var(--space-2))));
}

@media (prefers-reduced-motion: reduce) {
    .add-bar {
        transition: none;
    }
}

/* `position: fixed` gilt hier für die Ionic-Seite (`contain`), also neben der Seitenleiste */
@media (min-width: 45em) {
    .add-bar {
        left: 50%;
        right: auto;
        width: var(--mobile-nav-max-width);
        margin-left: calc(var(--mobile-nav-max-width) / -2);
    }
}

.add-bar__bar {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    height: var(--mobile-nav-height);
    padding: 0 var(--space-2) 0 var(--space-4);
    border-radius: var(--radius-pill);
    background: var(--nav-glass-bg);
    border: 1px solid var(--nav-glass-border);
    box-shadow: var(--shadow-float);
    backdrop-filter: var(--glass-blur) saturate(1.4);
    -webkit-backdrop-filter: var(--glass-blur) saturate(1.4);
}

.add-bar--open .add-bar__bar {
    border-color: var(--color-accent);
}

.add-bar__field {
    position: relative;
    flex: 1;
    min-width: 0;
    height: 100%;
}

.add-bar__input,
.add-bar__fake {
    width: 100%;
    min-width: 0;
    height: 100%;
    padding: 0;
    background: none;
    border: 0;
    color: var(--color-text-1);
    font-size: var(--font-size-title);
    text-align: left;
}

.add-bar__input::placeholder,
.add-bar__fake {
    color: var(--color-text-2);
}

/* den Fokus zeigt die grüne Kontur um Leiste und Kopf */
.add-bar__input:focus-visible {
    outline: none;
}

.add-bar__fake {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.add-bar__tap {
    position: absolute;
    inset: 0;
}

.add-bar__fake:focus-visible,
.add-bar__button:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-accent);
    outline-offset: 2px;
}

.add-bar__button {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
}

/* nur bis Michael gewählt hat, deshalb schmaler als die beiden Knöpfe */
.add-bar__button--switch {
    width: var(--space-8);
    font-size: var(--font-size-note);
    font-weight: var(--font-weight-semibold);
}

.add-bar__above {
    display: flex;
    flex-direction: column-reverse;
    max-height: min(30rem, calc(100svh - env(safe-area-inset-top, 0px) - var(--keyboard-height) - var(--mobile-nav-height) - var(--space-14)));
    margin-bottom: var(--space-2);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-float);
}

/* am Handy kurz warten, bis die Tastaturhöhe bekannt ist, sonst wäre die Liste
   einen Moment zu hoch; ohne Bildschirmtastatur kommt nie eine Höhe, dann gilt
   die Leiste unten */
@media (pointer: coarse) {
    :root:not([data-keyboard='open']) .add-bar__above {
        animation: add-bar-wait 1ms 400ms both;
    }
}

@keyframes add-bar-wait {
    from {
        visibility: hidden;
    }

    to {
        visibility: visible;
    }
}

.add-bar__layer {
    position: fixed;
    inset: 0;
    z-index: 13;
    padding-top: env(safe-area-inset-top, 0px);
    background: var(--color-bg);
    opacity: 0;
    pointer-events: none;
}

.add-bar__layer--open {
    opacity: 1;
    pointer-events: auto;
}

.add-bar__layer-body {
    display: flex;
    flex-direction: column;
    max-width: var(--list-content-width);
    height: 100%;
    margin: 0 auto;
}

.add-bar__layer-head {
    flex: none;
    display: flex;
    align-items: center;
    gap: var(--space-1);
    height: var(--mobile-nav-height);
    padding: 0 var(--space-2) 0 var(--space-4);
    border-bottom: 1px solid var(--color-border-subtle);
}

.add-bar__layer-head:focus-within {
    border-bottom-color: var(--color-accent);
}
</style>
