import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

// Quellen unter frontend/, Build nach bin/app/ mit festem Einstieg app.js und
// fester app.css, damit die Standalone-Seite nach einem Build unverändert bleibt.
// Das Build-Ergebnis wird committet; der Server baut nicht selbst.

const PACKAGE = 'michaeldanielczok/piklis';
const APP_NAME = 'Piklis';
// Seitengrund im hellen Motiv (--color-bg); Grün (#1F6E4E) färbte die Statusleiste
// über der hellen Seite
const THEME_COLOR = '#F7F6F3';

// Entwicklung: /api/piklis/ und /ajax.php gehen an die QUIQQER-Instanz, damit
// Anmeldung und Session-Cookie wie im Betrieb funktionieren. Standard: Docker-Testinstanz.
const devBackend = process.env.APP_BACKEND ?? 'http://localhost:8080';

const backendProxy = {
    target: devBackend,
    changeOrigin: true,
    cookieDomainRewrite: '',
    configure(proxy) {
        // Der Core lehnt Anmeldungen fremder Herkunft ab (RequestOrigin)
        proxy.on('proxyReq', (proxyRequest) => {
            if (proxyRequest.getHeader('origin')) {
                proxyRequest.setHeader('origin', devBackend);
            }

            proxyRequest.removeHeader('referer');
        });
    }
};

// Adresse von bin/app/ im Betrieb. Service Worker und Manifest liegen dort, werden
// aber über onRequest unter /sw.js und /manifest.webmanifest ausgeliefert
// (Pwa\EventHandler); alle Adressen darin müssen deshalb absolut sein.
const appBase = `/packages/${PACKAGE}/bin/app/`;

const pwa = VitePWA({
    // Registrierung selbst in pwa.js, die Startseite ist nicht frontend/index.html
    injectRegister: false,
    registerType: 'autoUpdate',
    manifestFilename: 'manifest.webmanifest',
    includeManifestIcons: false,
    manifest: {
        id: '/',
        name: APP_NAME,
        short_name: APP_NAME,
        description: 'Gemeinsame Einkaufslisten für den Haushalt',
        lang: 'de',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        // Manifest kennt nur eine Farbe: helles Motiv (wie --color-bg in tokens.css)
        background_color: THEME_COLOR,
        theme_color: THEME_COLOR,
        icons: [
            { src: appBase + 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
            { src: appBase + 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
            { src: appBase + 'icons/icon-maskable-192.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
            { src: appBase + 'icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
        ]
    },
    workbox: {
        swDest: fileURLToPath(new URL('./bin/app/sw.js', import.meta.url)),
        // Workbox-Laufzeit im sw.js, ein importScripts() suchte sie sonst unter /
        inlineWorkboxRuntime: true,
        globPatterns: ['**/*.{js,css,woff2,webp,png,svg}'],
        // Barcode-Scanner (barcode-detector, zxing-wasm ~1 MB) nur beim Scannen
        // nachgeladen, nicht vorab gecacht; .wasm fällt schon durch globPatterns heraus.
        // Der erste Scan legt ihn in `pk-scanner` ab (unten).
        globIgnores: ['sw.js', 'chunks/barcodeScanner-*.js', 'chunks/ponyfill-*.js'],
        modifyURLPrefix: { '': appBase },
        cleanupOutdatedCaches: true,
        // Die Startseite liefert QUIQQER; gecacht wird sie unten zur Laufzeit
        navigateFallback: null,
        runtimeCaching: [
            {
                // nur die Startseite; /admin/, /api/ und /ajax.php bleiben unberührt (Daten
                // offline: IndexedDB, api/localStore.js).
                // Sofort aus dem Cache, im Hintergrund aktualisiert: Ein Warten aufs Netz
                // zeigte beim Start der installierten App lange nur Weiß.
                urlPattern: ({ request, url }) => request.mode === 'navigate' && url.pathname === '/',
                handler: 'StaleWhileRevalidate',
                options: {
                    cacheName: 'pk-start',
                    expiration: { maxEntries: 1 }
                }
            },
            {
                // Scanner offline: Barcodes aus dem Katalog gehen ohne Verbindung, also muss
                // er nach dem ersten Scan auch ohne Netz starten. Neue Fassungen haben einen
                // neuen Hash im Namen, deshalb CacheFirst.
                urlPattern: ({ url }) => url.origin === self.location.origin
                    && /\/(chunks\/(barcodeScanner|ponyfill)-[^/]+\.js|assets\/zxing_reader-[^/]+\.wasm)$/.test(url.pathname),
                handler: 'CacheFirst',
                options: {
                    cacheName: 'pk-scanner',
                    expiration: { maxEntries: 6 },
                    cacheableResponse: { statuses: [200] }
                }
            },
            {
                // Produktbilder offline: nur Listen- und Detailgröße aus dem Media-Cache
                // (utils/offlineImages.js holt die Bilder der aktiven Listen vorab). Jedes neue
                // Bild hat eine neue Adresse (UUID), deshalb CacheFirst ohne Nachfragen.
                // Großansicht, Originale und alles andere unter /media/ bleiben ungecacht.
                urlPattern: ({ url }) => url.origin === self.location.origin
                    && /^\/media\/cache\/.+__(128x128|640x640)\.webp$/.test(url.pathname),
                handler: 'CacheFirst',
                options: {
                    cacheName: 'pk-images',
                    expiration: { maxEntries: 600, maxAgeSeconds: 60 * 24 * 60 * 60 },
                    cacheableResponse: { statuses: [200] }
                }
            }
        ]
    }
});

export default defineConfig({
    root: fileURLToPath(new URL('./frontend', import.meta.url)),
    base: './',
    // public/ (Icons) landet unverändert in bin/app/
    publicDir: 'public',
    plugins: [vue(), pwa],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./frontend', import.meta.url))
        }
    },
    server: {
        host: true,
        proxy: {
            // nur die REST-API; /api/index.js ist das eigene Frontend-Modul
            '/api/piklis/': backendProxy,
            '/ajax.php': backendProxy,
            '/media/cache/': backendProxy
        }
    },
    build: {
        outDir: fileURLToPath(new URL('./bin/app', import.meta.url)),
        emptyOutDir: true,
        cssCodeSplit: false,
        // Ionic-Kern als ein Chunk (~1,1 MB, gzip ~220 KB), vom Service Worker vorab gecacht
        chunkSizeWarningLimit: 1200,
        rolldownOptions: {
            input: fileURLToPath(new URL('./frontend/app.js', import.meta.url)),
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (asset) => {
                    const names = asset.names ?? [];

                    if (names.some((name) => name.endsWith('.css'))) {
                        return 'app.css';
                    }

                    return 'assets/[name]-[hash][extname]';
                }
            }
        }
    }
});
