/**
 * Kurze Hinweise unten, optional mit „Rückgängig“.
 * Über Ionic, damit der Toast auch über offenen Dialogen liegt.
 * Immer nur ein Toast: ein neuer ersetzt den vorherigen.
 */
import { toastController } from '@ionic/vue';

const DURATION = 5000;

let current = null;

/**
 * @param {string} message
 * @param {{ action?: { text: string, handler: () => void }, position?: 'bottom'|'top' }} [options]
 *   action zeigt einen Textknopf (z. B. „Rückgängig“); 'top', wenn unten ein Bottom Sheet offen ist
 */
export async function showToast(message, { action, position = 'bottom' } = {}) {
    if (current) {
        current.dismiss();
    }

    const toast = await toastController.create({
        message,
        duration: DURATION,
        position,
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
