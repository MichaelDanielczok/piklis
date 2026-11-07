/**
 * Testphase der Halten-Geste: Welche Mengenwahl und welches Aktionsmenü dieser
 * Nutzer sieht (`user.settings`, in den Einstellungen wählbar). Standard ist
 * Lineal und Sheet. Nach dem Test bleibt je eine Variante, dann fällt das weg.
 */
import { computed } from 'vue';
import { useSession } from './useSession.js';

export function useHoldVariants() {
    const { user } = useSession();

    return {
        quantityVariant: computed(() => user.value?.settings?.quantityVariant ?? 'ruler'),
        menuVariant: computed(() => user.value?.settings?.menuVariant ?? 'sheet')
    };
}
