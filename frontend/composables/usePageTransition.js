/**
 * Seitenanimation bei „Zurück“ durch den Browser.
 *
 * Safari (auch die installierte App) hat eine eigene Zurück-Geste: Sie zieht ein
 * Standbild der vorigen Seite herein und löst danach popstate aus. Ionic spielte
 * dann seine Zurück-Animation ein zweites Mal ab, und mit Ionics eigener
 * Wisch-Geste liefen beide gleichzeitig (Seite doppelt zu sehen). Ionics Geste
 * ist deshalb aus (app.js), und ein Zurück, das vom Browser kommt (Geste, Taste),
 * wechselt die Seite ohne Animation. Der Zurück-Pfeil der App animiert weiter.
 *
 * Mit einer nativen Hülle (Capacitor) gibt es keine Browser-Geste; dann Ionics
 * Geste wieder einschalten und dieses Modul überdenken.
 */
import { ref } from 'vue';

const animated = ref(true);

let appBackPending = false;
let browserPopInProgress = false;

/** Für `<IonRouterOutlet :animated>`. */
export const pageTransitionAnimated = animated;

export function installPageTransitionHandling(router) {
    // läuft synchron bei popstate, vor den Guards der Navigation
    router.options.history.listen((to, from, info) => {
        if (info.type === 'pop' && !appBackPending) {
            browserPopInProgress = true;
            animated.value = false;
        }

        appBackPending = false;
    });

    router.beforeEach(() => {
        if (!browserPopInProgress) {
            animated.value = true;
            appBackPending = false;
        }
    });

    // Weiterleitungen gehören zur selben Navigation, erst danach freigeben.
    // animated bleibt false bis zur nächsten Navigation, der Seitenwechsel läuft
    // erst nach afterEach.
    router.afterEach(() => {
        browserPopInProgress = false;
    });
}

/**
 * Zurück über den Pfeil der App: mit Animation. Ohne Verlauf (Seite direkt
 * geöffnet) ersetzt `fallback` die Seite.
 */
export function navigateBack(ionRouter, fallback) {
    if (ionRouter.canGoBack()) {
        appBackPending = true;
        ionRouter.back();
        return;
    }

    ionRouter.navigate(fallback, 'back', 'replace');
}
