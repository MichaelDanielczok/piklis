/**
 * Verbindung zum Server für die Oberfläche: `online` ist false, sobald eine
 * Anfrage am Netz scheitert, der Browser „offline“ meldet oder im
 * Entwicklungsserver „offline simulieren“ an ist; die nächste gelungene
 * Anfrage setzt es zurück. Daran hängen der Offline-Hinweis und die
 * Kennzeichnung „noch nicht übertragen“.
 */
import { computed, ref } from 'vue';
import { isReachable, isSimulatedOffline, onConnectionChange, setSimulatedOffline } from '@/api/index.js';

const reachable = ref(isReachable());
const browserOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false);
const simulated = ref(isSimulatedOffline());

onConnectionChange(() => {
    reachable.value = isReachable();
    simulated.value = isSimulatedOffline();
});

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        browserOnline.value = true;
    });
    window.addEventListener('offline', () => {
        browserOnline.value = false;
    });
}

const online = computed(() => reachable.value && browserOnline.value && !simulated.value);

export function useConnection() {
    return {
        online,
        simulatedOffline: simulated,
        setSimulatedOffline,
        // Schalter gibt es nur im Entwicklungsserver
        canSimulate: Boolean(import.meta.env?.DEV)
    };
}
