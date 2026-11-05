<script setup>
/**
 * Prototyp der Eingabeleiste (Schritt 15), nur im Entwicklungsserver: Die
 * Komponente wird hinter `import.meta.env.DEV` geladen und fehlt im Build.
 * Sie prüft nur, wie sich die Leiste am iPhone verhält. Vorschläge sind eine
 * feste Liste, angetippt wird nur hier im Speicher gezählt, nichts geht an
 * Liste oder Server.
 *
 * - Variante B: Leiste unten, beim Fokus direkt über der Tastatur
 *   (`--keyboard-height`), die Vorschläge darüber. Ein Knopf liegt in Ruhe über
 *   dem Feld und setzt den Fokus mit `preventScroll`: Tippt man direkt ins Feld,
 *   schiebt iOS die ganze Seite um die Tastaturhöhe hoch, und Vorschläge sind
 *   nicht mehr dort antippbar, wo man sie sieht.
 * - Variante C: Die Eingabe springt nach oben, die Vorschläge stehen darunter.
 *
 * `@mousedown.prevent` auf den Knöpfen hält den Fokus im Feld, die Tastatur
 * bleibt offen.
 *
 * Der Knopf rechts über der Leiste schaltet B → C → aus; gemerkt in
 * `localStorage`. „Aus“ zeigt wieder die Glas-Navigation. Schritt 19 baut die
 * gewählte Variante fertig und entfernt diese Datei.
 */
import { computed, onBeforeUnmount, reactive, ref, useTemplateRef, watchEffect } from 'vue';
import { useRouter } from 'vue-router';
import { LayoutGrid, Plus, ScanBarcode, X } from '@lucide/vue';

const MODE_KEY = 'pk.addBarPrototype';
const MODES = ['b', 'c', 'off'];
const MODE_LABELS = { b: 'Leiste B', c: 'Leiste C', off: 'Leiste aus' };

// Beispiele in der Länge echter Treffer; „auf der Liste“ zeigt die längste Zeile
const SUGGESTIONS = [
    { name: 'Milch 3,5 %', onList: 1 },
    { name: 'Milch 1,5 %' },
    { name: 'Hafermilch' },
    { name: 'Buttermilch' },
    { name: 'Kondensmilch 7,5 %' },
    { name: 'Milchreis' },
    { name: 'Milchbrötchen' },
    { name: 'Kokosmilch' }
];

const router = useRouter();

const mode = ref(readMode());
const open = ref(false);
const text = ref('');
// nur für die Anzeige, geht nirgends hin
const counts = reactive(Object.fromEntries(SUGGESTIONS.map((item) => [item.name, item.onList ?? 0])));

const inputB = useTemplateRef('inputB');
const inputC = useTemplateRef('inputC');

// Ionic hält die Seite im Verlauf: nur solange die Liste vorn liegt
const active = computed(() => router.currentRoute.value.name === 'list');

function readMode() {
    try {
        const stored = localStorage.getItem(MODE_KEY);

        return MODES.includes(stored) ? stored : 'b';
    } catch {
        return 'b';
    }
}

function nextMode() {
    close();
    mode.value = MODES[(MODES.indexOf(mode.value) + 1) % MODES.length];

    try {
        localStorage.setItem(MODE_KEY, mode.value);
    } catch {
        // nur Bequemlichkeit
    }
}

function metaOf(name) {
    return counts[name] > 0 ? `auf der Liste · ${counts[name]} ×` : '';
}

function add(name) {
    counts[name] += 1;
    text.value = '';
}

function submit() {
    text.value = '';
}

function openB() {
    open.value = true;
    // im selben Tipp, sonst öffnet iOS die Tastatur nicht
    inputB.value?.focus({ preventScroll: true });
}

function openC() {
    open.value = true;
    // im selben Tipp, sonst öffnet iOS die Tastatur nicht
    inputC.value?.focus();
}

function close() {
    open.value = false;
    text.value = '';
    inputB.value?.blur();
    inputC.value?.blur();
}

// die Leiste steht anstelle der Glas-Navigation
watchEffect(() => {
    if (active.value && mode.value !== 'off') {
        document.documentElement.dataset.addbar = mode.value;
    } else {
        delete document.documentElement.dataset.addbar;
    }
});

onBeforeUnmount(() => {
    delete document.documentElement.dataset.addbar;
});
</script>

<template>
    <button v-show="!open" type="button" class="addbar-switch" @click="nextMode">{{ MODE_LABELS[mode] }}</button>

    <template v-if="mode === 'b'">
        <div v-if="open" class="addbar-backdrop" aria-hidden="true" @click="close"></div>
        <div class="addbar-dock" :class="{ 'addbar-dock--open': open }">
            <ul v-if="open" class="addbar-suggestions addbar-suggestions--above" aria-label="Vorschläge">
                <li v-for="item in SUGGESTIONS" :key="item.name">
                    <button type="button" class="addbar-suggestion" @mousedown.prevent @click="add(item.name)">
                        <span class="addbar-suggestion__name">{{ item.name }}</span>
                        <span v-if="metaOf(item.name)" class="addbar-suggestion__meta">{{ metaOf(item.name) }}</span>
                        <Plus class="addbar-suggestion__icon" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <form class="addbar-bar" @submit.prevent="submit">
                <input
                    ref="inputB"
                    v-model="text"
                    class="addbar-bar__input"
                    type="text"
                    placeholder="Produkt hinzufügen"
                    aria-label="Produkt hinzufügen"
                    autocomplete="off"
                    @focus="open = true"
                    @blur="open = false"
                >
                <button v-show="!open" type="button" class="addbar-bar__tap" aria-label="Produkt hinzufügen" @click="openB"></button>
                <button type="button" class="addbar-bar__button" aria-label="Scannen" @mousedown.prevent><ScanBarcode aria-hidden="true" /></button>
                <button type="button" class="addbar-bar__button" aria-label="Kachelansicht" @mousedown.prevent><LayoutGrid aria-hidden="true" /></button>
            </form>
        </div>
    </template>

    <template v-else-if="mode === 'c'">
        <div class="addbar-dock">
            <div class="addbar-bar">
                <button type="button" class="addbar-bar__fake" @click="openC">Produkt hinzufügen</button>
                <button type="button" class="addbar-bar__button" aria-label="Scannen"><ScanBarcode aria-hidden="true" /></button>
                <button type="button" class="addbar-bar__button" aria-label="Kachelansicht"><LayoutGrid aria-hidden="true" /></button>
            </div>
        </div>
        <!-- immer im DOM: nur ein vorhandenes Feld bekommt im selben Tipp den Fokus -->
        <div class="addbar-sheet" :class="{ 'addbar-sheet--open': open }">
            <form class="addbar-sheet__head" @submit.prevent="submit">
                <input
                    ref="inputC"
                    v-model="text"
                    class="addbar-bar__input addbar-sheet__input"
                    type="text"
                    placeholder="Produkt hinzufügen"
                    aria-label="Produkt hinzufügen"
                    autocomplete="off"
                    :tabindex="open ? 0 : -1"
                >
                <button type="button" class="addbar-bar__button" aria-label="Schließen" @mousedown.prevent @click="close"><X aria-hidden="true" /></button>
            </form>
            <ul class="addbar-suggestions addbar-suggestions--below" aria-label="Vorschläge">
                <li v-for="item in SUGGESTIONS" :key="item.name">
                    <button type="button" class="addbar-suggestion" @mousedown.prevent @click="add(item.name)">
                        <span class="addbar-suggestion__name">{{ item.name }}</span>
                        <span v-if="metaOf(item.name)" class="addbar-suggestion__meta">{{ metaOf(item.name) }}</span>
                        <Plus class="addbar-suggestion__icon" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </div>
    </template>
</template>

<style>
:root[data-addbar] .mobile-nav {
    visibility: hidden;
}
</style>

<style scoped>
.addbar-switch {
    position: fixed;
    right: var(--space-4);
    bottom: calc(var(--mobile-nav-bottom) + var(--mobile-nav-height) + var(--space-2));
    z-index: 11;
    min-height: var(--space-8);
    padding: 0 var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-inverse-bg);
    color: var(--color-inverse-text);
    font-size: var(--font-size-caption);
    font-weight: var(--font-weight-semibold);
}

.addbar-backdrop {
    position: fixed;
    inset: 0;
    z-index: 11;
}

.addbar-dock {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: var(--mobile-nav-bottom);
    z-index: 12;
    /* läuft mit der Tastatur mit, die rund 250 ms braucht */
    transition: transform 250ms cubic-bezier(0.2, 0, 0, 1);
}

.addbar-dock--open {
    /* nie nach unten: `--keyboard-height` kommt erst kurz nach dem Tipp */
    transform: translateY(min(0px, calc(var(--mobile-nav-bottom) - var(--keyboard-height) - var(--space-2))));
}

@media (prefers-reduced-motion: reduce) {
    .addbar-dock {
        transition: none;
    }
}

@media (min-width: 45em) {
    .addbar-dock {
        left: 50%;
        right: auto;
        width: var(--mobile-nav-max-width);
        margin-left: calc(var(--mobile-nav-max-width) / -2);
    }
}

.addbar-bar {
    position: relative;
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

.addbar-bar__input,
.addbar-bar__fake {
    flex: 1;
    min-width: 0;
    height: 100%;
    padding: 0;
    background: none;
    border: 0;
    color: var(--color-text-1);
    font-size: var(--font-size-title);
    text-align: left;
}

.addbar-bar__input::placeholder,
.addbar-bar__fake {
    color: var(--color-text-2);
}

.addbar-bar__input:focus-visible {
    outline: none;
}

.addbar-bar__tap {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    right: calc(var(--stepper-button-size) * 2 + var(--space-3));
}

.addbar-bar__button {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--stepper-button-size);
    height: var(--stepper-button-size);
    border-radius: var(--radius-pill);
    color: var(--color-text-2);
}

.addbar-suggestions {
    overflow-y: auto;
    overscroll-behavior: contain;
    background: var(--color-surface-1);
}

/* am Handy erst mit bekannter Tastaturhöhe, sonst wäre die Liste kurz zu hoch */
@media (pointer: coarse) {
    :root:not([data-keyboard='open']) .addbar-suggestions--above {
        visibility: hidden;
    }
}

.addbar-suggestions--above {
    display: flex;
    flex-direction: column-reverse;
    max-height: calc(100svh - var(--keyboard-height) - var(--mobile-nav-height) - var(--space-14));
    margin-bottom: var(--space-2);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-float);
}

.addbar-suggestions--below {
    flex: 1;
    min-height: 0;
    padding-bottom: var(--keyboard-height);
}

.addbar-suggestion {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--stepper-button-size);
    padding: 0 var(--space-3) 0 var(--space-4);
    border-top: 1px solid var(--color-border-subtle);
    color: var(--color-text-1);
    font-size: var(--font-size-text);
    text-align: left;
}

.addbar-suggestion__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.addbar-suggestion__meta {
    flex: none;
    color: var(--color-text-2);
    font-size: var(--font-size-note);
}

.addbar-suggestion__icon {
    flex: none;
    width: var(--icon-size-lg);
    height: var(--icon-size-lg);
    color: var(--color-accent-text);
}

.addbar-sheet {
    position: fixed;
    inset: 0;
    z-index: 13;
    display: flex;
    flex-direction: column;
    padding-top: env(safe-area-inset-top, 0px);
    background: var(--color-bg);
    opacity: 0;
    pointer-events: none;
}

.addbar-sheet--open {
    opacity: 1;
    pointer-events: auto;
}

.addbar-sheet__head {
    flex: none;
    display: flex;
    align-items: center;
    gap: var(--space-1);
    height: var(--mobile-nav-height);
    padding: 0 var(--space-2) 0 var(--space-4);
    border-bottom: 1px solid var(--color-border-subtle);
}
</style>
