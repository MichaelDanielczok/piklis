<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { IonApp, IonRouterOutlet } from '@ionic/vue';
import SideNav from '@/components/layout/SideNav.vue';
import MobileNav from '@/components/layout/MobileNav.vue';
import { useSession } from '@/composables/useSession.js';
import { pageTransitionAnimated } from '@/composables/usePageTransition.js';

const route = useRoute();
const { isAuthenticated } = useSession();

// Anmeldeseite ohne Navigation
const showNavigation = computed(() => isAuthenticated.value && !route.meta.public);
</script>

<template>
    <IonApp>
        <div class="app-shell">
            <SideNav v-if="showNavigation" class="app-shell__side" />
            <div class="app-shell__main">
                <IonRouterOutlet :animated="pageTransitionAnimated" />
            </div>
            <MobileNav v-if="showNavigation" class="app-shell__bottom" />
        </div>
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
