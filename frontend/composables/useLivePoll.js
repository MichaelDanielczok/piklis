/**
 * Live-Abgleich einer Ansicht: fragt `task` alle `interval` ms ab, solange
 * die Seite aktiv ist (Ionic: zwischen ViewDidEnter und ViewWillLeave) und
 * die App sichtbar. Wird die App wieder sichtbar oder ist wieder Netz da,
 * fragt sie sofort. Keine WebSockets; die Abfragen nutzen ETag, unverändert
 * antwortet der Server mit 304.
 *
 * Das erste Laden macht die Ansicht selbst (onIonViewWillEnter), der Takt
 * beginnt danach.
 */
import { onBeforeUnmount } from 'vue';
import { onIonViewDidEnter, onIonViewWillLeave } from '@ionic/vue';
import { createPoller } from '@/utils/poller.js';

export const LIST_POLL_MS = 5000;
export const OVERVIEW_POLL_MS = 30000;

function isVisible() {
    return document.visibilityState === 'visible';
}

/**
 * @param {() => Promise<unknown>} task
 * @param {number} interval
 */
export function useLivePoll(task, interval) {
    const poller = createPoller(task, interval, {
        onError: (error) => console.warn('Abgleich fehlgeschlagen', error?.code ?? error)
    });

    const onVisibility = () => poller.setVisible(isVisible());
    const onOnline = () => {
        if (poller.running) {
            poller.trigger();
        }
    };

    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', onOnline);

    onIonViewDidEnter(() => {
        poller.setVisible(isVisible());
        poller.start();
    });

    onIonViewWillLeave(() => poller.stop());

    onBeforeUnmount(() => {
        poller.stop();
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('online', onOnline);
    });

    return poller;
}
