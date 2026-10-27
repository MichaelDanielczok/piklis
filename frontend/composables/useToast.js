/**
 * Kurze Hinweise unten, optional mit Aktion („Rückgängig“), nach dem
 * Designpaket: dunkle Fläche, Symbol links, grüne Aktion, 5 s.
 * Über Ionic, damit der Toast auch über offenen Sheets liegt.
 * Immer nur ein Toast: ein neuer ersetzt den vorherigen.
 */
import { toastController } from '@ionic/vue';

const DURATION = 5000;

// Symbole als SVG-Daten (Lucide, Linie 2); Ionic färbt sie über ::part(icon).
// base64: ion-icon lädt utf8-Daten-URLs nicht.
const svg = (paths) => 'data:image/svg+xml;base64,' + btoa(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    + 'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>'
);

const ICONS = {
    done: svg('<path d="M20 6 9 17l-5-5"/>'),
    notgot: svg('<path d="M18 6 6 18M6 6l12 12"/>'),
    info: svg('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>')
};

let current = null;

/**
 * @param {string} message
 * @param {{
 *   action?: { text: string, handler: () => void },
 *   icon?: 'done'|'notgot'|'info',
 *   position?: 'bottom'|'top'
 * }} [options]
 *   action zeigt einen Textknopf (z. B. „Rückgängig“); 'top', wenn unten ein Sheet offen ist
 */
export async function showToast(message, { action, icon, position = 'bottom' } = {}) {
    if (current) {
        current.dismiss();
    }

    const toast = await toastController.create({
        message,
        duration: DURATION,
        position,
        icon: icon ? ICONS[icon] : undefined,
        cssClass: position === 'top' ? ['app-toast', 'app-toast--top'] : 'app-toast',
        buttons: action ? [{ text: action.text, handler: action.handler }] : []
    });

    current = toast;
    toast.onDidDismiss().then(() => {
        if (current === toast) {
            current = null;
        }
    });

    await toast.present();
}
