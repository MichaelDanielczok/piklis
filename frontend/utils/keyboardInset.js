/**
 * Bildschirmtastatur für das Layout: iOS verkleinert die Seite nicht, wenn
 * die Tastatur aufgeht, das Ende des Inhalts läge dahinter. Ionic meldet die
 * Tastatur in der PWA (`visualViewport`) und später nativ mit demselben
 * Ereignis; hier wird daraus nur die CSS-Variable `--keyboard-height` und
 * `data-keyboard="open"` an der Wurzel. Den Rest erledigt das CSS.
 *
 * @param {EventTarget} target `window`
 * @param {HTMLElement} root `document.documentElement`
 */
export function installKeyboardInset(target, root) {
    target.addEventListener('ionKeyboardDidShow', (event) => {
        root.style.setProperty('--keyboard-height', `${event.detail.keyboardHeight}px`);
        root.dataset.keyboard = 'open';
    });

    target.addEventListener('ionKeyboardDidHide', () => {
        root.style.removeProperty('--keyboard-height');
        delete root.dataset.keyboard;
    });
}
