/**
 * Schalter des Eingabeleisten-Prototyps (B → C → aus), gemerkt in
 * `localStorage`. Eigene Datei, weil `App.vue` ihn auch dann braucht, wenn die
 * Listenansicht gar nicht geladen ist (Unteransicht direkt geöffnet). Schritt
 * 19 entfernt sie mit dem Prototyp.
 */
import { ref } from 'vue';

const MODE_KEY = 'pk.addBarPrototype';
const MODES = ['b', 'c', 'off'];
const DEFAULT_MODE = import.meta.env.DEV ? 'b' : 'off';

function readMode() {
    try {
        const stored = localStorage.getItem(MODE_KEY);

        return MODES.includes(stored) ? stored : DEFAULT_MODE;
    } catch {
        return DEFAULT_MODE;
    }
}

export const addBarMode = ref(readMode());

export function nextAddBarMode() {
    addBarMode.value = MODES[(MODES.indexOf(addBarMode.value) + 1) % MODES.length];

    try {
        localStorage.setItem(MODE_KEY, addBarMode.value);
    } catch {
        // nur Bequemlichkeit
    }
}
