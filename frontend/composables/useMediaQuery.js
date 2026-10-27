/**
 * Reagiert auf eine Media Query, z. B. die Desktop-Grenze `(min-width: 64em)`
 * (Seitenleiste statt Navigation unten, Produktpflege mit zwei Spalten).
 */
import { onBeforeUnmount, ref } from 'vue';

export const DESKTOP_QUERY = '(min-width: 64em)';

export function useMediaQuery(query) {
    const list = window.matchMedia(query);
    const matches = ref(list.matches);

    const update = (event) => {
        matches.value = event.matches;
    };

    list.addEventListener('change', update);
    onBeforeUnmount(() => list.removeEventListener('change', update));

    return matches;
}
