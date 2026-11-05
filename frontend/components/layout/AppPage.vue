<script setup>
/**
 * Rahmen jeder Ansicht: Ionic-Seite mit scrollendem Inhalt, oben der Kopf
 * (AppHeader: Titel, Zurück, Benutzermenü), unten Platz für die schwebende
 * Navigation (Mobil), den Home-Indikator und die offene Bildschirmtastatur.
 * Hinter der Statusleiste liegt ein Glasstreifen: In der installierten App
 * (black-translucent) liefe der Inhalt sonst sichtbar unter Uhrzeit und Akku
 * durch. Im Browser ist der Sicherheitsabstand 0, der Streifen also auch.
 *
 * `backTo`: Ziel des Zurück-Knopfs ohne Verlauf (Standard `/`).
 * `bare`: ohne Kopf und Navigation (Anmeldeseite), kein Platz unten.
 * `width`: Breite des Inhalts – wide (Standard) · list (Einkaufslisten) ·
 * narrow (Formulare, Profil).
 * `userMenu`: Benutzerbild im Kopf (Handy); aus in der Einkaufsliste.
 * `stickyHead`: Der Kopf samt Slot `head` (z. B. Suchfeld) klebt oben, auch
 * beim Scrollen und bei offener Tastatur.
 *
 * Slot `dock`: Hauptaktion, die immer sichtbar über der Navigation klebt
 * (`slot="fixed"` statt neben `IonContent`: Ionics Seitenwechsel bewegt nur
 * den Inhalt). Der Inhalt bekommt unten entsprechend mehr Platz.
 *
 * Exponiert `scrollToTop()`.
 */
import { IonContent, IonPage } from '@ionic/vue';
import { useTemplateRef } from 'vue';
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
    userMenu: {
        type: Boolean,
        default: true
    },
    stickyHead: {
        type: Boolean,
        default: false
    },
    width: {
        type: String,
        default: 'wide',
        validator: (value) => ['wide', 'list', 'narrow'].includes(value)
    }
});

const content = useTemplateRef('content');

function scrollToTop() {
    return content.value?.$el.scrollToTop(0);
}

defineExpose({ scrollToTop });
</script>

<template>
    <IonPage>
        <div class="app-page__statusbar" aria-hidden="true"></div>
        <IonContent
            ref="content"
            class="app-page"
            :class="{
                'app-page--bare': bare,
                'app-page--sticky-head': stickyHead,
                'app-page--docked': $slots.dock
            }"
        >
            <div class="app-page__inner" :class="'app-page__inner--' + width">
                <div v-if="!bare" :class="{ 'app-page__head': stickyHead }">
                    <AppHeader :title="title" :subtitle="subtitle" :back="back" :back-to="backTo" :user-menu="userMenu">
                        <template v-if="$slots.actions" #actions>
                            <slot name="actions" />
                        </template>
                    </AppHeader>
                    <slot name="head" />
                </div>
                <slot />
            </div>
            <div v-if="$slots.dock" slot="fixed" class="app-page__dock">
                <div class="app-page__inner" :class="'app-page__inner--' + width">
                    <div class="app-page__dock-body">
                        <slot name="dock" />
                    </div>
                </div>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.app-page {
    --background: var(--color-bg);
    --color: var(--color-text-1);
    --padding-top: env(safe-area-inset-top, 0px);
    --_keyboard-space: calc(var(--keyboard-height) + var(--space-4));
    --_dock-space: 0px;
    --padding-bottom: max(
        calc(var(--mobile-nav-height) + var(--mobile-nav-bottom) + var(--mobile-nav-offset) + var(--_dock-space)),
        var(--_keyboard-space)
    );
}

.app-page--docked {
    --_dock-space: calc(var(--button-height) + var(--space-2));
}

/* der Sicherheitsabstand gehört dann zum klebenden Kopf, sonst liefe Inhalt darüber */
.app-page--sticky-head {
    --padding-top: 0px;
}

.app-page--bare {
    --padding-bottom: max(env(safe-area-inset-bottom, 0px), var(--_keyboard-space));
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

.app-page__head {
    position: sticky;
    top: 0;
    z-index: 3;
    padding-top: env(safe-area-inset-top, 0px);
    border-bottom: 1px solid var(--color-border-subtle);
    background: var(--color-bg);
}

.app-page__dock {
    position: absolute;
    left: 0;
    right: 0;
    bottom: calc(var(--mobile-nav-bottom) + var(--mobile-nav-height) + var(--space-2));
    z-index: 3;
    pointer-events: none;
}

/* wie die Navigation: hinter der Tastatur nutzlos */
:root[data-keyboard='open'] .app-page__dock {
    visibility: hidden;
}

.app-page__dock-body {
    padding: 0 var(--page-gutter);
}

.app-page__dock-body :slotted(*) {
    box-shadow: var(--shadow-float);
    pointer-events: auto;
}

@media (min-width: 64em) {
    .app-page:not(.app-page--bare) {
        --padding-bottom: max(calc(var(--space-14) + var(--_dock-space)), var(--_keyboard-space));
    }

    .app-page__dock {
        bottom: var(--space-4);
    }

    .app-page__inner {
        padding: 0 var(--space-4);
    }
}
</style>
