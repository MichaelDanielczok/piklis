import { createRouter, createWebHashHistory } from '@ionic/vue-router';
import ListsView from './views/ListsView.vue';
import { loadSession, useSession } from './composables/useSession.js';
import { installPageTransitionHandling } from './composables/usePageTransition.js';

// Hash-Routing (/#/products): QUIQQER kennt keine Seiten für App-Pfade.
// Alle Ansichten außer `meta.public` verlangen eine Anmeldung;
// `meta.manageProducts` zusätzlich das Recht piklis.products.manage.
// Hauptziele (Navigation): Listen, Produkte, Vorlagen. Profil, Haushalt und
// Einstellungen kommen aus dem Benutzermenü.
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
        name: 'lists',
        component: ListsView
    },
    {
        path: '/archive',
        name: 'archive',
        component: () => import('./views/ArchiveView.vue')
    },
    {
        path: '/lists/:id',
        name: 'list',
        component: () => import('./views/ListView.vue')
    },
    {
        path: '/lists/:id/add',
        name: 'list-add',
        component: () => import('./views/ListAddView.vue')
    },
    {
        path: '/lists/:id/entries/:entryId',
        name: 'list-entry',
        component: () => import('./views/ListEntryView.vue')
    },
    {
        path: '/products',
        name: 'products',
        component: () => import('./views/ProductsView.vue')
    },
    {
        path: '/products/new',
        name: 'product-new',
        component: () => import('./views/ProductEditView.vue')
    },
    {
        path: '/products/:id',
        name: 'product',
        component: () => import('./views/ProductDetailView.vue')
    },
    {
        path: '/products/:id/edit',
        name: 'product-edit',
        component: () => import('./views/ProductEditView.vue')
    },
    {
        path: '/templates',
        name: 'templates',
        component: () => import('./views/TemplatesView.vue')
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('./views/ProfileView.vue')
    },
    {
        path: '/household',
        name: 'household',
        component: () => import('./views/HouseholdView.vue')
    },
    {
        path: '/settings',
        name: 'settings',
        component: () => import('./views/SettingsView.vue')
    },
    // Musterseite und Icon-Übersicht nur im Entwicklungsserver; im Build fehlen sie
    // samt Beispielbildern
    ...(import.meta.env.DEV
        ? [
            {
                path: '/components',
                name: 'components',
                component: () => import('./views/ComponentsView.vue')
            },
            {
                path: '/icons',
                name: 'icons',
                component: () => import('./views/IconsView.vue')
            }
        ]
        : []),
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

const { status, canManageProducts } = useSession();

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

    if (to.meta.manageProducts && !canManageProducts.value) {
        return { path: '/', replace: true };
    }

    return true;
});

export default router;
