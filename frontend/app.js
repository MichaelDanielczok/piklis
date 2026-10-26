import { createApp } from 'vue';
import { IonicVue } from '@ionic/vue';

import '@ionic/vue/css/core.css';
import './styles/tokens.css';
import './styles/base.css';

import App from './App.vue';
import router from './router.js';
import { applyDocumentLanguage } from './i18n/index.js';
import { onUnauthorized } from './api/index.js';
import { applyStoredTheme, markLoggedOut } from './composables/useSession.js';
import { registerServiceWorker } from './pwa.js';

applyDocumentLanguage();
applyStoredTheme();

// Session abgelaufen: zurück zur Anmeldeseite, danach wieder hierher
onUnauthorized(() => {
    const current = router.currentRoute.value;

    markLoggedOut();

    if (!current.meta.public) {
        router.replace({ name: 'login', query: { redirect: current.fullPath } });
    }
});

const app = createApp(App)
    .use(IonicVue, {
        // überall gleiches Aussehen, auch auf Android
        mode: 'ios',
        // Zurück-Geste übernimmt der Browser (siehe usePageTransition.js)
        swipeBackEnabled: false
    })
    .use(router);

router.isReady().then(() => {
    app.mount('#app');
    registerServiceWorker();
});
