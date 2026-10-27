import { createRouter, createWebHashHistory } from '@ionic/vue-router';
import HomeView from './views/HomeView.vue';
import { loadSession, useSession } from './composables/useSession.js';
import { installPageTransitionHandling } from './composables/usePageTransition.js';

// Hash-Routing (/#/profile): QUIQQER kennt keine Seiten für App-Pfade.
// Alle Ansichten außer `meta.public` verlangen eine Anmeldung;
// `meta.manage` zusätzlich das Recht pk.manage.
const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('./views/LoginView.vue'),
        meta: {
            public: true
        }
    },
    {
        path: '/',
        name: 'home',
        component: HomeView
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('./views/ProfileView.vue')
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/'
    }
];

const router = createRouter({
    history: createWebHashHistory(),
    routes
});

installPageTransitionHandling(router);

const { status, canManage } = useSession();

router.beforeEach(async (to) => {
    if (status.value === 'unknown') {
        try {
            await loadSession();
        } catch (error) {
            // Server nicht erreichbar: Anmeldeseite zeigt den Fehler beim Versuch
            console.error(error);
        }
    }

    if (to.meta.public) {
        return status.value === 'authenticated' && to.name === 'login' ? { path: '/' } : true;
    }

    if (status.value !== 'authenticated') {
        return {
            name: 'login',
            query: to.fullPath === '/' ? {} : { redirect: to.fullPath }
        };
    }

    if (to.meta.manage && !canManage.value) {
        return { path: '/', replace: true };
    }

    return true;
});

export default router;
