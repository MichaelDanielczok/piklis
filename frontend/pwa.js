/**
 * Service Worker der PWA. Die Datei entsteht beim Build in bin/app/, das Paket
 * liefert sie über onRequest unter /sw.js aus, damit sie die Startseite / steuert.
 * Nur im Build; der Vite-Server läuft ohne Service Worker.
 */
export function registerServiceWorker() {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
        return;
    }

    // Ein neuer Build übernimmt sofort (skipWaiting). Die offene Seite stammt dann
    // vom alten Build und fände dessen Chunks nicht mehr, also einmal neu laden.
    // Beim allerersten Besuch gibt es keinen alten Build, dort nicht neu laden.
    const hadController = Boolean(navigator.serviceWorker.controller);
    let reloading = false;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!hadController || reloading) {
            return;
        }

        reloading = true;
        window.location.reload();
    });

    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
        // ohne sicheren Kontext (HTTP außer localhost) gibt es keinen Service Worker; die App läuft trotzdem
        console.warn('Service worker not registered', error);
    });
}
