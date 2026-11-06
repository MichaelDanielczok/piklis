/**
 * Variante der Eingabeleiste, gemerkt in `localStorage`. Solange Michael nicht
 * gewählt hat, schaltet ein Knopf in der Leiste B ↔ C.
 *
 * - B: Leiste unten, Vorschläge darüber.
 * - C: Die Eingabe springt nach oben, Vorschläge darunter.
 *
 * Schalter entfernen, eine Zeile: nur die gewählte Variante in `MODES` lassen
 * (`['b']` oder `['c']`). Dann verschwindet der Knopf, und gemerkte Werte
 * zählen nicht mehr.
 */
import { ref } from 'vue';

const MODES = ['b', 'c'];
const MODE_KEY = 'pk.addBarMode';

function readMode() {
    try {
        const stored = localStorage.getItem(MODE_KEY);

        return MODES.includes(stored) ? stored : MODES[0];
    } catch {
        return MODES[0];
    }
}

export const addBarSwitchable = MODES.length > 1;

export const addBarMode = ref(readMode());

export function nextAddBarMode() {
    addBarMode.value = MODES[(MODES.indexOf(addBarMode.value) + 1) % MODES.length];

    try {
        localStorage.setItem(MODE_KEY, addBarMode.value);
    } catch {
        // nur Bequemlichkeit
    }
}
