<script setup>
/**
 * Eingabeleiste einer aktiven Liste (Obsidian „Piklis Konzept“ › „Neue Liste
 * und Produktauswahl“, docs/architecture.md, „Input bar“). Steht unten anstelle
 * der Navigation, führt auch zum Scanner und zur Kachelansicht.
 *
 * - Vorschläge: beim Öffnen die häufigsten Produkte (wie die Kachelansicht), beim
 *   Tippen die Treffer der Katalogsuche. Listenartikel erscheinen dort nie.
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
 * - C: Eingabe und Vorschläge in einer Ebene über der Seite. Das Feld steht immer
 *   im DOM, denn nur ein vorhandenes Feld bekommt im selben Tipp den Fokus.
 *
 * Props: listId · Emits: tiles · Exponiert `close()`
 */
import { computed, ref, useTemplateRef, watch } from 'vue';
import { LayoutGrid, ScanBarcode, X } from '@lucide/vue';
import AddBarScanSheet from './AddBarScanSheet.vue';
import AddBarSuggestions from './AddBarSuggestions.vue';
import { addBarMode as mode, addBarSwitchable, nextAddBarMode } from './addBarMode.js';
import { createProductOnList, increaseProduct, listView } from '@/composables/useLists.js';
import { useProducts } from '@/composables/useProducts.js';
import { dismissToast } from '@/composables/useToast.js';
import { topProductIds } from '@/utils/pickerOrder.js';
import { exactProduct } from '@/utils/productSearch.js';
import { formatQuantity } from '@/utils/quantity.js';
import { t } from '@/i18n/index.js';

const SUGGESTION_LIMIT = 20;
const NAME_MAX = 120;

const props = defineProps({
    listId: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['tiles']);

const { catalog, findProduct, search, suggestions } = useProducts();
const listState = listView(props.listId);

const open = ref(false);
const text = ref('');
const scanOpen = ref(false);
const announcement = ref('');

const input = useTemplateRef('input');

const isB = computed(() => mode.value === 'b');
const query = computed(() => text.value.trim());

const shown = computed(() => {
    const products = query.value !== ''
        ? search(query.value, { limit: SUGGESTION_LIMIT })
        : topProductIds(catalog.value, suggestions.value, SUGGESTION_LIMIT).map(findProduct);

    return products.map((product) => ({ product, meta: metaOf(product.id) }));
});

// ein Toast läge über den Vorschlägen
watch(open, (isOpen) => {
    if (isOpen) {
        dismissToast();
    }
});

const emptyText = computed(() => (query.value !== '' ? t('addBar.noMatch', { name: query.value }) : t('addBar.noProducts')));

function metaOf(productId) {
    const entry = listState.value.open.find((item) => item.productId === productId);

    if (entry) {
        return t('addBar.onList', { quantity: formatQuantity(entry.quantity.amount, entry.quantity.unit) });
    }

    return listState.value.done.some((item) => item.productId === productId) ? t('addProducts.done') : '';
}

function keepTyping() {
    text.value = '';
    input.value?.focus({ preventScroll: true });
}

function addProduct(product) {
    increaseProduct(props.listId, product.id);
    announcement.value = t('addProducts.added', { name: product.name });
}

function pick(product) {
    addProduct(product);
    keepTyping();
}

function submit() {
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
    open.value = true;
    // im selben Tipp, sonst öffnet iOS die Tastatur nicht
    input.value?.focus({ preventScroll: true });
}

function close() {
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
                    @input="text = $event.target.value"
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
                    @input="text = $event.target.value"
                >
                <button type="button" class="add-bar__button" :aria-label="t('common.close')" :tabindex="open ? 0 : -1" @mousedown.prevent @click="close">
                    <X aria-hidden="true" />
                </button>
            </form>
            <AddBarSuggestions
                v-if="open"
                class="add-bar__below"
                :items="shown"
                :label="t('addBar.suggestions')"
                :empty-text="emptyText"
                @pick="pick"
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

.add-bar__below {
    flex: 1;
    min-height: 0;
    padding-bottom: var(--keyboard-height);
    background: none;
}
</style>
