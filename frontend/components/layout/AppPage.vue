<script setup>
/**
 * Rahmen jeder Ansicht: Ionic-Seite mit scrollendem Inhalt, oben der Kopf
 * (AppHeader: Titel, Zurück, Benutzermenü), unten Platz für die schwebende
 * Navigation (Mobil) und den Home-Indikator.
 * Hinter der Statusleiste liegt ein Glasstreifen: In der installierten App
 * (black-translucent) liefe der Inhalt sonst sichtbar unter Uhrzeit und Akku
 * durch. Im Browser ist der Sicherheitsabstand 0, der Streifen also auch.
 *
 * `backTo`: Ziel des Zurück-Knopfs ohne Verlauf (Standard `/`).
 * `bare`: ohne Kopf und Navigation (Anmeldeseite), kein Platz unten.
 * `width`: Breite des Inhalts – wide (Standard) · list (Einkaufslisten) ·
 * narrow (Formulare, Profil).
 */
import { IonContent, IonPage } from '@ionic/vue';
import AppHeader from './AppHeader.vue';

defineProps({
    title: {
        type: String,
        default: ''
    },
    subtitle: {
        type: String,
        default: ''
    },
    back: {
        type: Boolean,
        default: false
    },
    backTo: {
        type: String,
        default: '/'
    },
    bare: {
        type: Boolean,
        default: false
    },
    width: {
        type: String,
        default: 'wide',
        validator: (value) => ['wide', 'list', 'narrow'].includes(value)
    }
});
</script>

<template>
    <IonPage>
        <div class="app-page__statusbar" aria-hidden="true"></div>
        <IonContent class="app-page" :class="{ 'app-page--bare': bare }">
            <div class="app-page__inner" :class="'app-page__inner--' + width">
                <AppHeader v-if="!bare" :title="title" :subtitle="subtitle" :back="back" :back-to="backTo">
                    <template v-if="$slots.actions" #actions>
                        <slot name="actions" />
                    </template>
                </AppHeader>
                <slot />
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.app-page {
    --background: var(--color-bg);
    --color: var(--color-text-1);
    --padding-top: env(safe-area-inset-top, 0px);
    --padding-bottom: calc(var(--mobile-nav-height) + var(--mobile-nav-bottom) + var(--mobile-nav-offset));
}

.app-page--bare {
    --padding-bottom: env(safe-area-inset-bottom, 0px);
}

.app-page__statusbar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 20;
    height: env(safe-area-inset-top, 0px);
    background: var(--bar-glass-bg);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    pointer-events: none;
}

.app-page__inner {
    max-width: var(--content-max-width);
    margin: 0 auto;
}

.app-page__inner--list {
    max-width: var(--list-content-width);
}

.app-page__inner--narrow {
    max-width: var(--narrow-content-width);
}

@media (min-width: 64em) {
    .app-page:not(.app-page--bare) {
        --padding-bottom: var(--space-14);
    }

    .app-page__inner {
        padding: 0 var(--space-4);
    }
}
</style>
