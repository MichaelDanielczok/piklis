<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { IonApp, IonRouterOutlet } from '@ionic/vue';
import { setLucideProps } from '@lucide/vue';
import SideNav from '@/components/layout/SideNav.vue';
import MobileNav from '@/components/layout/MobileNav.vue';
import ConflictSheet from '@/components/lists/ConflictSheet.vue';
import { isListCompleted } from '@/composables/useLists.js';
import { useSession } from '@/composables/useSession.js';
import { pageTransitionAnimated } from '@/composables/usePageTransition.js';
import { addBarMode } from '@/dev/addBarMode.js';

// Liste und ihre Unteransichten: dort steht die Eingabeleiste statt der Navigation
const ADD_BAR_ROUTES = ['list', 'list-add', 'list-entry'];
// aus „Produkte hinzufügen“ erreichbar, gehören dann noch zur Liste
const PRODUCT_ROUTES = ['product', 'product-edit'];

const route = useRoute();
const { isAuthenticated } = useSession();

// Oberflächen-Icons mit derselben Strichstärke wie die Ersatzicons (1,75)
setLucideProps({ strokeWidth: 1.75 });

// Anmeldeseite ohne Navigation
const showNavigation = computed(() => isAuthenticated.value && !route.meta.public);

// Liste, zu der die aktuelle Ansicht gehört. Ein Produkt erbt sie nur, wenn man
// aus der Liste kommt; über „Produkte“ geöffnet behält es die Navigation.
const listContext = ref(null);

watch(() => route.name, (name) => {
    if (ADD_BAR_ROUTES.includes(name)) {
        listContext.value = String(route.params.id);
    } else if (!PRODUCT_ROUTES.includes(name)) {
        listContext.value = null;
    }
}, { immediate: true });

// Unteransichten zählen mit, sonst taucht die Navigation beim Hin- und Zurückgehen auf
const addBarReplacesNav = computed(() => addBarMode.value !== 'off'
    && listContext.value !== null
    && !isListCompleted(listContext.value));
</script>

<template>
    <IonApp>
        <div class="app-shell">
            <SideNav v-if="showNavigation" class="app-shell__side" />
            <div class="app-shell__main">
                <IonRouterOutlet :animated="pageTransitionAnimated" />
            </div>
            <MobileNav v-if="showNavigation && !addBarReplacesNav" class="app-shell__bottom" />
        </div>
        <!-- Konflikte beim gemeinsamen Bearbeiten kommen auf jeder Seite an -->
        <ConflictSheet v-if="isAuthenticated" />
    </IonApp>
</template>

<style scoped>
.app-shell {
    display: flex;
    flex: 1;
    min-height: 0;
}

.app-shell__side {
    display: none;
}

.app-shell__main {
    position: relative;
    flex: 1;
    min-width: 0;
    /* Seitenwechsel schiebt die alte Seite nach links: nicht über die Seitenleiste */
    overflow: hidden;
}

.app-shell__main :deep(ion-router-outlet) {
    position: absolute;
    inset: 0;
}

@media (min-width: 64em) {
    .app-shell__side {
        display: flex;
    }

    .app-shell__bottom {
        display: none;
    }
}
</style>
